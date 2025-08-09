<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use PDO;

class BandwidthController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();

        $hours = isset($_GET['hours']) ? max(1, (int)$_GET['hours']) : 24;
        $to = time();
        $from = $to - ($hours * 3600);

        $st = $pdo->prepare("SELECT vm_uuid, interface,
                SUM(rx_bytes) AS rx_bytes, SUM(tx_bytes) AS tx_bytes,
                SUM(rx_packets) AS rx_packets, SUM(tx_packets) AS tx_packets
            FROM bandwidth_usage
            WHERE period_start >= FROM_UNIXTIME(?) AND period_end <= FROM_UNIXTIME(?)
            GROUP BY vm_uuid, interface
            ORDER BY (SUM(rx_bytes)+SUM(tx_bytes)) DESC");
        $st->execute([$from, $to]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // map VM names
        $names = [];
        $vmRows = $pdo->query("SELECT uuid, name FROM vm_instances")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($vmRows as $v) { $names[$v['uuid']] = $v['name']; }

        ob_start();
        ?>
<div class="card">
  <h2>Bandwidth (last <?php echo (int)$hours; ?>h)</h2>
  <form method="get" action="/admin/bandwidth">
    <label>Hours:</label> <input type="number" name="hours" value="<?php echo (int)$hours; ?>" min="1" max="720">
    <button type="submit">Apply</button>
    <a href="/admin/bandwidth.csv?hours=<?php echo (int)$hours; ?>">Download CSV</a>
  </form>
  <table class="table">
    <thead>
      <tr><th>VM</th><th>Interface</th><th>RX MiB</th><th>TX MiB</th><th>RX pkts</th><th>TX pkts</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r) { ?>
        <tr>
          <td><?php echo htmlspecialchars($names[$r['vm_uuid']] ?? $r['vm_uuid']); ?></td>
          <td><?php echo htmlspecialchars($r['interface']); ?></td>
          <td><?php echo number_format($r['rx_bytes'] / (1024*1024), 2); ?></td>
          <td><?php echo number_format($r['tx_bytes'] / (1024*1024), 2); ?></td>
          <td><?php echo (int)$r['rx_packets']; ?></td>
          <td><?php echo (int)$r['tx_packets']; ?></td>
        </tr>
      <?php } ?>
    </tbody>
  </table>
</div>
<?php
        $html = ob_get_clean();
        View::render('Bandwidth', $html);
    }

    public function csv() {
        Auth::require();
        $pdo = DB::pdo();
        $hours = isset($_GET['hours']) ? max(1, (int)$_GET['hours']) : 24;
        $to = time();
        $from = $to - ($hours * 3600);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="bandwidth.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['vm_uuid','interface','rx_bytes','tx_bytes','rx_packets','tx_packets','from','to']);
        $st = $pdo->prepare("SELECT vm_uuid, interface,
                SUM(rx_bytes) AS rx_bytes, SUM(tx_bytes) AS tx_bytes,
                SUM(rx_packets) AS rx_packets, SUM(tx_packets) AS tx_packets
            FROM bandwidth_usage
            WHERE period_start >= FROM_UNIXTIME(?) AND period_end <= FROM_UNIXTIME(?)
            GROUP BY vm_uuid, interface
            ORDER BY vm_uuid, interface");
        $st->execute([$from, $to]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$r['vm_uuid'], $r['interface'], $r['rx_bytes'], $r['tx_bytes'], $r['rx_packets'], $r['tx_packets'], date('c',$from), date('c',$to)]);
        }
        fclose($out);
    }
}
