<?php
namespace VMForge\Core\Middleware;

use VMForge\Core\Security;

class CsrfMiddleware {
    // Prefixes exempt from CSRF (agent/api/webhooks)
    private static array $exempt = ['/api/', '/agent/', '/webhook/'];

    public static function validate(): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET','HEAD','OPTIONS'], true)) {
            return;
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach (self::$exempt as $prefix) {
            if (strpos($path, $prefix) === 0) return;
        }
        $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        Security::requireCsrf($token);
    }
}
