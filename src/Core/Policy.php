<?php
namespace VMForge\Core;
use PDO;

class Policy {
    public static function isAdmin(?array $user): bool {
        return $user && !empty($user['is_admin']);
    }
    public static function currentProjectId(): ?int {
        return isset($_SESSION['project_id']) ? (int)$_SESSION['project_id'] : null;
    }
    public static function requireProjectSelected(): int {
        $pid = self::currentProjectId();
        if (!$pid) { http_response_code(400); echo 'Select a project first'; exit; }
        return $pid;
    }
}
