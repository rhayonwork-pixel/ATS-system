-- Run this once against an existing acme_ats database to pick up the
-- expanded application form fields merged in from the old prototype's
-- apply.html: a separate "why do you want to work here" question,
-- a structured "how did you hear about us" source, and an optional
-- portfolio URL. Safe to re-run.
USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='applications' AND COLUMN_NAME='why_us');
SET @sql := IF(@col=0, 'ALTER TABLE applications ADD COLUMN why_us TEXT NULL AFTER cover_letter', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='candidates' AND COLUMN_NAME='portfolio_url');
SET @sql := IF(@col=0, 'ALTER TABLE candidates ADD COLUMN portfolio_url VARCHAR(255) NULL AFTER resume_path', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
