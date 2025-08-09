USE vmforge;
ALTER TABLE nodes ADD COLUMN last_seen TIMESTAMP NULL AFTER bridge;
