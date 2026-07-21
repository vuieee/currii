<?php
// index.php - Front Controller
session_start();

// Simple autoloader for app classes
spl_autoload_register(function ($class) {
    $directories = ['Core', 'Controllers', 'Models', 'Services'];
    foreach ($directories as $dir) {
        $file = __DIR__ . '/../app/' . $dir . '/' . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Basic .env parser
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(sprintf('%s=%s', trim($name), trim($value)));
    }
}

$router = new Router();

// Auth routes (register/login/guest issue their own CSRF token, so they're exempt from the check below)
$router->add('POST', '/api/register', 'AuthController', 'register');
$router->add('POST', '/api/login', 'AuthController', 'login');
$router->add('POST', '/api/logout', 'AuthController', 'logout');
$router->add('POST', '/api/guest', 'AuthController', 'guest');
$router->add('GET', '/api/me', 'AuthController', 'me');

// Feed & article routes
$router->add('POST', '/api/feeds/add', 'FeedController', 'addSource');
$router->add('POST', '/api/feeds/remove', 'FeedController', 'removeSource');
$router->add('POST', '/api/feeds/refresh', 'FeedController', 'refreshSource');
$router->add('POST', '/api/feeds/notify', 'FeedController', 'toggleNotification');
$router->add('GET', '/api/feeds/sources', 'FeedController', 'getSources');
$router->add('POST', '/api/articles/feed', 'FeedController', 'getUserArticles');
$router->add('POST', '/api/articles/bookmarks', 'FeedController', 'getBookmarks');
$router->add('POST', '/api/articles/search', 'FeedController', 'search');
$router->add('POST', '/api/articles/toggle', 'FeedController', 'toggleArticleState');
$router->add('POST', '/api/articles/mark-all-read', 'FeedController', 'markAllRead');
$router->add('POST', '/api/articles/fetch-full', 'FeedController', 'fetchFullContent');

// Sync route — in production this should run via a protected CLI/cron entry point, not a public GET
$router->add('GET', '/api/sync', function () {
    $sync = new FeedSynchronizer();
    header('Content-Type: application/json');
    echo json_encode($sync->syncAll());
}, 'Closure');

$router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
