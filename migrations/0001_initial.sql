-- VMForge Enterprise Database Schema
-- Initial setup with all tables

CREATE DATABASE IF NOT EXISTS vmforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vmforge;

-- Users table with enhanced security
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret VARCHAR(64) NULL,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_locked_until (locked_until)
) ENGINE=InnoDB;

-- Roles table for RBAC
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) UNIQUE NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name)
) ENGINE=InnoDB;

-- Permissions table
CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) UNIQUE NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name)
) ENGINE=InnoDB;

-- Role-Permission mapping
CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- User-Role mapping
CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT NOT NULL,
  role_id INT NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Projects for multi-tenancy
CREATE TABLE IF NOT EXISTS projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  description TEXT,
  status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status)
) ENGINE=InnoDB;

-- User-Project mapping
CREATE TABLE IF NOT EXISTS user_projects (
  user_id INT NOT NULL,
  project_id INT NOT NULL,
  role ENUM('owner','admin','member','viewer') NOT NULL DEFAULT 'member',
  PRIMARY KEY (user_id, project_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  INDEX idx_user_id (user_id),
  INDEX idx_project_id (project_id)
) ENGINE=InnoDB;

-- Project quotas
CREATE TABLE IF NOT EXISTS quotas (
  project_id INT PRIMARY KEY,
  max_vms INT NULL,
  max_vcpus INT NULL,
  max_ram_mb INT NULL,
  max_disk_gb INT NULL,
  max_snapshots INT NULL,
  max_backups INT NULL,
  max_bandwidth_gb INT NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Nodes (hypervisors)
CREATE TABLE IF NOT EXISTS nodes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  mgmt_url VARCHAR(255) NOT NULL,
  bridge VARCHAR(64) NOT NULL DEFAULT 'br0',
  token VARCHAR(255) NOT NULL,
  token_hash VARCHAR(255) NULL,
  token_old_hash VARCHAR(255) NULL,
  token_rotated_at TIMESTAMP NULL,
  last_seen_at TIMESTAMP NULL,
  status ENUM('online','offline','maintenance') DEFAULT 'offline',
  capabilities JSON NULL,
  resources JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_token_hash (token_hash)
) ENGINE=InnoDB;

-- Storage pools
CREATE TABLE IF NOT EXISTS storage_pools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  node_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  driver ENUM('qcow2','raw','lvm','zfs','ceph') NOT NULL,
  path VARCHAR(500) NOT NULL,
  total_gb INT NOT NULL,
  used_gb INT NOT NULL DEFAULT 0,
  status ENUM('active','full','offline') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
  INDEX idx_node_id (node_id),
  INDEX idx_status (status)
) ENGINE=InnoDB;

-- VM images/templates
CREATE TABLE IF NOT EXISTS images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  type ENUM('kvm','lxc') NOT NULL,
  os_type VARCHAR(64) NULL,
  os_version VARCHAR(64) NULL,
  architecture VARCHAR(16) DEFAULT 'x86_64',
  source_url VARCHAR(500) NULL,
  sha256 CHAR(64) NULL,
  size_bytes BIGINT NULL,
  min_disk_gb INT NULL,
  min_ram_mb INT NULL,
  public BOOLEAN DEFAULT TRUE,
  owner_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_type (type),
  INDEX idx_public (public)
) ENGINE=InnoDB;

-- IPv4 Subnets
CREATE TABLE IF NOT EXISTS subnets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  cidr VARCHAR(64) NOT NULL,
  gateway_ip VARCHAR(64) NULL,
  dns_servers VARCHAR(255) NULL,
  project_id INT NULL,
  vlan_tag INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_name (name),
  INDEX idx_project_id (project_id)
) ENGINE=InnoDB;

-- IPv6 Subnets
CREATE TABLE IF NOT EXISTS subnets6 (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  prefix VARCHAR(64) NOT NULL,
  gateway_ip6 VARCHAR(64) NULL,
  dns_servers VARCHAR(255) NULL,
  ra_enabled BOOLEAN DEFAULT TRUE,
  project_id INT NULL,
  vlan_tag INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_name (name),
  INDEX idx_project_id (project_id)
) ENGINE=InnoDB;

