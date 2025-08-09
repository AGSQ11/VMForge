USE vmforge;

CREATE TABLE IF NOT EXISTS console_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  node_id INT NOT NULL,
  token VARCHAR(64) NOT NULL,
  listen_port INT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (vm_uuid),
  UNIQUE KEY uniq_token (token),
  FOREIGN KEY (node_id) REFERENCES nodes(id)
);
