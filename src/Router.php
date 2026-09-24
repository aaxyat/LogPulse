<?php
declare(strict_types=1);

namespace LogPulse;

class Router
{
    private array $routes = [];
    private array $corsConfig = [];

    public function __construct(array $corsConfig = [])
    {
        $this->corsConfig = $corsConfig;
    }

    public function get(string $pattern, callable $handler, array $middlewares = []): self
    {
        return $this->add('GET', $pattern, $handler, $middlewares);
    }

    public function post(string $pattern, callable $handler, array $middlewares = []): self
    {
        return $this->add('POST', $pattern, $handler, $middlewares);
    }

    public function put(string $pattern, callable $handler, array $middlewares = []): self
    {
        return $this->add('PUT', $pattern, $handler, $middlewares);
    }

    public function delete(string $pattern, callable $handler, array $middlewares = []): self
    {
        return $this->add('DELETE', $pattern, $handler, $middlewares);
    }

    private function add(string $method, string $pattern, callable $handler, array $middlewares): self
    {
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
        return $this;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Auto-handle CORS
        $this->handleCors();
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Match route
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Run middlewares
                $context = [];
                foreach ($route['middlewares'] as $mw) {
                    $res = $mw($context);
                    if ($res !== null) {
                        $context = array_merge($context, is_array($res) ? $res : ['user' => $res]);
                    }
                }

                // Call handler
                call_user_func($route['handler'], $params, $context);
                return;
            }
        }

        // 404 Not Found
        if (str_starts_with($uri, '/api/')) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => "API route not found: {$method} {$uri}"
            ]);
            return;
        }

        // If web request, route appropriately
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        if ($uri === '/' || $uri === '') {
            require dirname(__DIR__) . '/public/assets/landing.html';
        } elseif ($uri === '/docs') {
            require dirname(__DIR__) . '/public/assets/docs.html';
        } else {
            require dirname(__DIR__) . '/public/assets/dashboard.html';
        }
    }

    private function handleCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        $allowedOrigins = $this->corsConfig['allowed_origins'] ?? ['*'];

        if (in_array('*', $allowedOrigins, true) || in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With");
    }
}
