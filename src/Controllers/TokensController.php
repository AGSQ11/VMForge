<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Core\Security;
use PDO;

class TokensController {
    public function index() {
        Auth::require();
        $user = Auth::user();
        $st = DB::pdo()->prepare('SELECT id, name, scopes, created_at, last_used_at FROM api_tokens WHERE user_id=? ORDER BY id DESC');
        $st->execute([(int)$user['id']]);
        $tokens = $st->fetchAll(PDO::FETCH_ASSOC);
        $rows='';
        foreach ($tokens as $t) {
            $rows .= '<tr><td>'.(int)$t['id'].'</td><td>'.htmlspecialchars($t['name']).'</td><td>'.htmlspecialchars($t['scopes']).'</td><td>'.htmlspecialchars($t['created_at']).'</td><td>'.htmlspecialchars($t['last_used_at'] ?? '').'</td></tr>';
        }
        $newToken = $_GET['tok'] ?? null;
        $newHtml = $newToken ? '<div class="card"><strong>Copy this token now, it will not be shown again:</strong><br><code>'.htmlspecialchars($newToken).'</code></div>' : '';
        $html = $newHtml.'<div class="card"><h2>API Tokens</h2>
        <table class="table"><thead><tr><th>ID</th><th>Name</th><th>Scopes</th><th>Created</th><th>Last Used</th></tr></thead><tbody>'.$rows.'</tbody></table>
        </div>
        <div class="card"><h3>Create New Token</h3>
        <form method="post" action="/admin/api-tokens">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(\VMForge\Core\Security::csrfToken()); ?>">
            <input name="name" placeholder="token name" required>
            <input name="scopes" placeholder="api:*" value="api:*">
            <button type="submit">Create</button>
        </form></div>';
        View::render('API Tokens', $html);
    }
    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $user = Auth::user();
        $token = bin2hex(random_bytes(24));
        $hash = Security::hashToken($token);
        $st = DB::pdo()->prepare('INSERT INTO api_tokens(user_id, token_hash, name, scopes) VALUES (?,?,?,?)');
        $st->execute([(int)$user['id'], $hash, ($_POST['name'] ?? 'token'), ($_POST['scopes'] ?? 'api:*')]);
        header('Location: /admin/api-tokens?tok='.$token);
    }
}