-- IP pools for IPAM
CREATE TABLE IF NOT EXISTS ip_pools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  cidr VARCHAR(64) NOT NULL,
  gateway VARCHAR(64) NULL,
  dns VARCHAR(128) NULL,
  version TINYINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_version (version)
) ENGINE=InnoDB;

-- IP allocations
CREATE TABLE IF NOT EXISTS ip_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pool_id INT NOT NULL,
  vm_uuid VARCHAR(64) NULL,
  ip_address VARCHAR(64) NOT NULL,
  allocated TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pool_id) REFERENCES ip_pools(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_ip (pool_id, ip_address),
  INDEX idx_vm_uuid (vm_uuid),
  INDEX idx_allocated (allocated)
) ENGINE=InnoDB;

-- VM instances
CREATE TABLE IF NOT EXISTS vm_instances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid VARCHAR(64) UNIQUE NOT NULL,
  project_id INT NULL,
  node_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  type ENUM('kvm','lxc') NOT NULL,
  vcpus INT NOT NULL,
  memory_mb INT NOT NULL,
  disk_gb INT NOT NULL,
  image_id INT NULL,
  bridge VARCHAR(64) NOT NULL,
  ip_address VARCHAR(64) NULL,
  ip6_address VARCHAR(64) NULL,
  mac_address VARCHAR(17) NULL,
  subnet_id INT NULL,
  subnet6_id INT NULL,
  storage_type VARCHAR(32) NULL,
  storage_pool_id INT NULL,
  vlan_tag INT NULL,
  status ENUM('creating','running','stopped','suspended','error','deleted') DEFAULT 'creating',
  power_state ENUM('running','stopped','suspended','unknown') DEFAULT 'unknown',
  firewall_mode ENUM('disabled','allowlist','denylist') DEFAULT 'disabled',
  metadata JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  FOREIGN KEY (node_id) REFERENCES nodes(id),
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE SET NULL,
  FOREIGN KEY (subnet_id) REFERENCES subnets(id) ON DELETE SET NULL,
  FOREIGN KEY (subnet6_id) REFERENCES subnets6(id) ON DELETE SET NULL,
  FOREIGN KEY (storage_pool_id) REFERENCES storage_pools(id) ON DELETE SET NULL,
  INDEX idx_uuid (uuid),
  INDEX idx_project_id (project_id),
  INDEX idx_node_id (node_id),
  INDEX idx_status (status),
  INDEX idx_name_node (name, node_id)
) ENGINE=InnoDB;

-- Agent jobs queue
CREATE TABLE IF NOT EXISTS agent_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  node_id INT NOT NULL,
  type VARCHAR(64) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
  log MEDIUMTEXT NULL,
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  started_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  FOREIGN KEY (node_id) REFERENCES nodes(id),
  INDEX idx_status (status),
  INDEX idx_node_status (node_id, status)
) ENGINE=InnoDB;

-- API tokens
CREATE TABLE IF NOT EXISTS api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  name VARCHAR(190) NOT NULL,
  project_id INT NULL,
  scope VARCHAR(64) NOT NULL DEFAULT 'project',
  scopes VARCHAR(255) NOT NULL DEFAULT 'api:*',
  last_used_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_token (user_id, token_hash),
  INDEX idx_token_hash (token_hash),
  INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB;

-- Console sessions
CREATE TABLE IF NOT EXISTS console_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  node_id INT NOT NULL,
  token VARCHAR(64) NOT NULL,
  listen_port INT NOT NULL,
  requester_ip VARCHAR(64) NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (node_id) REFERENCES nodes(id),
  UNIQUE KEY uniq_token (token),
  INDEX idx_vm_uuid (vm_uuid),
  INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB;

-- Snapshots
CREATE TABLE IF NOT EXISTS snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  node_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  size_bytes BIGINT NULL,
  parent_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (node_id) REFERENCES nodes(id),
  FOREIGN KEY (parent_id) REFERENCES snapshots(id) ON DELETE CASCADE,
  INDEX idx_vm_uuid (vm_uuid)
) ENGINE=InnoDB;

