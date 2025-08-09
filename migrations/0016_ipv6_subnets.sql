USE vmforge;

CREATE TABLE IF NOT EXISTS subnets6 (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  prefix VARCHAR(128) NOT NULL,       -- e.g., 2001:db8:1234::/64
  project_id INT NULL,
  vlan_tag INT NULL,
  gateway_ip6 VARCHAR(128) NULL,      -- usually prefix::1
  ra_enabled BOOLEAN DEFAULT TRUE,
  dns_servers VARCHAR(255) NULL,      -- space-separated IPv6 addresses for RDNSS
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_name6 (name)
);

ALTER TABLE vm_instances
  ADD COLUMN subnet6_id INT NULL AFTER subnet_id,
  ADD CONSTRAINT fk_vm_subnet6 FOREIGN KEY (subnet6_id) REFERENCES subnets6(id);
