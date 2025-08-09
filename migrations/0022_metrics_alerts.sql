-- 0022_metrics_alerts.sql
ALTER TABLE nodes ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMP NULL;

CREATE TABLE IF NOT EXISTS metrics_current (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  value DOUBLE NOT NULL,
  labels JSON NULL,
  collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name_time (name, collected_at)
);

CREATE TABLE IF NOT EXISTS alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resource VARCHAR(128) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL,
  type VARCHAR(64) NOT NULL,
  message TEXT NOT NULL,
  acknowledged TINYINT(1) DEFAULT 0,
  acknowledged_by INT NULL,
  acknowledged_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_severity (severity, acknowledged),
  INDEX idx_resource_alerts (resource, created_at)
);
