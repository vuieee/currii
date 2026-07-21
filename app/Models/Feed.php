<?php
// app/Models/Feed.php

class Feed {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function findByUrl($url) {
        $stmt = $this->db->prepare('SELECT * FROM Feeds WHERE url = ?');
        $stmt->execute([$url]);
        return $stmt->fetch();
    }

    public function findById($id) {
        $stmt = $this->db->prepare('SELECT * FROM Feeds WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function findByIds(array $ids) {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM Feeds WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    public function create($title, $url, $websiteUrl, $faviconUrl) {
        $stmt = $this->db->prepare(
            'INSERT INTO Feeds (title, url, website_url, favicon_url) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$title, $url, $websiteUrl, $faviconUrl]);
        return $this->db->lastInsertId();
    }

    public function updateHealthStatus($id, $status) {
        $stmt = $this->db->prepare('UPDATE Feeds SET health_status = ?, last_fetched = NOW() WHERE id = ?');
        return $stmt->execute([$status, $id]);
    }

    public function getFeedsNeedingRefresh($intervalMinutes = 30) {
        // Fetches feeds that haven't been updated within the interval
        $stmt = $this->db->prepare(
            'SELECT * FROM Feeds WHERE last_fetched IS NULL OR last_fetched < NOW() - INTERVAL ? MINUTE'
        );
        $stmt->execute([$intervalMinutes]);
        return $stmt->fetchAll();
    }
}