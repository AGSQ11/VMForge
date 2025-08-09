<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\Security;

class ISOController {
    private array $allowed = ['iso','img','raw'];

    public function index() {
        Auth::require();
        $csrf = Security::csrfToken();
        ob_start(); ?>
<div class="card">
  <h2>ISO Library</h2>
  <form method="post" enctype="multipart/form-data" action="/admin/isos">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <input type="file" name="iso" required>
    <input type="text" name="name" placeholder="Name (optional)">
    <button type="submit">Upload</button>
  </form>
</div>
<?php
        $html = ob_get_clean();
        View::render('ISOs', $html);
    }

    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        if (!isset($_FILES['iso']) || $_FILES['iso']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400); echo 'upload failed'; return;
        }
        $orig = $_FILES['iso']['name'] ?? 'file.iso';
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowed, true)) { http_response_code(400); echo 'unsupported extension'; return; }
        // Sanitize filename
        $safeBase = preg_replace('~[^a-zA-Z0-9._-]+~', '_', pathinfo($orig, PATHINFO_FILENAME));
        $fname = $safeBase . '.' . $ext;
        $targetDir = __DIR__ . '/../../isos'; // outside public
        @mkdir($targetDir, 0755, true);
        $target = $targetDir . '/' . $fname;
        if (!move_uploaded_file($_FILES['iso']['tmp_name'], $target)) {
            http_response_code(500); echo 'cannot store iso'; return;
        }
        echo 'ok';
    }
}
