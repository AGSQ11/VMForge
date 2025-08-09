USE vmforge;
-- Add useful indexes (run once; ignore errors if they already exist)
ALTER TABLE vm_instances ADD INDEX idx_node_status (node_id, status);
ALTER TABLE vm_instances ADD INDEX idx_project_status (project_id, status);
ALTER TABLE jobs ADD INDEX idx_node_status (node_id, status);
