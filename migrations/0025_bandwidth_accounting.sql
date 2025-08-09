-- 0025_bandwidth_accounting.sql
CREATE TABLE IF NOT EXISTS bandwidth_counters (
  vm_uuid VARCHAR(64) NOT NULL,
  interface VARCHAR(64) NOT NULL,
  last_rx BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_tx BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (vm_uuid, interface)
);

CREATE TABLE IF NOT EXISTS bandwidth_usage (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  interface VARCHAR(64) NOT NULL,
  rx_bytes BIGINT UNSIGNED NOT NULL,
  tx_bytes BIGINT UNSIGNED NOT NULL,
  period_start TIMESTAMP NOT NULL,
  period_end TIMESTAMP NOT NULL,
  INDEX idx_vm_period (vm_uuid, period_start, period_end)
);

CREATE TABLE IF NOT EXISTS egress_caps (
  vm_uuid VARCHAR(64) NOT NULL PRIMARY KEY,
  mbps INT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
