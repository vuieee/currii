<?php
// app/Core/Router.php

class Router {
    private $routes = [];

    // Routes that create/reset a session and therefore issue the CSRF token, rather than requiring one
    private $csrfExempt = ['/api/register', '/api/login', '/api/guest'];

    public function add($method, $path, $controller, $action) {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'controller' => $controller,
            'action' => $action
        ];
    }

    public function dispatch($uri, $method) {
        $parsedUrl = parse_url($uri);
        $path = rtrim($parsedUrl['path'], '/');
        if ($path === '') $path = '/';

        // Serve the frontend application
        if ($path === '/' && $method === 'GET') {
            require_once __DIR__ . '/../../public/index.html';
            return;
        }

        // Serve the admin dashboard shell (client-side JS enforces the admin-only API calls)
        if ($path === '/admin' && $method === 'GET') {
            require_once __DIR__ . '/../../public/admin.html';
            return;
        }

        // Handle API routes
        foreach ($this->routes as $route) {
            if ($route['path'] === $path && $route['method'] === strtoupper($method)) {
                $controllerName = $route['controller'];
                $actionName = $route['action'];

                $needsCsrf = in_array($route['method'], ['POST', 'PUT', 'DELETE']) && !in_array($path, $this->csrfExempt);
                if ($needsCsrf && class_exists('Security')) {
                    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                    Security::verifyCsrfToken($token);
                }

                // Closure-based route (controller itself is callable, e.g. the /api/sync endpoint)
                if ($controllerName instanceof \Closure) {
                    $controllerName();
                    return;
                }

                if (is_string($controllerName) && class_exists($controllerName)) {
                    $controller = new $controllerName();
                    if (method_exists($controller, $actionName)) {
                        // Read JSON input for POST/PUT requests
                        $input = json_decode(file_get_contents('php://input'), true) ?? [];
                        
                        // Execute controller action
                        $response = call_user_func_array([$controller, $actionName], [$input]);
                        
                        header('Content-Type: application/json');
                        echo json_encode($response);
                        return;
                    }
                }
            }
        }

        // 404 Fallback
        header("HTTP/1.0 404 Not Found");
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Route not found']);
    }
}