-- Backups
CREATE TABLE IF NOT EXISTS backups (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  snapshot_name VARCHAR(190) NOT NULL,
  location VARCHAR(500) NOT NULL,
  type ENUM('full','incremental','differential') NOT NULL DEFAULT 'full',
  size_bytes BIGINT NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  storage ENUM('local','s3','nfs','hybrid') NOT NULL DEFAULT 'local',
  status ENUM('creating','ready','deleted','failed') NOT NULL DEFAULT 'creating',
  meta JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vm_uuid (vm_uuid),
  INDEX idx_status (status)
) ENGINE=InnoDB;

-- Backup policies
CREATE TABLE IF NOT EXISTS backup_policies (
  vm_uuid VARCHAR(64) NOT NULL PRIMARY KEY,
  keep_daily INT NULL,
  keep_weekly INT NULL,
  keep_monthly INT NULL,
  keep_yearly INT NULL,
  max_total_gb INT NULL,
  max_age_days INT NULL,
  offsite ENUM('none','s3','nfs') NOT NULL DEFAULT 's3',
  delete_local_after_upload TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_backup_policies_vm FOREIGN KEY (vm_uuid) REFERENCES vm_instances(uuid) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Firewall rules
CREATE TABLE IF NOT EXISTS firewall_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  direction ENUM('ingress','egress') NOT NULL DEFAULT 'ingress',
  protocol ENUM('tcp','udp','icmp','any') NOT NULL,
  source_cidr VARCHAR(64) NULL,
  dest_cidr VARCHAR(64) NULL,
  source_ports VARCHAR(64) NULL,
  dest_ports VARCHAR(64) NULL,
  action ENUM('allow','deny') NOT NULL,
  priority INT NOT NULL DEFAULT 1000,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vm_uuid (vm_uuid),
  INDEX idx_priority (priority)
) ENGINE=InnoDB;

-- Bandwidth usage tracking
CREATE TABLE IF NOT EXISTS bandwidth_usage (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  interface VARCHAR(64) NOT NULL,
  rx_bytes BIGINT UNSIGNED NOT NULL,
  tx_bytes BIGINT UNSIGNED NOT NULL,
  rx_packets BIGINT UNSIGNED NOT NULL,
  tx_packets BIGINT UNSIGNED NOT NULL,
  period_start TIMESTAMP NOT NULL,
  period_end TIMESTAMP NOT NULL,
  INDEX idx_vm_period (vm_uuid, period_start, period_end),
  INDEX idx_period (period_start)
) ENGINE=InnoDB;

