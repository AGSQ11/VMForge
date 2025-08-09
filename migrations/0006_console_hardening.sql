USE vmforge;
ALTER TABLE console_sessions
  ADD COLUMN requester_ip VARCHAR(64) NULL AFTER node_id;
