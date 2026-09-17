-- ============================================================================
-- Acme ATS — Candidate waiting room and interviewer admission
-- ----------------------------------------------------------------------------
-- Run AFTER migration-profiles-password-reset.sql. Safe to re-run.
--
-- Design notes
-- ------------
-- * There is exactly one candidate per interview, so the waiting state lives on
--   the interview row rather than in a separate requests table. That is what
--   makes duplicate requests impossible by construction: a second click writes
--   the same row, it does not insert a second one.
--
-- * candidate_request_state:
--       none      candidate is not in the waiting room
--       waiting   candidate is waiting, the interviewer has not arrived yet
--       requested candidate has asked to come in and the interviewer must decide
--       admitted  the interviewer let them in; the room opens for them
--   "Keep waiting" deliberately leaves the state at 'requested' so the
--   interviewer can still admit them later — it is not a rejection.
-- ============================================================================

USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='interviews' AND COLUMN_NAME='candidate_request_state');
SET @sql := IF(@col=0,
  "ALTER TABLE interviews
     ADD COLUMN candidate_request_state ENUM('none','waiting','requested','admitted') NOT NULL DEFAULT 'none' AFTER candidate_joined_at,
     ADD COLUMN candidate_requested_at DATETIME NULL,
     ADD COLUMN candidate_admitted_at DATETIME NULL,
     ADD COLUMN candidate_admitted_by INT UNSIGNED NULL,
     ADD COLUMN candidate_last_seen DATETIME NULL,
     ADD COLUMN interviewer_last_seen DATETIME NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='interviews' AND CONSTRAINT_NAME='fk_interviews_admitted_by');
SET @sql := IF(@fk=0,
  "ALTER TABLE interviews ADD CONSTRAINT fk_interviews_admitted_by FOREIGN KEY (candidate_admitted_by) REFERENCES users(id) ON DELETE SET NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='interviews' AND INDEX_NAME='interview_waiting');
SET @sql := IF(@idx=0, "ALTER TABLE interviews ADD INDEX interview_waiting (candidate_request_state, candidate_requested_at)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- How long before an inactive participant is treated as gone, in seconds.
INSERT INTO settings(setting_key, setting_value)
VALUES ('meeting_presence_seconds', '25')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
