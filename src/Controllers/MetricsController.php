<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\DB;
use VMForge\Core\View;
use PDO;

class MetricsController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $v = $pdo->query('SELECT vm_uuid, AVG(rx_bytes) rx_avg, AVG(tx_bytes) tx_avg, MAX(collected_at) last FROM metrics_vm GROUP BY vm_uuid ORDER BY last DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
        $rows = '';
        foreach ($v as $r) {
            $rows .= '<tr><td>'.htmlspecialchars($r['vm_uuid']).'</td><td>'.htmlspecialchars((string)$r['rx_avg']).'</td><td>'.htmlspecialchars((string)$r['tx_avg']).'</td><td>'.htmlspecialchars($r['last']).'</td></tr>';
        }
        $html = '<div class="card"><h2>Metrics (recent)</h2><table class="table"><thead><tr><th>VM UUID</th><th>RX avg</th><th>TX avg</th><th>Last</th></tr></thead><tbody>'.$rows.'</tbody></table>
        <p>Run <code>php scripts/sampler.php</code> on each node (cron) to populate.</p></div>';
        View::render('Metrics', $html);
    }
}
