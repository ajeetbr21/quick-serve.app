ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL;
ALTER TABLE users ADD INDEX idx_last_activity (last_activity);
