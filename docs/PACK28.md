# Pack 28 — Backups: S3 offsite + retention policies + CLI + minimal UI

Includes:
- `src/Integrations/S3.php` — minimal SigV4 S3 client (PUT/DELETE) for AWS/MinIO/compatible endpoints.
- `src/Services/Backup.php` — full replacement: create full QCOW2 backup, optional S3 upload, list/delete, retention pruning.
- `migrations/0024_backups_and_policies.sql` — `backups` + `backup_policies` tables.
- `scripts/backups/run_backup.php` — backup a VM by name/uuid.
- `scripts/backups/prune.php` — apply retention for all VMs or a single VM.
- `src/Controllers/BackupsController.php` — minimal admin UI to list/create/delete backups.

## Configure
In `.env` (master & nodes for path consistency):
```
BACKUP_DIR=/var/lib/vmforge/backups

# S3 (optional for offsite copies)
S3_ENDPOINT=play.min.io:9000          # or s3.amazonaws.com, or your MinIO
S3_REGION=us-east-1
S3_BUCKET=vmforge-backups
S3_ACCESS_KEY=xxx
S3_SECRET_KEY=yyy
S3_USE_PATH_STYLE=1                   # 1 for MinIO/compat, 0 for AWS virtual-host style
S3_SSL=1
S3_PREFIX=vmforge/backups
BACKUP_OFFSITE=s3                     # s3|none
DELETE_LOCAL_AFTER_UPLOAD=0           # 1 to keep only offsite
```

## DB
```bash
mysql -u root -p vmforge < migrations/0024_backups_and_policies.sql
```

## Usage
Create a backup:
```bash
php scripts/backups/run_backup.php myvm
# or by uuid
php scripts/backups/run_backup.php 123e4567-e89b-12d3-a456-426614174000
```

Retention prune (all VMs):
```bash
php scripts/backups/prune.php
```
Retention parameters per VM live in `backup_policies` (daily/weekly/monthly caps, size cap in GiB, age in days, offsite mode, and whether to drop the local copy after upload).

## UI
Visit `/admin/backups` — select VM, create backups, and delete them. This is intentionally minimal; styling matches existing cards/table components.

Notes:
- Backup path assumes KVM QCOW2 stored at `/var/lib/libvirt/images/<name>.qcow2` (as used in current agent). If your storage pools differ, wire a resolver from `vm_instances` → disk path and update `backupVM()` accordingly.
- LXC backups are not implemented in this pack.
- Upload uses a small SigV4 client (no Composer deps). If you prefer AWS SDK later, we can swap the client drop‑in.
