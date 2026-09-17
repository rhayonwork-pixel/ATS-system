-- Run this once against an existing acme_ats database. Adds meeting_type
-- to interviews so a meeting can be explicitly scheduled as a Screening
-- or an Interview — used to route the in-room scorecard to the matching
-- stage_reviews row on the candidate's profile. Safe to re-run.
USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='interviews' AND COLUMN_NAME='meeting_type');
SET @sql := IF(@col=0, "ALTER TABLE interviews ADD COLUMN meeting_type ENUM('screening','interview') NOT NULL DEFAULT 'interview' AFTER interviewer_id", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
