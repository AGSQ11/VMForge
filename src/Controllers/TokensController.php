<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\DB;
use VMForge\Core\View;
use VMForge\Core\Security;
use VMForge\Core\Policy;
use PDO;

class TokensController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $toks = $pdo->query('SELECT id, user_id, SUBSTRING(token_hash,1,16) AS token_prefix, created_at, last_used_at, project_id, scope FROM api_tokens ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $rows='';
        foreach ($toks as $t) {
            $rows .= '<tr><td>'.(int)$t['id'].'</td><td>'.(int)$t['user_id'].'</td><td>'.htmlspecialchars($t['token_prefix']).'…</td><td>'.htmlspecialchars((string)$t['project_id']).'</td><td>'.htmlspecialchars($t['scope']).'</td><td>'.htmlspecialchars($t['created_at']).'</td><td>'.htmlspecialchars($t['last_used_at']).'</td></tr>';
        }
        $html = '<div class="card"><h2>API Tokens</h2><table class="table"><thead><tr><th>ID</th><th>User</th><th>Token (prefix)</th><th>Project</th><th>Scope</th><th>Created</th><th>Last Used</th></tr></thead><tbody>'.$rows.'</tbody></table>
        <h3>Create</h3>
        <form method="post" action="/admin/api-tokens">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(\VMForge\Core\Security::csrfToken()); ?>">
            <select name="scope"><option value="project">project</option><option value="admin">admin</option></select>
            <button type="submit">Create Token</button>
        </form>
        <p>The token will be scoped to the current project unless scope=admin and you are an admin.</p>
        </div>';
        View::render('API Tokens', $html);
    }
    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $user = Auth::user();
        $scope = $_POST['scope'] ?? 'project';
        if ($scope === 'admin' && empty($user['is_admin'])) { http_response_code(403); echo 'admin scope requires admin user'; return; }
        $token = bin2hex(random_bytes(32));
        $hash = Security::hashToken($token);
        $pid = Policy::currentProjectId();
        $pdo = DB::pdo();
        $st = $pdo->prepare('INSERT INTO api_tokens(user_id, token_hash, project_id, scope) VALUES (?,?,?,?)');
        $st->execute([(int)$user['id'], $hash, $scope==='project' ? $pid : null, $scope]);
        echo '<div class="card"><h3>New Token (copy now)</h3><pre>'.htmlspecialchars($token).'</pre><p>Scope: '.htmlspecialchars($scope).'; Project: '.htmlspecialchars((string)$pid).'</p></div>';
    }
}
