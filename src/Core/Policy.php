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
    public static function requireMember(array $user, int $projectId): void {
        if (self::isAdmin($user)) return;
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT 1 FROM user_projects WHERE user_id=? AND project_id=? LIMIT 1');
        $st->execute([ (int)$user['id'], $projectId ]);
        if (!$st->fetchColumn()) { http_response_code(403); echo 'not a project member'; exit; }
    }
    public static function ensureOwner(array $user, int $projectId): void {
        if (self::isAdmin($user)) return;
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT role FROM user_projects WHERE user_id=? AND project_id=? LIMIT 1');
        $st->execute([ (int)$user['id'], $projectId ]);
        $role = $st->fetchColumn();
        if ($role !== 'owner') { http_response_code(403); echo 'owner required'; exit; }
    }
}