-- VM metrics
CREATE TABLE IF NOT EXISTS vm_metrics (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  cpu_usage_percent FLOAT NOT NULL,
  memory_used_mb INT NOT NULL,
  memory_available_mb INT NOT NULL,
  disk_read_bytes BIGINT NOT NULL,
  disk_write_bytes BIGINT NOT NULL,
  disk_read_iops INT NOT NULL,
  disk_write_iops INT NOT NULL,
  network_rx_bytes BIGINT NOT NULL,
  network_tx_bytes BIGINT NOT NULL,
  collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vm_time (vm_uuid, collected_at),
  INDEX idx_collected (collected_at)
) ENGINE=InnoDB PARTITION BY RANGE (UNIX_TIMESTAMP(collected_at)) (
  PARTITION p_2024_01 VALUES LESS THAN (UNIX_TIMESTAMP('2024-02-01')),
  PARTITION p_2024_02 VALUES LESS THAN (UNIX_TIMESTAMP('2024-03-01')),
  PARTITION p_2024_03 VALUES LESS THAN (UNIX_TIMESTAMP('2024-04-01')),
  PARTITION p_2024_04 VALUES LESS THAN (UNIX_TIMESTAMP('2024-05-01')),
  PARTITION p_2024_05 VALUES LESS THAN (UNIX_TIMESTAMP('2024-06-01')),
  PARTITION p_2024_06 VALUES LESS THAN (UNIX_TIMESTAMP('2024-07-01')),
  PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Audit log
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  resource_type VARCHAR(64) NULL,
  resource_id VARCHAR(64) NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  details JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user_id (user_id),
  INDEX idx_action (action),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB PARTITION BY RANGE (UNIX_TIMESTAMP(created_at)) (
  PARTITION p_2024_q1 VALUES LESS THAN (UNIX_TIMESTAMP('2024-04-01')),
  PARTITION p_2024_q2 VALUES LESS THAN (UNIX_TIMESTAMP('2024-07-01')),
  PARTITION p_2024_q3 VALUES LESS THAN (UNIX_TIMESTAMP('2024-10-01')),
  PARTITION p_2024_q4 VALUES LESS THAN (UNIX_TIMESTAMP('2025-01-01')),
  PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Rate limiting
CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  rl_key VARCHAR(255) NOT NULL,
  bucket_start TIMESTAMP NOT NULL,
  count INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_key_bucket (rl_key, bucket_start),
  INDEX idx_bucket_start (bucket_start)
) ENGINE=InnoDB;

-- Support tickets
CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  project_id INT NULL,
  subject VARCHAR(255) NOT NULL,
  status ENUM('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  category VARCHAR(64) NULL,
  assigned_to INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_status (status),
  INDEX idx_user_id (user_id)
) ENGINE=InnoDB;

-- Ticket messages
CREATE TABLE IF NOT EXISTS ticket_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  user_id INT NOT NULL,
  message TEXT NOT NULL,
  attachments JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_ticket_id (ticket_id)
) ENGINE=InnoDB;

-- Billing customers
CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  stripe_customer_id VARCHAR(255) NULL,
  payment_method VARCHAR(64) NULL,
  billing_address JSON NULL,
  tax_id VARCHAR(64) NULL,
  credit_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uniq_user_id (user_id),
  INDEX idx_stripe_id (stripe_customer_id)
) ENGINE=InnoDB;

-- Products
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  description TEXT,
  type ENUM('vm','storage','bandwidth','addon') NOT NULL,
  specifications JSON NOT NULL,
  price_monthly DECIMAL(10, 2) NOT NULL,
  price_yearly DECIMAL(10, 2) NULL,
  setup_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  available BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Subscriptions
CREATE TABLE IF NOT EXISTS subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  product_id INT NOT NULL,
  vm_uuid VARCHAR(64) NULL,
  status ENUM('active','suspended','cancelled','expired') NOT NULL,
  billing_cycle ENUM('monthly','yearly') NOT NULL,
  current_period_start DATE NOT NULL,
  current_period_end DATE NOT NULL,
  cancel_at_period_end BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  INDEX idx_status (status),
  INDEX idx_customer_id (customer_id)
) ENGINE=InnoDB;

-- Invoices
CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  subscription_id INT NULL,
  invoice_number VARCHAR(64) UNIQUE NOT NULL,
  status ENUM('draft','pending','paid','overdue','cancelled') NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'USD',
  subtotal DECIMAL(10, 2) NOT NULL,
  tax DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10, 2) NOT NULL,
  due_date DATE NOT NULL,
  paid_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
  INDEX idx_status (status),
  INDEX idx_customer_id (customer_id)
) ENGINE=InnoDB;

-- Invoice items
CREATE TABLE IF NOT EXISTS invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(10, 2) NOT NULL,
  amount DECIMAL(10, 2) NOT NULL,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Transactions
CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  gateway ENUM('stripe','paypal','credit','manual') NOT NULL,
  transaction_id VARCHAR(255) NOT NULL,
  amount DECIMAL(10, 2) NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('completed','pending','failed','refunded') NOT NULL,
  gateway_response JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  INDEX idx_transaction_id (transaction_id)
) ENGINE=InnoDB;

-- ISO library
CREATE TABLE IF NOT EXISTS iso_library (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  size_bytes BIGINT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  os_type VARCHAR(64) NULL,
  os_version VARCHAR(64) NULL,
  architecture VARCHAR(16) NULL,
  bootable BOOLEAN DEFAULT TRUE,
  public BOOLEAN DEFAULT FALSE,
  owner_id INT NULL,
  storage_path VARCHAR(500) NOT NULL,
  download_url VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_public (public, name)
) ENGINE=InnoDB;

