<?php
// app/Models/Subscription.php

class Subscription {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function add($userId, $feedId, $categoryId = null) {
        // INSERT IGNORE prevents a duplicate-key error; rowCount() tells us whether a row was actually inserted
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO Subscriptions (user_id, feed_id, category_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $feedId, $categoryId]);
        return $stmt->rowCount() > 0;
    }

    public function remove($userId, $feedId) {
        $stmt = $this->db->prepare('DELETE FROM Subscriptions WHERE user_id = ? AND feed_id = ?');
        return $stmt->execute([$userId, $feedId]);
    }

    public function toggleNotification($userId, $feedId, $notifyStatus) {
        // Same fix as Article::toggleState — real prepared statements send PHP
        // booleans as '' rather than 0/1, which strict SQL mode rejects here.
        $notifyStatus = (int)$notifyStatus;

        $stmt = $this->db->prepare(
            'UPDATE Subscriptions SET notify_email = ? WHERE user_id = ? AND feed_id = ?'
        );
        return $stmt->execute([$notifyStatus, $userId, $feedId]);
    }

    public function getUserSources($userId) {
        $stmt = $this->db->prepare('
            SELECT f.id, f.title, f.website_url, f.favicon_url, f.health_status, s.notify_email, c.name as category_name 
            FROM Subscriptions s
            JOIN Feeds f ON s.feed_id = f.id
            LEFT JOIN Categories c ON s.category_id = c.id
            WHERE s.user_id = ?
            ORDER BY c.name ASC, f.title ASC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}