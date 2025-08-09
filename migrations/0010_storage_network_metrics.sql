USE vmforge;

CREATE TABLE IF NOT EXISTS storage_pools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  driver ENUM('qcow2','lvmthin','zfs') NOT NULL DEFAULT 'qcow2',
  config JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE vm_instances
  ADD COLUMN storage_type ENUM('qcow2','lvmthin','zfs') NOT NULL DEFAULT 'qcow2' AFTER disk_gb,
  ADD COLUMN storage_pool_id INT NULL AFTER storage_type,
  ADD COLUMN vlan_tag INT NULL AFTER bridge,
  ADD CONSTRAINT fk_vm_storage_pool FOREIGN KEY (storage_pool_id) REFERENCES storage_pools(id);

CREATE TABLE IF NOT EXISTS metrics_vm (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid CHAR(36) NOT NULL,
  cpu_pct FLOAT NULL,
  mem_used_mb INT NULL,
  rx_bytes BIGINT NULL,
  tx_bytes BIGINT NULL,
  collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(vm_uuid), INDEX(collected_at)
);

CREATE TABLE IF NOT EXISTS metrics_node (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  hostname VARCHAR(128) NOT NULL,
  cpu_pct FLOAT NULL,
  mem_used_mb INT NULL,
  collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(hostname), INDEX(collected_at)
);
