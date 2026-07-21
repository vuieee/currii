<?php
// app/Services/FeedSynchronizer.php

class FeedSynchronizer {
    private $feedModel;
    private $articleModel;
    private $rssParser;

    public function __construct() {
        $this->feedModel = new Feed();
        $this->articleModel = new Article();
        $this->rssParser = new RssParser();
    }

    /**
     * Call this method via a CRON job or a protected API endpoint.
     * @param int $intervalMinutes Only refresh feeds older than this.
     */
    public function syncAll($intervalMinutes = 30) {
        $feeds = $this->feedModel->getFeedsNeedingRefresh($intervalMinutes);
        
        $stats = [
            'feeds_checked' => count($feeds),
            'articles_added' => 0,
            'failed_feeds' => 0
        ];

        foreach ($feeds as $feed) {
            $parsedData = $this->rssParser->fetchAndParse($feed['url']);
            
            if ($parsedData === false || $parsedData === null) {
                // Mark as offline if retrieval fails
                $this->feedModel->updateHealthStatus($feed['id'], 'Offline');
                $stats['failed_feeds']++;
                continue;
            }

            // Save new articles (duplicates are ignored by the model's SQL)
            $inserted = $this->articleModel->saveBulk($parsedData['articles'], $feed['id']);
            $stats['articles_added'] += $inserted;
            
            // Mark feed as healthy and update the last_fetched timestamp
            $this->feedModel->updateHealthStatus($feed['id'], 'Online');
        }

        return $stats;
    }
}