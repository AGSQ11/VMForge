# Pack 29 — Bandwidth accounting + per‑VM egress caps

**Agent**
- New jobs:
  - `BANDWIDTH_COLLECT` — returns per‑VM `{name, if, rx_bytes, tx_bytes}` from `/sys/class/net/<tap>/statistics/*`.
  - `NET_EGRESS_CAP_SET {name, mbps}` — applies `tc tbf` on the VM tap to limit egress to `<mbps>`.
  - `NET_EGRESS_CAP_CLEAR {name}` — removes the qdisc.
- All commands are executed with `Shell::runf(...)` (no string interpolation).

**Master**
- `migrations/0025_bandwidth_accounting.sql` — tables:
  - `bandwidth_counters` (last rx/tx per vm+iface),
  - `bandwidth_usage` (delta rows with period start/end),
  - `egress_caps` (desired cap per VM).
- `scripts/metrics/collect_bandwidth.php` — enqueues `BANDWIDTH_COLLECT` for each node, then ingests finished jobs and writes deltas.
- `src/Controllers/BandwidthController.php` — minimal admin UI to view 24h totals per VM and set/clear egress caps.
- `deploy/vmforge-bandwidth.service` + `.timer` — systemd timer to run collection every 5 minutes.

## Apply
```bash
unzip -o vmforge_feature_pack_29_bandwidth_quota.zip -d .
git checkout -b fix/pack29-bandwidth
git add migrations/0025_bandwidth_accounting.sql scripts/metrics/collect_bandwidth.php         src/Controllers/BandwidthController.php deploy/vmforge-bandwidth.service deploy/vmforge-bandwidth.timer docs/PACK29.md
git commit -m "pack29: bandwidth accounting + egress caps (agent+master)"
git push -u origin fix/pack29-bandwidth

# DB
mysql -u root -p vmforge < migrations/0025_bandwidth_accounting.sql

# Enable collector
sudo systemctl daemon-reload
sudo systemctl enable --now vmforge-bandwidth.timer
```

## Wire agent
Open `agent/agent.php` and:
1) Add these cases inside `executeJob()`:
```
case 'BANDWIDTH_COLLECT':       return net_bw_collect($p, $bridge);
case 'NET_EGRESS_CAP_SET':      return net_egress_cap_set($p, $bridge);
case 'NET_EGRESS_CAP_CLEAR':    return net_egress_cap_clear($p, $bridge);
```
2) Paste the functions from `patches/agent_bandwidth_snippet.php` (or from the README block) near your other agent helpers.

## Notes
- Accounting is **delta‑based** and robust across agent restarts: the `bandwidth_counters` table keeps the last absolute counters read from sysfs; we store only the positive deltas into `bandwidth_usage` with the time window.
- Caps are **egress only** via `tc tbf` on the tap device (typical `vnet*`). Ingress shaping would require IFB; not included here.
- The UI resides at `/admin/bandwidth` and mirrors your existing card/table styling.
- Security: No shell interpolation. The collector uses prepared statements; the agent uses `Shell::runf` with argv arrays.
