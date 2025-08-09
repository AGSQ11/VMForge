<?php
namespace VMForge\Core;
class Response {
    public static function json($data, int $code=200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
}
