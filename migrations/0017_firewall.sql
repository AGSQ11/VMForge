USE vmforge;

CREATE TABLE IF NOT EXISTS firewall_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  protocol ENUM('tcp','udp','icmp','any') NOT NULL DEFAULT 'tcp',
  source_cidr VARCHAR(128) DEFAULT 'any',
  dest_ports VARCHAR(64) DEFAULT 'any', -- single "80", range "1000-2000", comma list "80,443"
  action ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  priority INT NOT NULL DEFAULT 1000,
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vm (vm_uuid),
  INDEX idx_vm_enabled (vm_uuid, enabled, priority)
);

ALTER TABLE vm_instances
  ADD COLUMN firewall_mode ENUM('disabled','allowlist','denylist') NOT NULL DEFAULT 'disabled' AFTER vlan_tag;
