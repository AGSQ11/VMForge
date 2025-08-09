<?php
namespace VMForge\Controllers;
use VMForge\Core\DB;

class HealthController {
    public function index() {
        try {
            $pdo = DB::pdo();
            $pdo->query('SELECT 1')->fetchColumn();
            header('Content-Type: text/plain'); echo "ok";
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain'); echo "err: ".$e->getMessage();
        }
    }
}
