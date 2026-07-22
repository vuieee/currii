<?php
// app/Controllers/FeedController.php

class FeedController {
    private $feedModel;
    private $subscriptionModel;
    private $articleModel;
    private $rssParser;
    private $isGuest;

    public function __construct() {
        // Registered users and active guests may both read/add feeds
        Security::requireAuth();

        $this->feedModel = new Feed();
        $this->subscriptionModel = new Subscription();
        $this->articleModel = new Article();
        $this->rssParser = new RssParser();
        $this->isGuest = !empty($_SESSION['is_guest']);
    }

    public function addSource($input) {
        $url = filter_var(trim($input['url'] ?? ''), FILTER_VALIDATE_URL);
        if (!$url || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'])) {
            return ['error' => 'Invalid URL provided'];
        }

        // 1. Check if feed already exists in the global (shared) feed cache
        $feed = $this->feedModel->findByUrl($url);
        $feedId = $feed['id'] ?? null;

        // 2. New feed: parse it first to validate and pull metadata before caching it
        if (!$feed) {
            $parsedData = $this->rssParser->fetchAndParse($url);

            if ($parsedData === false || $parsedData === null) {
                return ['error' => 'Could not detect a valid RSS or Atom feed at this URL'];
            }

            $meta = $this->rssParser->getLastFeedMeta();
            $parsedUrl = parse_url($url);
            $websiteUrl = $meta['website_url'] ?? ($parsedUrl['scheme'] . '://' . $parsedUrl['host']);
            $faviconUrl = $meta['favicon_url'] ?? (rtrim($websiteUrl, '/') . '/favicon.ico');
            $title = $meta['title'] ?: $parsedUrl['host'];

            $feedId = $this->feedModel->create($title, $url, $websiteUrl, $faviconUrl);

            $this->articleModel->saveBulk($parsedData['articles'], $feedId);
            $this->feedModel->updateHealthStatus($feedId, 'Online');
        }

        // 3. Guests get a temporary, session-only source list; registered users get a persisted subscription
        if ($this->isGuest) {
            $_SESSION['guest_feed_ids'] = $_SESSION['guest_feed_ids'] ?? [];
            if (in_array($feedId, $_SESSION['guest_feed_ids'])) {
                return ['error' => 'You are already following this source'];
            }
            $_SESSION['guest_feed_ids'][] = $feedId;
            return ['success' => true, 'message' => 'Source added for this session'];
        }

        $userId = $_SESSION['user_id'];
        $categoryId = $input['category_id'] ?? null;
        $success = $this->subscriptionModel->add($userId, $feedId, $categoryId);

        if ($success) {
            return ['success' => true, 'message' => 'Source added successfully'];
        }
        return ['error' => 'You are already subscribed to this source'];
    }

    public function removeSource($input) {
        $feedId = (int)($input['feed_id'] ?? 0);
        if (!$feedId) return ['error' => 'Invalid feed'];

        if ($this->isGuest) {
            $_SESSION['guest_feed_ids'] = array_values(array_diff($_SESSION['guest_feed_ids'] ?? [], [$feedId]));
            return ['success' => true];
        }

        $this->subscriptionModel->remove($_SESSION['user_id'], $feedId);
        return ['success' => true];
    }

    public function refreshSource($input) {
        $feedId = (int)($input['feed_id'] ?? 0);
        $feed = $this->feedModel->findById($feedId);
        if (!$feed) return ['error' => 'Feed not found'];

        $parsedData = $this->rssParser->fetchAndParse($feed['url']);
        if ($parsedData === false || $parsedData === null) {
            $this->feedModel->updateHealthStatus($feedId, 'Offline');
            return ['error' => 'Feed could not be reached'];
        }

        $inserted = $this->articleModel->saveBulk($parsedData['articles'], $feedId);
        $this->feedModel->updateHealthStatus($feedId, 'Online');
        return ['success' => true, 'articles_added' => $inserted];
    }

    public function toggleNotification($input) {
        Security::requireRegistered();
        $feedId = (int)($input['feed_id'] ?? 0);
        $notify = (bool)($input['notify'] ?? true);
        if (!$feedId) return ['error' => 'Invalid feed'];

        $this->subscriptionModel->toggleNotification($_SESSION['user_id'], $feedId, $notify);
        return ['success' => true];
    }

