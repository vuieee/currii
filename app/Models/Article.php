<?php
// app/Models/Article.php

class Article {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function saveBulk(array $articles, $feedId) {
        // Prepared statements in a loop for clean bulk inserts. 
        // We use INSERT IGNORE based on the unique 'guid' field.
        $stmt = $this->db->prepare('
            INSERT IGNORE INTO Articles (feed_id, guid, title, url, content, author, published_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        
        $insertedCount = 0;
        foreach ($articles as $article) {
            $stmt->execute([
                $feedId, 
                $article['guid'], 
                $article['title'], 
                $article['url'], 
                $article['content'], 
                $article['author'] ?? null, 
                $article['published_at']
            ]);
            if ($stmt->rowCount() > 0) $insertedCount++;
        }
        return $insertedCount;
    }

    public function getFeedForUser($userId, $limit = 50, $offset = 0) {
        $stmt = $this->db->prepare('
            SELECT a.id, a.feed_id, a.title, a.url, a.published_at, a.author, f.title as source_title, f.favicon_url,
                   COALESCE(MAX(ua.is_read), 0) as is_read, COALESCE(MAX(ua.is_bookmarked), 0) as is_bookmarked,
                   GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR \',\') as tags
            FROM Articles a
            JOIN Subscriptions s ON a.feed_id = s.feed_id
            JOIN Feeds f ON a.feed_id = f.id
            LEFT JOIN UserArticles ua ON a.id = ua.article_id AND ua.user_id = ?
            LEFT JOIN Article_Tags at ON at.article_id = a.id
            LEFT JOIN Tags t ON t.id = at.tag_id AND t.user_id = ?
            WHERE s.user_id = ?
            GROUP BY a.id, a.feed_id, a.title, a.url, a.published_at, a.author, f.title, f.favicon_url
            ORDER BY a.published_at DESC
            LIMIT ? OFFSET ?
        ');

        // PDO requires integers for LIMIT/OFFSET to be bound specifically or emulation turned off
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->bindValue(3, $userId, PDO::PARAM_INT);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->bindValue(5, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getBookmarksForUser($userId, $limit = 50, $offset = 0) {
        $stmt = $this->db->prepare('
            SELECT a.id, a.feed_id, a.title, a.url, a.published_at, a.author, f.title as source_title, f.favicon_url,
                   MAX(ua.is_read) as is_read, MAX(ua.is_bookmarked) as is_bookmarked,
                   GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR \',\') as tags
            FROM UserArticles ua
            JOIN Articles a ON a.id = ua.article_id
            JOIN Feeds f ON a.feed_id = f.id
            LEFT JOIN Article_Tags at ON at.article_id = a.id
            LEFT JOIN Tags t ON t.id = at.tag_id AND t.user_id = ?
            WHERE ua.user_id = ? AND ua.is_bookmarked = 1
            GROUP BY a.id, a.feed_id, a.title, a.url, a.published_at, a.author, f.title, f.favicon_url
            ORDER BY a.published_at DESC
            LIMIT ? OFFSET ?
        ');
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Search title, description/content, author, and source name for a subscribed user.
     * Category/tag matching is included once those tables are populated for the user.
     */
    public function searchForUser($userId, $term, $limit = 50) {
        $like = '%' . $term . '%';
        $stmt = $this->db->prepare('
            SELECT a.id, a.feed_id, a.title, a.url, a.published_at, a.author, f.title as source_title, f.favicon_url,
                   COALESCE(MAX(ua.is_read), 0) as is_read, COALESCE(MAX(ua.is_bookmarked), 0) as is_bookmarked,
                   GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR \',\') as tags
            FROM Articles a
            JOIN Subscriptions s ON a.feed_id = s.feed_id
            JOIN Feeds f ON a.feed_id = f.id
            LEFT JOIN UserArticles ua ON a.id = ua.article_id AND ua.user_id = ?
            LEFT JOIN Article_Tags at ON at.article_id = a.id
            LEFT JOIN Tags t ON t.id = at.tag_id AND t.user_id = ?
            WHERE s.user_id = ?
              AND (a.title LIKE ? OR a.content LIKE ? OR a.author LIKE ? OR f.title LIKE ?
                   OR EXISTS (
                       SELECT 1 FROM Article_Tags at2 JOIN Tags t2 ON t2.id = at2.tag_id
                       WHERE at2.article_id = a.id AND t2.user_id = ? AND t2.name LIKE ?
                   ))
            GROUP BY a.id, a.feed_id, a.title, a.url, a.published_at, a.author, f.title, f.favicon_url
            ORDER BY a.published_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->bindValue(3, $userId, PDO::PARAM_INT);
        $stmt->bindValue(4, $like);
        $stmt->bindValue(5, $like);
        $stmt->bindValue(6, $like);
        $stmt->bindValue(7, $like);
        $stmt->bindValue(8, $userId, PDO::PARAM_INT);
        $stmt->bindValue(9, $like);
        $stmt->bindValue(10, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markAllReadForUser($userId) {
        // Insert-or-update read state for every article the user is currently subscribed to
        $stmt = $this->db->prepare('
            INSERT INTO UserArticles (user_id, article_id, is_read)
            SELECT ?, a.id, 1
            FROM Articles a
            JOIN Subscriptions s ON a.feed_id = s.feed_id
            WHERE s.user_id = ?
            ON DUPLICATE KEY UPDATE is_read = 1
        ');
        return $stmt->execute([$userId, $userId]);
    }

    // Guest mode: no Subscriptions/UserArticles rows exist, so read directly by feed id
    public function getForFeedIds(array $feedIds, $limit = 50, $offset = 0) {
        if (empty($feedIds)) return [];
        $placeholders = implode(',', array_fill(0, count($feedIds), '?'));
        $stmt = $this->db->prepare("
            SELECT a.id, a.feed_id, a.title, a.url, a.published_at, a.author, f.title as source_title, f.favicon_url,
                   0 as is_read, 0 as is_bookmarked
            FROM Articles a
            JOIN Feeds f ON a.feed_id = f.id
            WHERE a.feed_id IN ($placeholders)
            ORDER BY a.published_at DESC
            LIMIT ? OFFSET ?
        ");
        $i = 1;
        foreach ($feedIds as $id) { $stmt->bindValue($i++, $id, PDO::PARAM_INT); }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getArticleContent($articleId) {
        $stmt = $this->db->prepare('SELECT id, title, url, content, author, published_at FROM Articles WHERE id = ?');
        $stmt->execute([$articleId]);
        return $stmt->fetch();
    }

    // Persists content fetched from the article's original page so we don't
    // re-fetch it on every subsequent open of the same article.
    public function updateContent($articleId, $content) {
        $stmt = $this->db->prepare('UPDATE Articles SET content = ? WHERE id = ?');
        return $stmt->execute([$content, $articleId]);
    }

    public function toggleState($userId, $articleId, $field, $state) {
        // Safely restrict field names to prevent SQL injection
        $allowedFields = ['is_read', 'is_bookmarked'];
        if (!in_array($field, $allowedFields)) return false;

        // With real prepared statements (PDO::ATTR_EMULATE_PREPARES = false), PDO sends
        // PHP booleans as an empty string rather than 0/1, which MySQL's strict mode
        // rejects for this integer column — cast explicitly instead.
        $state = (int)$state;

        $stmt = $this->db->prepare("
            INSERT INTO UserArticles (user_id, article_id, $field) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE $field = ?
        ");
        return $stmt->execute([$userId, $articleId, $state, $state]);
    }
}