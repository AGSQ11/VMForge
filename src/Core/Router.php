<?php
namespace VMForge\Core;

class Router {
    private array $routes = [];

    public function get(string $path, $handler) { $this->map('GET', $path, $handler); }
    public function post(string $path, $handler) { $this->map('POST', $path, $handler); }

    private function map(string $method, string $path, $handler) {
        $this->routes[] = [$method, $path, $handler];
    }

    public function dispatch() {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        foreach ($this->routes as [$m, $p, $h]) {
            if ($m === $method && $p === $uri) {
                if (is_array($h)) {
                    [$class, $func] = $h;
                    $instance = new $class();
                    return $instance->$func();
                }
                return $h();
            }
        }
        http_response_code(404);
        echo 'Not Found';
    }
}