    public function getUserArticles($input) {
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        if ($this->isGuest) {
            $articles = $this->articleModel->getForFeedIds($_SESSION['guest_feed_ids'] ?? [], $limit, $offset);
            return ['success' => true, 'data' => $articles];
        }

        $articles = $this->articleModel->getFeedForUser($_SESSION['user_id'], $limit, $offset);
        return ['success' => true, 'data' => $articles];
    }

    public function getBookmarks($input) {
        Security::requireRegistered();
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $articles = $this->articleModel->getBookmarksForUser($_SESSION['user_id'], $limit, $offset);
        return ['success' => true, 'data' => $articles];
    }

    public function search($input) {
        $term = trim($input['q'] ?? '');
        if ($term === '') return ['success' => true, 'data' => []];

        if ($this->isGuest) {
            // Guests search only within their session's feed ids, in-memory over the cached articles
            $all = $this->articleModel->getForFeedIds($_SESSION['guest_feed_ids'] ?? [], 500, 0);
            $needle = mb_strtolower($term);
            $filtered = array_values(array_filter($all, function ($a) use ($needle) {
                return str_contains(mb_strtolower($a['title']), $needle)
                    || str_contains(mb_strtolower($a['source_title']), $needle)
                    || str_contains(mb_strtolower((string)($a['author'] ?? '')), $needle);
            }));
            return ['success' => true, 'data' => array_slice($filtered, 0, 50)];
        }

        $articles = $this->articleModel->searchForUser($_SESSION['user_id'], $term, 50);
        return ['success' => true, 'data' => $articles];
    }

    public function markAllRead($input) {
        Security::requireRegistered();
        $this->articleModel->markAllReadForUser($_SESSION['user_id']);
        return ['success' => true];
    }

    // On-demand fetch of the original article page when the feed only gave us
    // an excerpt (or nothing). Result is cached onto the Articles row so we
    // don't re-fetch on every subsequent open of the same article.
    public function fetchFullContent($input) {
        $articleId = (int)($input['article_id'] ?? 0);
        if (!$articleId) return ['error' => 'Invalid article'];

        $article = $this->articleModel->getArticleContent($articleId);
        if (!$article) return ['error' => 'Article not found'];

        // Already have substantial content cached — no need to re-fetch or re-extract
        if (!empty($article['content']) && strlen(trim(strip_tags($article['content']))) > 400) {
            return ['success' => true, 'content' => $article['content'], 'source' => 'cached'];
        }

        $fetcher = new ContentFetcher();
        $extracted = $fetcher->fetchReadable($article['url']);

        if ($extracted === null) {
            return ['error' => 'Could not retrieve the original article content'];
        }

        $this->articleModel->updateContent($articleId, $extracted);
        return ['success' => true, 'content' => $extracted, 'source' => 'fetched'];
    }

    public function getSources($input) {
        if ($this->isGuest) {
            $feeds = $this->feedModel->findByIds($_SESSION['guest_feed_ids'] ?? []);
            $sources = array_map(fn($f) => $f + ['category_name' => null, 'notify_email' => false], $feeds);
            return ['success' => true, 'data' => $sources];
        }

        $sources = $this->subscriptionModel->getUserSources($_SESSION['user_id']);
        return ['success' => true, 'data' => $sources];
    }

    public function toggleArticleState($input) {
        $articleId = (int)($input['article_id'] ?? 0);
        $field = $input['field'] ?? ''; // 'is_read' or 'is_bookmarked'
        $state = (bool)($input['state'] ?? true);

        if (!$articleId || !in_array($field, ['is_read', 'is_bookmarked'])) {
            return ['error' => 'Invalid parameters'];
        }

        if ($field === 'is_bookmarked') {
            Security::requireRegistered();
        }

        // Guests have nothing persisted server-side to toggle (no bookmarks, ephemeral read state)
        if ($this->isGuest) {
            return ['success' => true];
        }

        $this->articleModel->toggleState($_SESSION['user_id'], $articleId, $field, $state);
        return ['success' => true];
    }
}