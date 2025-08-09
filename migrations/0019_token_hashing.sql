-- 0019_token_hashing.sql
ALTER TABLE nodes 
  ADD COLUMN token_hash VARCHAR(255) NULL AFTER token,
  ADD COLUMN token_old_hash VARCHAR(255) NULL AFTER token_hash,
  ADD COLUMN token_rotated_at TIMESTAMP NULL AFTER token_old_hash;

-- optional: speed up legacy lookups during migration
ALTER TABLE nodes ADD INDEX idx_token_legacy (token);
