<?php
// app/Core/Security.php

class Security {
    
    public static function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken($token) {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit();
        }
        return true;
    }

    /**
     * Simple sliding-window rate limiter backed by the session.
     * Returns true if the action is allowed, false if the caller is over the limit.
     */
    public static function checkRateLimit($key, $maxAttempts = 5, $windowSeconds = 300) {
        $now = time();
        if (!isset($_SESSION['rate_limits'][$key])) {
            $_SESSION['rate_limits'][$key] = [];
        }
        $_SESSION['rate_limits'][$key] = array_values(array_filter(
            $_SESSION['rate_limits'][$key],
            fn($ts) => ($now - $ts) < $windowSeconds
        ));

        if (count($_SESSION['rate_limits'][$key]) >= $maxAttempts) {
            return false;
        }

        $_SESSION['rate_limits'][$key][] = $now;
        return true;
    }

    public static function sanitize($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::sanitize($value);
            }
        } else {
            $data = htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
        }
        return $data;
    }

    // Allows registered users AND active guests (guests can browse/add sources, not bookmark)
    public static function requireAuth() {
        if (!isset($_SESSION['user_id']) && empty($_SESSION['is_guest'])) {
            header('HTTP/1.1 401 Unauthorized');
            echo json_encode(['error' => 'Authentication required']);
            exit();
        }
    }

    // Registered-only actions: bookmarks, notifications, preferences, categories, tags
    public static function requireRegistered() {
        if (empty($_SESSION['user_id'])) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'This feature requires a registered account']);
            exit();
        }
    }
}