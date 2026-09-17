-- ============================================================================
-- Acme ATS — Candidate interview access and recruiter workload ownership
-- ----------------------------------------------------------------------------
-- Run AFTER migration-seats-interviews.sql. Safe to re-run.
--
-- Design notes
-- ------------
-- * Until now anyone holding a room code could open interview-room.php. That
--   is fine for staff (they are already signed in) but it means a candidate
--   link could be forwarded and reused. interviews.candidate_token gives each
--   interview one unguessable candidate-side key, so a candidate can only ever
--   reach the interview that belongs to them. The token goes in the URL rather
--   than the candidate's email address, so no personal data is ever put in a
--   query string.
--
-- * applications.assigned_to answers "whose candidate is this?" for personal
--   analytics. It is backfilled from the job's author/owner, which is how the
--   prototype already implied ownership, so no history is invented.
-- ============================================================================

USE acme_ats;

-- ----------------------------------------------------------------------------
-- 1. interviews — the candidate's own way in
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='interviews' AND COLUMN_NAME='candidate_token');
SET @sql := IF(@col=0,
  "ALTER TABLE interviews
     ADD COLUMN candidate_token CHAR(32) NULL AFTER room_code,
     ADD COLUMN candidate_joined_at DATETIME NULL,
     ADD UNIQUE KEY interview_candidate_token (candidate_token)",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Give every existing interview a token so already-scheduled meetings work.
-- MD5(UUID()) rather than RANDOM_BYTES: XAMPP ships MariaDB, which has no
-- RANDOM_BYTES, and UUID() is unique per row on both engines. New interviews
-- get a cryptographically random token from PHP instead.
UPDATE interviews
   SET candidate_token = MD5(CONCAT(UUID(), id, RAND()))
 WHERE candidate_token IS NULL;

-- ----------------------------------------------------------------------------
-- 2. applications — who is working this candidate
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='applications' AND COLUMN_NAME='assigned_to');
SET @sql := IF(@col=0,
  "ALTER TABLE applications
     ADD COLUMN assigned_to INT UNSIGNED NULL,
     ADD INDEX application_assigned (assigned_to, stage)",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='applications' AND CONSTRAINT_NAME='fk_applications_assigned');
SET @sql := IF(@fk=0,
  "ALTER TABLE applications ADD CONSTRAINT fk_applications_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill from the job's author, falling back to its owner. This mirrors how
-- the prototype already attributed work, so the first analytics view is real
-- rather than empty.
UPDATE applications a
  JOIN jobs j ON j.id = a.job_id
   SET a.assigned_to = COALESCE(j.created_by, j.owner_id)
 WHERE a.assigned_to IS NULL AND COALESCE(j.created_by, j.owner_id) IS NOT NULL;

-- ----------------------------------------------------------------------------
-- 3. How early a candidate may enter the waiting room, in minutes
-- ----------------------------------------------------------------------------
INSERT INTO settings(setting_key, setting_value)
VALUES ('interview_join_window_minutes', '15')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
