# Pack 26 — Metrics & Alerts (Prometheus exporter + heartbeat + sweep)

**Drop-ins:**

- `public/metrics.php` — Prometheus text-format endpoint (no router changes).
- `scripts/metrics/alerts_sweep.php` — checks stale nodes & queue backlog; inserts alerts.
- `migrations/0022_metrics_alerts.sql` — adds `nodes.last_seen_at`, creates `metrics_current` and `alerts` (idempotent).
- `src/Controllers/AgentController.php` — updates `last_seen_at` on `/agent/poll` (heartbeat).
- `deploy/vmforge-metrics.service` + `deploy/vmforge-metrics.timer` — systemd timer to run the sweep every minute.

## Apply
```bash
unzip -o vmforge_feature_pack_26_metrics_and_alerts.zip -d .
git checkout -b fix/pack26-metrics
git add public/metrics.php scripts/metrics/alerts_sweep.php migrations/0022_metrics_alerts.sql \
        src/Controllers/AgentController.php deploy/vmforge-metrics.service deploy/vmforge-metrics.timer docs/PACK26.md
git commit -m "pack26: prometheus /metrics, node heartbeat, alerts sweep, timer"
git push -u origin fix/pack26-metrics

# DB migrate
mysql -u root -p vmforge < migrations/0022_metrics_alerts.sql

# Enable timer (master)
sudo install -o root -g root -m 0644 deploy/vmforge-metrics.service /etc/systemd/system/vmforge-metrics.service
sudo install -o root -g root -m 0644 deploy/vmforge-metrics.timer /etc/systemd/system/vmforge-metrics.timer
sudo systemctl daemon-reload
sudo systemctl enable --now vmforge-metrics.timer
```

## Prometheus scrape
Point Prometheus at `http://MASTER:8080/metrics`. You’ll get gauges for users, nodes, vms, jobs, alerts, plus `vmforge_node_up{node="..."}` per node.

## Thresholds
- `ALERT_NODE_STALE_SEC` (default 180)
- `ALERT_JOBS_PENDING_MAX` (default 100)
Set in `.env` and they’ll be picked up by the sweep.
