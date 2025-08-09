USE vmforge;

CREATE TABLE IF NOT EXISTS snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  node_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (node_id) REFERENCES nodes(id)
);

CREATE TABLE IF NOT EXISTS backups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  node_id INT NOT NULL,
  snapshot_name VARCHAR(190) NOT NULL,
  location VARCHAR(255) NOT NULL,  -- local path or s3://bucket/key
  size_bytes BIGINT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (node_id) REFERENCES nodes(id)
);

CREATE TABLE IF NOT EXISTS schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(32) NOT NULL, -- 'backup'
  vm_uuid VARCHAR(64) NOT NULL,
  cron VARCHAR(64) NOT NULL, -- crontab expression "0 3 * * *"
  payload JSON NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
