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

    // --- Admin Module ---

    /**
     * Step 1/Action 1: list of registered users for the admin dashboard.
     * Guests are session-only and never appear here.
     */
    public function getAllUsers() {
        $stmt = $this->db->query(
            'SELECT id, email, role, status, created_at, last_login FROM Users ORDER BY created_at DESC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Step 3/Action 2: full detail view for a single selected user,
     * including counts an admin would want context on before acting.
     */
    public function getAccountDetails($id) {
        $stmt = $this->db->prepare('SELECT id, email, role, status, created_at, last_login FROM Users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) return null;

        $subs = $this->db->prepare('SELECT COUNT(*) AS c FROM Subscriptions WHERE user_id = ?');
        $subs->execute([$id]);
        $user['subscription_count'] = (int)$subs->fetch()['c'];

        $bookmarks = $this->db->prepare('SELECT COUNT(*) AS c FROM UserArticles WHERE user_id = ? AND is_bookmarked = 1');
        $bookmarks->execute([$id]);
        $user['bookmark_count'] = (int)$bookmarks->fetch()['c'];

        return $user;
    }

    /**
     * Step 4/Action 3-4: update the selected user's editable account fields (email, role, status).
     * Returns false if the new email collides with a different existing account.
     */
    public function updateAccount($id, $email, $role, $status) {
        $stmt = $this->db->prepare('SELECT id FROM Users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) return false;

        $stmt = $this->db->prepare('UPDATE Users SET email = ?, role = ?, status = ? WHERE id = ?');
        return $stmt->execute([$email, $role, $status, $id]);
    }

    /**
     * Step 4/Action 3-4: quick status toggle (disable/re-enable) without touching other fields.
     */
    public function setStatus($id, $status) {
        $stmt = $this->db->prepare('UPDATE Users SET status = ? WHERE id = ?');
        return $stmt->execute([$status, $id]);
    }

    public function countAdmins() {
        $stmt = $this->db->query("SELECT COUNT(*) AS c FROM Users WHERE role = 'Admin'");
        return (int)$stmt->fetch()['c'];
    }
}