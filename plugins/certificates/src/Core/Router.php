<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

/**
 * High-performance, lightweight HTTP Router with path extraction,
 * softcoded URL generation, and automatic browser CSRF checking.
 */
class Router
{
    protected array $routes = [];
    protected string $basePath = '';

    public function __construct(string $basePath = '')
    {
        $this->basePath = '/' . trim($basePath, '/');
        if ($this->basePath === '/') {
            $this->basePath = '';
        }
    }

    public function get(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $pattern, $handler, $middleware);
    }

    public function addRoute(string $method, string $pattern, callable|array $handler, array $middleware = []): void
    {
        $pattern = '/' . trim($pattern, '/');
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function url(string $path = ''): string
    {
        $path = '/' . ltrim($path, '/');
        return $this->basePath . $path;
    }

    public function dispatch(?string $method = null, ?string $uri = null): mixed
    {
        $method = strtoupper($method ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $uri ?? $_SERVER['REQUEST_URI'] ?? '/';

        // Strip query string
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }

        // Normalize base path removal if running in subfolder
        if (!empty($this->basePath)
            && ($uri === $this->basePath || str_starts_with($uri, $this->basePath . '/'))) {
            $uri = substr($uri, strlen($this->basePath));
        }

        $uri = '/' . trim($uri, '/');

        // Automatic CSRF verification on state-changing browser routes (ignore /api/)
        if (in_array($method, ['POST', 'PUT', 'DELETE'], true) && !str_starts_with($uri, '/api/')) {
            $submittedToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            if (!Session::verifyCsrf($submittedToken)) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo "403 Forbidden: Invalid or missing CSRF token.";
                return null;
            }
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $route['pattern']);
            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $cleanup = [];
                foreach ($route['middleware'] as $mw) {
                    if (is_callable($mw)) {
                        $res = $mw();
                        if ($res === false) {
                            foreach (array_reverse($cleanup) as $callback) {
                                $callback();
                            }
                            return null;
                        }
                        if (is_callable($res)) {
                            $cleanup[] = $res;
                        }
                    }
                }

                try {
                    $handler = $route['handler'];
                    if (is_array($handler)) {
                        [$class, $action] = $handler;
                        $controller = is_object($class) ? $class : new $class();
                        return call_user_func_array([$controller, $action], [$params]);
                    }

                    return call_user_func_array($handler, [$params]);
                } finally {
                    foreach (array_reverse($cleanup) as $callback) {
                        $callback();
                    }
                }
            }
        }

        // Route Not Found
        if (str_starts_with($uri, '/api/')) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'The requested endpoint does not exist.'
                ],
                'request_id' => 'req_' . bin2hex(random_bytes(8))
            ]);
            return null;
        }

        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo "<h1>404 Not Found</h1><p>The requested page could not be located on this server.</p>";
        return null;
    }
}
