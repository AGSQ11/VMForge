# Pack 29 (redo) — Bandwidth accounting + per‑VM egress caps — FULL files

This pack contains **full, drop‑in files** (no patches/partials).

Included:
- `agent/agent.php` — complete agent with new jobs:
  - `BANDWIDTH_COLLECT` — reads per‑VM counters from `/sys/class/net/<tap>/statistics/*`.
  - `NET_EGRESS_CAP_SET {name, mbps}` — applies `tc tbf` egress cap on VM tap.
  - `NET_EGRESS_CAP_CLEAR {name}` — removes the qdisc.
  - Existing jobs remain (some stubs left intentionally; no shell interpolation anywhere).
- `migrations/0025_bandwidth_accounting.sql` — tables: `bandwidth_counters`, `bandwidth_usage`, `egress_caps`.
- `scripts/metrics/collect_bandwidth.php` — enqueues collect jobs across nodes and ingests deltas.
- `src/Controllers/BandwidthController.php` — minimal UI at `/admin/bandwidth`.
- `deploy/vmforge-bandwidth.service` + `deploy/vmforge-bandwidth.timer` — systemd timer to run collection every 5 minutes.

Apply:
```bash
unzip -o vmforge_feature_pack_29b_bandwidth_full_agent.zip -d .
git checkout -b fix/pack29b-bandwidth
git add agent/agent.php migrations/0025_bandwidth_accounting.sql scripts/metrics/collect_bandwidth.php         src/Controllers/BandwidthController.php deploy/vmforge-bandwidth.service deploy/vmforge-bandwidth.timer docs/PACK29.md
git commit -m "pack29b: bandwidth accounting + egress caps (full agent replacement)"
git push -u origin fix/pack29b-bandwidth

# DB
mysql -u root -p vmforge < migrations/0025_bandwidth_accounting.sql

# Timer on master
sudo systemctl daemon-reload
sudo systemctl enable --now vmforge-bandwidth.timer
```

Notes:
- Agent uses only `Shell::runf($bin, $argv)` to avoid shell injection. This passes your `scripts/audit/scan.php --strict` checks.
- Egress shaping uses `tc tbf` on the VM tap (found via `virsh domiflist`). Ingress not included.
- Accounting is delta‑based with a durable last‑counter table.
