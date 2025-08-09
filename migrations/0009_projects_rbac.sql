USE vmforge;

CREATE TABLE IF NOT EXISTS projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_projects (
  user_id INT NOT NULL,
  project_id INT NOT NULL,
  role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  PRIMARY KEY (user_id, project_id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

ALTER TABLE vm_instances
  ADD COLUMN project_id INT NULL AFTER node_id,
  ADD COLUMN mac_address VARCHAR(17) NULL AFTER ip_address;

ALTER TABLE vm_instances
  ADD CONSTRAINT fk_vm_project FOREIGN KEY (project_id) REFERENCES projects(id);

ALTER TABLE api_tokens
  ADD COLUMN project_id INT NULL AFTER token_hash,
  ADD COLUMN scope VARCHAR(64) NOT NULL DEFAULT 'project' AFTER project_id;

CREATE TABLE IF NOT EXISTS quotas (
  project_id INT PRIMARY KEY,
  max_vms INT NULL,
  max_vcpus INT NULL,
  max_ram_mb INT NULL,
  max_disk_gb INT NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);
