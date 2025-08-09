USE vmforge;

-- Rate limiting storage (per-key per minute)
CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  rl_key VARCHAR(255) NOT NULL,
  bucket_start TIMESTAMP NOT NULL,
  count INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_key_bucket (rl_key, bucket_start)
);

-- Nodes: hashed tokens + rotation fields (non-breaking, keeps existing token column intact)
ALTER TABLE nodes
  ADD COLUMN token_hash VARCHAR(255) NULL AFTER token,
  ADD COLUMN token_old_hash VARCHAR(255) NULL AFTER token_hash,
  ADD COLUMN token_rotated_at TIMESTAMP NULL AFTER token_old_hash;
