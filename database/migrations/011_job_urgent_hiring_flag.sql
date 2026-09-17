-- Run this once against an existing acme_ats database. Adds an
-- Urgent Hiring flag to jobs, settable by admins when creating or
-- editing a job, and shown publicly on the careers site. Safe to re-run.
USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jobs' AND COLUMN_NAME='is_urgent');
SET @sql := IF(@col=0, "ALTER TABLE jobs ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0 AFTER requirements", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
