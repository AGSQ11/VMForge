USE vmforge;

-- API tokens for REST auth
CREATE TABLE IF NOT EXISTS api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL, -- sha256 of plaintext token (never store token in clear)
  name VARCHAR(190) NOT NULL,
  scopes VARCHAR(255) NOT NULL DEFAULT 'api:*',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uniq_user_token (user_id, token_hash)
);

-- IPAM: pools and allocations
CREATE TABLE IF NOT EXISTS ip_pools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  cidr VARCHAR(64) NOT NULL,    -- e.g., 192.0.2.0/24 or 2001:db8::/64
  gateway VARCHAR(64) NULL,
  dns VARCHAR(128) NULL,        -- comma-separated
  version TINYINT NOT NULL,     -- 4 or 6
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ip_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pool_id INT NOT NULL,
  vm_uuid VARCHAR(64) NULL,
  ip_address VARCHAR(64) NOT NULL,
  allocated TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pool_id) REFERENCES ip_pools(id),
  UNIQUE KEY uniq_ip (pool_id, ip_address)
);

-- Extend images with checksum for verification
ALTER TABLE images ADD COLUMN IF NOT EXISTS sha256 CHAR(64) NULL;
