<?php
namespace VMForge\Core;
class View {
    public static function render(string $title, string $contentHtml): void {
        $brand = $_ENV['APP_NAME'] ?? 'VMForge — an ENGINYRING project';
        $proj = isset($_SESSION['project_id']) ? ('Project #'.(int)$_SESSION['project_id']) : 'No project';
        echo '<!doctype html><html><head><meta charset="utf-8"><title>'
            . htmlspecialchars($title) . ' — ' . htmlspecialchars($brand) .
            '</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>';
        echo '<div class="header"><div>' . htmlspecialchars($brand) . ' — <small>'.htmlspecialchars($proj).'</small></div>';
        echo '<div>'
            . '<a href="/admin/nodes">Nodes</a> '
            . '<a href="/admin/vms">VMs</a> '
            . '<a href="/admin/images">Images</a> '
            . '<a href="/admin/isos">ISOs</a> '
            . '<a href="/admin/ip-pools">IP Pools</a> '
            . '<a href="/admin/api-tokens">API Tokens</a> '
            . '<a href="/admin/backups">Backups</a> '
            . '<a href="/admin/network">Network</a> '
            . '<a href="/admin/subnets">Subnets</a> '
            . '<a href="/admin/subnets6">IPv6</a> '
            . '<a href="/admin/bandwidth">Bandwidth</a> '
            . '<a href="/admin/zfs-repos">ZFS Repos</a> '
            . '<a href="/admin/jobs">Jobs</a> '
            . '<a href="/admin/projects">Projects</a> '
            . '<a href="/admin/storage">Storage</a> '
            . '<a href="/admin/metrics">Metrics</a> '
            . '<a href="/settings/2fa">2FA</a> '
            . '<a href="/logout">Logout</a>'
            . '</div></div>';
        echo '<div class="container">';
        echo $contentHtml;
        echo '<div class="footer">VMForge — an ENGINYRING project</div>';
        echo '</div></body></html>';
    }
}
