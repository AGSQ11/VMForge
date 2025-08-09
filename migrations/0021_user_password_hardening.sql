-- 0021_user_password_hardening.sql
ALTER TABLE users
  ADD COLUMN password_hash VARCHAR(255) NULL AFTER password,
  ADD COLUMN password_legacy VARCHAR(255) NULL AFTER password_hash,
  ADD COLUMN failed_logins INT NOT NULL DEFAULT 0 AFTER password_legacy,
  ADD COLUMN locked_until TIMESTAMP NULL AFTER failed_logins,
  ADD COLUMN last_login_at TIMESTAMP NULL AFTER locked_until;

-- keep emails unique if not already
ALTER TABLE users ADD UNIQUE KEY IF NOT EXISTS uniq_users_email (email);

-- seed legacy column from old password column where applicable
UPDATE users SET password_legacy = password WHERE password_legacy IS NULL AND password IS NOT NULL AND password <> '';
