<?php
namespace VMForge\Core;

use VMForge\Models\User;

class Policy {
    /**
     * Check if current user has permission
     */
    public static function can(string $permission): bool {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }
        
        // Super admin bypass
        if (!empty($user['is_admin'])) {
            return true;
        }
        
        // Check specific permission
        $permissions = $_SESSION['permissions'] ?? [];
        return in_array($permission, $permissions, true);
    }
    
    /**
     * Check if current user owns a resource
     */
    public static function owns(string $resource, int $resourceId): bool {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }
        
        // Admin can access everything
        if (!empty($user['is_admin'])) {
            return true;
        }
        
        $pdo = DB::pdo();
        
        switch ($resource) {
            case 'vm':
                $stmt = $pdo->prepare('
                    SELECT COUNT(*) FROM vm_instances vi
                    JOIN user_projects up ON vi.project_id = up.project_id
                    WHERE vi.id = ? AND up.user_id = ?
                ');
                $stmt->execute([$resourceId, $user['id']]);
                return $stmt->fetchColumn() > 0;
                
            case 'project':
                $stmt = $pdo->prepare('
                    SELECT COUNT(*) FROM user_projects
                    WHERE project_id = ? AND user_id = ?
                ');
                $stmt->execute([$resourceId, $user['id']]);
                return $stmt->fetchColumn() > 0;
                
            case 'ticket':
                $stmt = $pdo->prepare('
                    SELECT COUNT(*) FROM tickets
                    WHERE id = ? AND user_id = ?
                ');
                $stmt->execute([$resourceId, $user['id']]);
                return $stmt->fetchColumn() > 0;
                
            default:
                return false;
        }
    }
    
    /**
     * Get current project ID from session
     */
    public static function currentProjectId(): ?int {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        return isset($_SESSION['project_id']) ? (int)$_SESSION['project_id'] : null;
    }
    
    /**
     * Set current project ID in session
     */
    public static function setProjectId(int $projectId): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $_SESSION['project_id'] = $projectId;
    }
    
    /**
     * Check if user has access to project
     */
    public static function canAccessProject(int $projectId): bool {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }
        
        if (!empty($user['is_admin'])) {
            return true;
        }
        
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM user_projects
            WHERE project_id = ? AND user_id = ?
        ');
        $stmt->execute([$projectId, $user['id']]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Require project to be selected
     */
    public static function requireProjectSelected(): int {
        $projectId = self::currentProjectId();
        
        if (!$projectId) {
            http_response_code(400);
            View::render('No Project', '<div class="card"><h2>No Project Selected</h2><p>Please select a project first.</p></div>');
            exit;
        }
        
        return $projectId;
    }
    
    /**
     * Check if user is admin
     */
    public static function isAdmin(): bool {
        $user = Auth::user();
        return $user && !empty($user['is_admin']);
    }
    
    /**
     * Require admin access
     */
    public static function requireAdmin(): void {
        if (!self::isAdmin()) {
            http_response_code(403);
            View::render('Forbidden', '<div class="card"><h2>403 Forbidden</h2><p>Admin access required.</p></div>');
            exit;
        }
    }
    
    /**
     * Check quota limits for project
     */
    public static function checkQuota(int $projectId, string $resource, int $amount = 1): bool {
        $pdo = DB::pdo();
        
        // Get quotas
        $stmt = $pdo->prepare('
            SELECT * FROM quotas WHERE project_id = ?
        ');
        $stmt->execute([$projectId]);
        $quota = $stmt->fetch();
        
        if (!$quota) {
            return true; // No quotas set
        }
        
        // Get current usage
        $stmt = $pdo->prepare('
            SELECT 
                COUNT(*) as vm_count,
                COALESCE(SUM(vcpus), 0) as vcpu_total,
                COALESCE(SUM(memory_mb), 0) as ram_total,
                COALESCE(SUM(disk_gb), 0) as disk_total
            FROM vm_instances
            WHERE project_id = ?
        ');
        $stmt->execute([$projectId]);
        $usage = $stmt->fetch();
        
        switch ($resource) {
            case 'vms':
                return !$quota['max_vms'] || ($usage['vm_count'] + $amount) <= $quota['max_vms'];
            case 'vcpus':
                return !$quota['max_vcpus'] || ($usage['vcpu_total'] + $amount) <= $quota['max_vcpus'];
            case 'ram':
                return !$quota['max_ram_mb'] || ($usage['ram_total'] + $amount) <= $quota['max_ram_mb'];
            case 'disk':
                return !$quota['max_disk_gb'] || ($usage['disk_total'] + $amount) <= $quota['max_disk_gb'];
            default:
                return true;
        }
    }
}
