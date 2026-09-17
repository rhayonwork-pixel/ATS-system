-- ============================================================================
-- Acme ATS — Manual candidate entry
-- ----------------------------------------------------------------------------
-- Run AFTER migration-notifications-ui.sql. Safe to re-run.
--
-- audit_logs already matches the brief's shape and then some:
--   id, user_id, action, entity_type, entity_id, details (JSON), ip_address,
--   created_at
-- so it is left exactly as it is. candidates.source also already exists.
-- Only the genuinely new columns are added.
-- ============================================================================

USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='candidates' AND COLUMN_NAME='experience_level');
SET @sql := IF(@col=0,
  "ALTER TABLE candidates
     ADD COLUMN current_title VARCHAR(160) NULL AFTER phone,   -- not `current_role`: reserved in MySQL 8
     ADD COLUMN experience_level ENUM('entry','mid','senior','lead') NULL AFTER current_title,
     ADD COLUMN skills VARCHAR(500) NULL AFTER experience_level,
     ADD COLUMN education VARCHAR(300) NULL AFTER skills,
     ADD COLUMN notes TEXT NULL AFTER education,
     ADD COLUMN record_status ENUM('draft','active') NOT NULL DEFAULT 'active' AFTER rating,
     ADD COLUMN created_by INT UNSIGNED NULL,
     ADD INDEX candidate_record_status (record_status, created_at)",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='candidates' AND CONSTRAINT_NAME='fk_candidates_created_by');
SET @sql := IF(@fk=0,
  "ALTER TABLE candidates ADD CONSTRAINT fk_candidates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Which fields an Admin has marked mandatory on the add-candidate form.
-- Stored as JSON in the existing settings table rather than a new one.
INSERT INTO settings(setting_key, setting_value)
VALUES ('candidate_required_fields', '["full_name","email"]')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
