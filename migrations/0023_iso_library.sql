-- 0023_iso_library.sql
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
  INDEX idx_public (public, name)
);
