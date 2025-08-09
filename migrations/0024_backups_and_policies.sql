-- 0024_backups_and_policies.sql
CREATE TABLE IF NOT EXISTS backups (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  type ENUM('full','incremental') NOT NULL DEFAULT 'full',
  size_bytes BIGINT NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  storage ENUM('local','s3','hybrid') NOT NULL DEFAULT 'local',
  path VARCHAR(500) NULL,
  s3_key VARCHAR(500) NULL,
  meta JSON NULL,
  status ENUM('ready','deleted','failed') NOT NULL DEFAULT 'ready',
  INDEX idx_vm_time (vm_uuid, created_at),
  INDEX idx_status (status)
);

CREATE TABLE IF NOT EXISTS backup_policies (
  vm_uuid VARCHAR(64) NOT NULL PRIMARY KEY,
  keep_daily INT NULL,
  keep_weekly INT NULL,
  keep_monthly INT NULL,
  max_total_gb INT NULL,
  max_age_days INT NULL,
  offsite ENUM('none','s3') NOT NULL DEFAULT 's3',
  delete_local_after_upload TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_backup_policies_vm FOREIGN KEY (vm_uuid) REFERENCES vm_instances(uuid) ON DELETE CASCADE
);
