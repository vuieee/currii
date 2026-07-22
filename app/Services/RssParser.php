<?php
// app/Services/RssParser.php
class RssParser {
    private $lastFeedMeta = [];

    /**
     * Fetches and parses an RSS 2.0 or Atom feed.
     * Returns ['articles' => [...]] on success, or false/null if the URL isn't a usable feed.
     * Feed-level metadata (title, website, favicon) is stashed and readable via getLastFeedMeta().
     */
    public function fetchAndParse($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Currii-Reader/1.0');
        $xmlData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$xmlData) return false;

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlData);
        if ($xml === false) return false;

        $articles = [];

        if (isset($xml->channel)) {
            // RSS 2.0
            $channel = $xml->channel;
            $websiteUrl = (string)$channel->link ?: null;
            $this->lastFeedMeta = [
                'title' => (string)$channel->title,
                'website_url' => $websiteUrl,
                'favicon_url' => isset($channel->image->url) ? (string)$channel->image->url : null,
            ];

            foreach ($channel->item as $item) {
                $link = (string)$item->link;
                $guid = (string)$item->guid ?: $link;
                if ($guid === '') continue; // no stable identifier, skip to avoid unique-key collisions

                $dc = $item->children('http://purl.org/dc/elements/1.1/');
                $content = $item->children('http://purl.org/rss/1.0/modules/content/');

                $articles[] = [
                    'title' => (string)$item->title,
                    'url' => $link,
                    'content' => (string)($content->encoded ?? $item->description),
                    'guid' => $guid,
                    'author' => (string)($item->author ?: $dc->creator ?: ''),
                    'published_at' => $this->parseDate((string)$item->pubDate),
                ];
            }
        } elseif (isset($xml->entry) || (isset($xml->getName) && $xml->getName() === 'feed')) {
            // Atom
            $links = $xml->link ?? null;
            $siteLink = $this->atomAlternateLink($xml);
            $this->lastFeedMeta = [
                'title' => (string)$xml->title,
                'website_url' => $siteLink,
                'favicon_url' => isset($xml->icon) ? (string)$xml->icon : null,
            ];

            foreach ($xml->entry as $entry) {
                $link = $this->atomAlternateLink($entry);
                $guid = (string)$entry->id ?: $link;
                if ($guid === '') continue;

                $author = '';
                if (isset($entry->author->name)) $author = (string)$entry->author->name;

                $articles[] = [
                    'title' => (string)$entry->title,
                    'url' => $link,
                    'content' => (string)($entry->content ?: $entry->summary),
                    'guid' => $guid,
                    'author' => $author,
                    'published_at' => $this->parseDate((string)($entry->updated ?: $entry->published)),
                ];
            }
        } else {
            return false;
        }

        return ['articles' => $articles];
    }

    public function getLastFeedMeta() {
        return $this->lastFeedMeta;
    }

    private function atomAlternateLink($node) {
        if (!isset($node->link)) return '';
        foreach ($node->link as $link) {
            $rel = (string)$link['rel'];
            if ($rel === '' || $rel === 'alternate') {
                return (string)$link['href'];
            }
        }
        return (string)$node->link[0]['href'];
    }

    private function parseDate($raw) {
        $ts = strtotime($raw);
        return date('Y-m-d H:i:s', $ts ?: time());
    }
}