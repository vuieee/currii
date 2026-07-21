<?php
// app/Controllers/AuthController.php
class AuthController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function register($input) {
        $email = trim($input['email'] ?? '');
        $password = (string)($input['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Invalid email format'];
        }
        if (strlen($password) < 8) {
            return ['error' => 'Password must be at least 8 characters'];
        }
        if (!Security::checkRateLimit('register_' . $this->clientKey(), 5, 600)) {
            return ['error' => 'Too many registration attempts. Please try again later.'];
        }

        $stmt = $this->db->prepare('SELECT id FROM Users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) return ['error' => 'Email already exists'];

        // Argon2id when available, bcrypt fallback
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $algo);

        $stmt = $this->db->prepare('INSERT INTO Users (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, $hash]);
        $userId = $this->db->lastInsertId();

        $this->db->prepare('INSERT INTO Preferences (user_id) VALUES (?)')->execute([$userId]);

        return ['success' => true, 'message' => 'Registration successful. You can now log in.'];
    }

    public function login($input) {
        $email = trim($input['email'] ?? '');
        $password = (string)($input['password'] ?? '');

        if (!Security::checkRateLimit('login_' . $this->clientKey(), 8, 300)) {
            return ['error' => 'Too many login attempts. Please wait a few minutes and try again.'];
        }

        $stmt = $this->db->prepare('SELECT id, email, password_hash, role FROM Users WHERE email = ? AND status = "Active"');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Prevent session fixation
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['is_guest'] = false;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $this->db->prepare('UPDATE Users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

            return [
                'success' => true,
                'token' => $_SESSION['csrf_token'],
                'user' => ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']]
            ];
        }
        return ['error' => 'Invalid credentials'];
    }

    public function logout($input) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        return ['success' => true];
    }

    /**
     * Starts a temporary guest session: browsing and adding sources only,
     * no bookmarks/notifications, expires automatically (see GuestSessions table).
     */
    public function guest($input) {
        session_regenerate_id(true);
        $sessionId = bin2hex(random_bytes(24));

        $userModel = new User();
        $userModel->createGuestSession($sessionId);

        $_SESSION['guest_session_id'] = $sessionId;
        $_SESSION['is_guest'] = true;
        $_SESSION['role'] = 'Guest';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        unset($_SESSION['user_id']);

        return ['success' => true, 'token' => $_SESSION['csrf_token'], 'guest' => true];
    }

    public function me($input) {
        if (!empty($_SESSION['is_guest'])) {
            return ['success' => true, 'guest' => true, 'csrf_token' => $_SESSION['csrf_token'] ?? null];
        }
        if (!empty($_SESSION['user_id'])) {
            return [
                'success' => true,
                'guest' => false,
                'user' => ['id' => $_SESSION['user_id'], 'email' => $_SESSION['email'] ?? '', 'role' => $_SESSION['role'] ?? 'User'],
                'csrf_token' => $_SESSION['csrf_token'] ?? null
            ];
        }
        return ['success' => false];
    }

    private function clientKey() {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
