<?php
namespace VMForge\Core\Middleware;

use VMForge\Core\Security;

class CsrfMiddleware {
    private static array $exempt = [
        '/api/*',
        '/agent/*',
        '/webhook/*',
        '/healthz',
    ];

    public static function validate(): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET','HEAD','OPTIONS'], true)) return;

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach (self::$exempt as $pattern) {
            if (fnmatch($pattern, $path)) return;
        }

        $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        Security::requireCsrf($token);
    }
}
