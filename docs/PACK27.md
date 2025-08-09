# Pack 27 — ISO Library

What you get:
- `src/Services/ISOStore.php` — ISO registry and retrieval:
  - `registerLocal($file)` moves a local ISO into `ISO_DIR`, computes sha256, inserts DB row, sets `download_url` if `ISO_BASE_URL` is configured.
  - `importUrl($url, $name?, $sha256?)` streams a remote ISO into `ISO_DIR`, verifies hash (optional), inserts DB row.
  - `ensureLocal($isoId)` (used by agent reinstall) ensures the ISO exists on the current host; will download from `download_url` if necessary.
- `migrations/0023_iso_library.sql` — table for the ISO catalog.
- `scripts/isos/import_url.php` — CLI to add ISOs by URL.
- `deploy/nginx-isos-snippet.conf` — serve `/isos/` from `ISO_DIR` on the master.

## Configure

In `.env` on the master and agents:
```
ISO_DIR=/var/lib/vmforge/isos
ISO_BASE_URL=http://MASTER:8080/isos
```
Create the directory and permissions:
```bash
sudo mkdir -p /var/lib/vmforge/isos
sudo chown -R vmforge:vmforge /var/lib/vmforge/isos
```

Wire nginx on the master:
```bash
sudo tee /etc/nginx/snippets/vmforge-isos.conf >/dev/null < deploy/nginx-isos-snippet.conf
# then include the snippet inside your vmforge server {} block and reload nginx
sudo systemctl reload nginx
```
Increase upload size if you plan to upload ISOs via web later:
```
client_max_body_size 4g;
```

## DB
```bash
mysql -u root -p vmforge < migrations/0023_iso_library.sql
```

## Import examples
```bash
php scripts/isos/import_url.php https://releases.ubuntu.com/24.04/ubuntu-24.04-live-server-amd64.iso
php scripts/isos/import_url.php https://cdimage.debian.org/debian-cd/current/amd64/iso-cd/debian-12.5.0-amd64-netinst.iso debian-12-netinst.iso
php scripts/isos/import_url.php https://mirror.../almalinux-9.iso AlmaLinux-9.iso <expected_sha256>
```

## Agent behavior
`agent/agent.php` calls `ISOStore::ensureLocal($isoId)` during reinstall. If the node doesn't have the ISO locally and `download_url` is set, the agent downloads and verifies sha256, then uses the local copy. If you prefer shared storage (NFS/CephFS), point both master and nodes' `ISO_DIR` to the mounted path and leave `ISO_BASE_URL` unset.
