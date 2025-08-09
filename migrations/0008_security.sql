USE vmforge;

ALTER TABLE users
  ADD COLUMN totp_secret VARCHAR(64) NULL AFTER password_hash,
  ADD COLUMN failed_logins INT NOT NULL DEFAULT 0 AFTER totp_secret,
  ADD COLUMN lock_until DATETIME NULL AFTER failed_logins,
  ADD COLUMN last_login_at DATETIME NULL AFTER lock_until,
  ADD COLUMN last_login_ip VARCHAR(64) NULL AFTER last_login_at;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  ip VARCHAR(64) NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_rate_limits (
  token_hash VARCHAR(128) NOT NULL,
  window_start DATETIME NOT NULL,
  count INT NOT NULL DEFAULT 0,
  PRIMARY KEY (token_hash, window_start)
);
