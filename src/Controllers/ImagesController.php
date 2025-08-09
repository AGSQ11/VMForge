<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Models\Image;

class ImagesController {
    public function index() {
        Auth::require();
        $images = Image::all();
        $rows = '';
        foreach ($images as $img) {
            $rows .= '<tr><td>'.(int)$img['id'].'</td><td>'.htmlspecialchars($img['name']).'</td><td><span class="badge">'.htmlspecialchars($img['type']).'</span></td><td>'.htmlspecialchars($img['source_url'] ?? '').'</td><td>'.htmlspecialchars($img['sha256'] ?? '').'</td></tr>';
        }
        $html = '<div class="card"><h2>Images</h2>
        <table class="table"><thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Source URL</th><th>SHA256</th></tr></thead><tbody>'.$rows.'</tbody></table>
        </div>
        <div class="card"><h3>Add Image</h3>
        <form method="post" action="/admin/images">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(\VMForge\Core\Security::csrfToken()); ?>">
            <input name="name" placeholder="Debian 12 Cloud" required>
            <select name="type"><option value="kvm">kvm</option><option value="lxc">lxc</option></select>
            <input name="source_url" placeholder="https://...qcow2 or https://...tar.xz">
            <input name="sha256" placeholder="optional sha256">
            <button type="submit">Create</button>
        </form></div>';
        View::render('Images', $html);
    }
    public function store() {
        Auth::require();
        \VMForge\Core\Security::requireCsrf($_POST['csrf'] ?? null);
        Image::create([
            'name' => $_POST['name'] ?? 'image',
            'type' => $_POST['type'] ?? 'kvm',
            'source_url' => $_POST['source_url'] ?? '',
            'sha256' => $_POST['sha256'] ?? null,
        ]);
        header('Location: /admin/images');
    }
}
