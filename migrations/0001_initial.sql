-- VMForge — an ENGINYRING project
CREATE DATABASE IF NOT EXISTS vmforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vmforge;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users(email, password_hash) VALUES('admin@local', '$2y$10$S4mC7JcQKMBI8H0g9YtaZed7B7hM7bCxywV3ByN5xwHfCq0vm.3bO'); -- 'adminadmin'

CREATE TABLE nodes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  mgmt_url VARCHAR(255) NOT NULL,
  bridge VARCHAR(64) NOT NULL DEFAULT 'br0',
  token VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  type ENUM('kvm','lxc') NOT NULL,
  source_url VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO images(name, type) VALUES ('Debian 12 Cloud', 'kvm'), ('Debian LXC bookworm', 'lxc');

CREATE TABLE vm_instances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid VARCHAR(64) UNIQUE NOT NULL,
  node_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  type ENUM('kvm','lxc') NOT NULL,
  vcpus INT NOT NULL,
  memory_mb INT NOT NULL,
  disk_gb INT NOT NULL,
  image_id INT NOT NULL,
  bridge VARCHAR(64) NOT NULL,
  ip_address VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (node_id) REFERENCES nodes(id),
  FOREIGN KEY (image_id) REFERENCES images(id)
);

CREATE TABLE agent_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  node_id INT NOT NULL,
  type VARCHAR(64) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  log MEDIUMTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (node_id) REFERENCES nodes(id)
);
