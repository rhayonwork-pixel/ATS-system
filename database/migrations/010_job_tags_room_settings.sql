-- Run this once against an existing acme_ats database to pick up the new
-- job tags, applicant limits, built-in interview room, and settings.
-- (Safe to re-run: every statement checks first.)
USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jobs' AND COLUMN_NAME='tags');
SET @sql := IF(@col=0, 'ALTER TABLE jobs ADD COLUMN tags VARCHAR(255) NULL AFTER employment_type', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jobs' AND COLUMN_NAME='applicant_limit');
SET @sql := IF(@col=0, 'ALTER TABLE jobs ADD COLUMN applicant_limit SMALLINT UNSIGNED NULL AFTER tags', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='interviews' AND COLUMN_NAME='room_code');
SET @sql := IF(@col=0, 'ALTER TABLE interviews ADD COLUMN room_code VARCHAR(20) NULL AFTER meeting_provider', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='interviews' AND COLUMN_NAME='score');
SET @sql := IF(@col=0, 'ALTER TABLE interviews ADD COLUMN score TINYINT UNSIGNED NULL AFTER feedback', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO settings(setting_key,setting_value) VALUES
 ('careers_headline','Do the best work of your career.'),
 ('default_applicant_limit',''),
 ('logo_path','')
ON DUPLICATE KEY UPDATE setting_key=setting_key;
