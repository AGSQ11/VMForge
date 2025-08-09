USE vmforge;

CREATE TABLE IF NOT EXISTS zfs_repos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL UNIQUE,
  mode ENUM('local','ssh') NOT NULL DEFAULT 'local',
  pool VARCHAR(128) NULL,
  dataset VARCHAR(256) NOT NULL,     -- e.g., tank/vmforge-backups
  remote_host VARCHAR(255) NULL,     -- when mode=ssh
  remote_user VARCHAR(64) NULL DEFAULT 'root',
  ssh_port INT NULL DEFAULT 22,
  compression ENUM('lz4','zstd','off') DEFAULT 'lz4',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- optional: record last backup metadata per VM/repo (for incremental seeds later)
CREATE TABLE IF NOT EXISTS zfs_repo_state (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vm_uuid VARCHAR(64) NOT NULL,
  repo_id INT NOT NULL,
  last_snapshot VARCHAR(255) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vm_repo (vm_uuid, repo_id),
  FOREIGN KEY (repo_id) REFERENCES zfs_repos(id) ON DELETE CASCADE
);
