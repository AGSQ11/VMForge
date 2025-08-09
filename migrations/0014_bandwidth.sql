USE vmforge;

CREATE TABLE IF NOT EXISTS bandwidth_usage (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  interface VARCHAR(64) NOT NULL,
  rx_bytes BIGINT NOT NULL,
  tx_bytes BIGINT NOT NULL,
  rx_packets BIGINT NOT NULL,
  tx_packets BIGINT NOT NULL,
  period_start TIMESTAMP NOT NULL,
  period_end TIMESTAMP NOT NULL,
  INDEX idx_vm_period (vm_uuid, period_start),
  INDEX idx_period (period_start, period_end)
);