-- Alerts
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
  FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_severity (severity, acknowledged),
  INDEX idx_resource_alerts (resource, created_at)
) ENGINE=InnoDB;

-- Migration tracking
CREATE TABLE IF NOT EXISTS _migrations (
  filename VARCHAR(255) PRIMARY KEY,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insert default data
INSERT INTO roles (name, description) VALUES
('admin', 'Super Administrator - has all permissions'),
('billing', 'Billing Manager - can view and manage billing information'),
('support', 'Support Staff - can manage VMs and support tickets'),
('customer', 'Customer - can manage their own VMs and billing');

INSERT INTO permissions (name, description) VALUES
-- VM Management
('vms.view', 'View virtual machines'),
('vms.create', 'Create virtual machines'),
('vms.update', 'Update virtual machines'),
('vms.delete', 'Delete virtual machines'),
('vms.console', 'Access VM console'),
-- User Management
('users.view', 'View users'),
('users.create', 'Create users'),
('users.update', 'Update users'),
('users.delete', 'Delete users'),
-- Billing Management
('billing.view', 'View billing information'),
('billing.manage', 'Manage billing information'),
-- Support
('tickets.view', 'View support tickets'),
('tickets.manage', 'Manage support tickets'),
-- System
('system.view', 'View system settings'),
('system.manage', 'Manage system settings'),
-- RBAC
('rbac.view', 'View roles and permissions'),
('rbac.manage', 'Manage roles and permissions');

-- Assign permissions to roles
-- Admin gets everything (handled dynamically in code)
-- Customer role
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'customer' 
AND p.name IN ('vms.view', 'vms.create', 'vms.update', 'vms.delete', 'vms.console', 'billing.view', 'tickets.view');

-- Support role
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'support' 
AND p.name IN ('vms.view', 'vms.update', 'vms.console', 'tickets.view', 'tickets.manage');

-- Billing role
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'billing' 
AND p.name IN ('billing.view', 'billing.manage');

-- Default images
INSERT INTO images (name, type, os_type, os_version, architecture) VALUES
('Ubuntu 22.04 LTS', 'kvm', 'ubuntu', '22.04', 'x86_64'),
('Ubuntu 20.04 LTS', 'kvm', 'ubuntu', '20.04', 'x86_64'),
('Debian 12', 'kvm', 'debian', '12', 'x86_64'),
('Debian 11', 'kvm', 'debian', '11', 'x86_64'),
('CentOS 9 Stream', 'kvm', 'centos', '9', 'x86_64'),
('Rocky Linux 9', 'kvm', 'rocky', '9', 'x86_64'),
('AlmaLinux 9', 'kvm', 'almalinux', '9', 'x86_64'),
('Ubuntu 22.04 Container', 'lxc', 'ubuntu', '22.04', 'x86_64'),
('Debian 12 Container', 'lxc', 'debian', '12', 'x86_64');

-- Default products
INSERT INTO products (name, description, type, specifications, price_monthly) VALUES
('VPS Starter', 'Entry level VPS', 'vm', '{"vcpus": 1, "memory_mb": 1024, "disk_gb": 20, "bandwidth_gb": 1000}', 5.00),
('VPS Standard', 'Standard VPS', 'vm', '{"vcpus": 2, "memory_mb": 2048, "disk_gb": 40, "bandwidth_gb": 2000}', 10.00),
('VPS Pro', 'Professional VPS', 'vm', '{"vcpus": 4, "memory_mb": 4096, "disk_gb": 80, "bandwidth_gb": 4000}', 20.00),
('VPS Business', 'Business VPS', 'vm', '{"vcpus": 8, "memory_mb": 8192, "disk_gb": 160, "bandwidth_gb": 8000}', 40.00),
('Extra Storage', 'Additional storage space', 'storage', '{"disk_gb": 100}', 5.00),
('Extra Bandwidth', 'Additional bandwidth', 'bandwidth', '{"bandwidth_gb": 1000}', 5.00);
