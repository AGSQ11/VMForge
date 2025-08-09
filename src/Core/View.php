<?php
namespace VMForge\Core;
class View {
    public static function render(string $title, string $contentHtml): void {
        $brand = $_ENV['APP_NAME'] ?? 'VMForge — an ENGINYRING project';
        echo '<!doctype html><html><head><meta charset="utf-8"><title>'
            . htmlspecialchars($title) . ' — ' . htmlspecialchars($brand) .
            '</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>';
        echo '<div class="header"><div>' . htmlspecialchars($brand) . '</div>';
        echo '<div>'
            . '<a href="/admin/nodes">Nodes</a> '
            . '<a href="/admin/vms">VMs</a> '
            . '<a href="/admin/images">Images</a> '
            . '<a href="/admin/ip-pools">IP Pools</a> '
            . '<a href="/admin/api-tokens">API Tokens</a> '
            . '<a href="/logout">Logout</a>'
            . '</div></div>';
        echo '<div class="container">';
        echo $contentHtml;
        echo '<div class="footer">VMForge — an ENGINYRING project</div>';
        echo '</div></body></html>';
    }
}
