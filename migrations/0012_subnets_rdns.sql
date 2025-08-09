USE vmforge;

CREATE TABLE IF NOT EXISTS subnets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  cidr VARCHAR(64) NOT NULL, -- e.g., 198.51.100.0/24
  project_id INT NULL,
  vlan_tag INT NULL,
  gateway_ip VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_name (name)
);

ALTER TABLE vm_instances
  ADD COLUMN subnet_id INT NULL AFTER ip_address,
  ADD CONSTRAINT fk_vm_subnet FOREIGN KEY (subnet_id) REFERENCES subnets(id);
