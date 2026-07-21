<?php
// app/Models/User.php

class User {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function findById($id) {
        $stmt = $this->db->prepare('SELECT id, email, role, status, created_at FROM Users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function findByEmail($email) {
        $stmt = $this->db->prepare('SELECT * FROM Users WHERE email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch();
    }

    public function updatePassword($id, $newHash) {
        $stmt = $this->db->prepare('UPDATE Users SET password_hash = ? WHERE id = ?');
        return $stmt->execute([$newHash, $id]);
    }

    public function createGuestSession($sessionId) {
        // Expiration set to 24 hours
        $expires = date('Y-m-d H:i:s', strtotime('+1 day'));
        $stmt = $this->db->prepare('INSERT INTO GuestSessions (session_id, expires_at) VALUES (?, ?)');
        return $stmt->execute([$sessionId, $expires]);
    }

    public function deleteAccount($id) {
        // Cascade delete will handle related records based on our schema
        $stmt = $this->db->prepare('DELETE FROM Users WHERE id = ?');
        return $stmt->execute([$id]);
    }
}