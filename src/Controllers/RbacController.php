<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\Policy;
use VMForge\Models\Role;

class RbacController
{
    public function index()
    {
        Auth::require();
        if (!Policy::can('rbac.manage')) {
            http_response_code(403);
            View::render('Forbidden', '<div class="card"><h2>403 Forbidden</h2><p>You do not have permission to manage roles and permissions.</p></div>');
            return;
        }

        $roles = Role::findAll();
        $html = '<div class="card"><h2>Roles & Permissions</h2>';
        $html .= '<table class="table"><thead><tr><th>ID</th><th>Role</th><th>Description</th><th>Action</th></tr></thead><tbody>';
        foreach ($roles as $role) {
            $html .= '<tr>';
            $html .= '<td>' . (int)$role['id'] . '</td>';
            $html .= '<td>' . htmlspecialchars($role['name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($role['description']) . '</td>';
            $html .= '<td><a href="/admin/rbac/role?id=' . (int)$role['id'] . '">Edit</a></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div>';

        View::render('RBAC Management', $html);
    }

    public function editRole()
    {
        Auth::require();
        if (!Policy::can('rbac.manage')) {
            http_response_code(403);
            View::render('Forbidden', '<div class="card"><h2>403 Forbidden</h2><p>You do not have permission to manage roles and permissions.</p></div>');
            return;
        }
        $roleId = (int)($_GET['id'] ?? 0);
        $role = Role::findById($roleId);
        if (!$role) {
            http_response_code(404);
            View::render('Not Found', '<div class="card"><h2>404 Not Found</h2><p>Role not found.</p></div>');
            return;
        }

        $allPermissions = \VMForge\Models\Permission::findAll();
        $rolePermissions = array_column(Role::getPermissions($roleId), 'id');

        $csrf = \VMForge\Core\Security::csrfToken();
        $html = '<div class="card"><h2>Edit Role: ' . htmlspecialchars($role['name']) . '</h2>';
        $html .= '<form method="post" action="/admin/rbac/role">';
        $html .= '<input type="hidden" name="csrf" value="' . $csrf . '">';
        $html .= '<input type="hidden" name="id" value="' . $roleId . '">';
        $html .= '<h3>Permissions</h3>';
        $html .= '<div class="permission-grid">';
        foreach ($allPermissions as $perm) {
            $checked = in_array($perm['id'], $rolePermissions) ? 'checked' : '';
            $html .= '<div class="permission-item">';
            $html .= '<input type="checkbox" name="permissions[]" value="' . $perm['id'] . '" id="perm_' . $perm['id'] . '" ' . $checked . '>';
            $html .= '<label for="perm_' . $perm['id'] . '">' . htmlspecialchars($perm['name']) . '</label>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '<br><button type="submit">Save Changes</button>';
        $html .= '</form></div>';
        $html .= '<style>.permission-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }</style>';

        View::render('Edit Role', $html);
    }

    public function updateRole()
    {
        Auth::require();
        if (!Policy::can('rbac.manage')) {
            http_response_code(403);
            View::render('Forbidden', '<div class="card"><h2>403 Forbidden</h2><p>You do not have permission to manage roles and permissions.</p></div>');
            return;
        }
        \VMForge\Core\Security::requireCsrf($_POST['csrf'] ?? null);
        $roleId = (int)($_POST['id'] ?? 0);
        $permissionIds = $_POST['permissions'] ?? [];

        if (!$roleId) {
            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        Role::updatePermissions($roleId, $permissionIds);
        header('Location: /admin/rbac');
        exit;
    }
}
