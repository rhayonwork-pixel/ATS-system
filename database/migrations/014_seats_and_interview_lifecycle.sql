-- ============================================================================
-- Acme ATS — Seats, account reactivation approvals, and the interview lifecycle
-- ----------------------------------------------------------------------------
-- Run AFTER migration-rbac-approvals.sql. Safe to re-run.
--
-- Design notes
-- ------------
-- * users.hr_account_limit keeps its name in the database (renaming a column
--   in use buys nothing) but everywhere a person can see it, it is now
--   "seats". A new users.seat_released flag lets a Super Admin free a seat
--   held by a suspended or disabled account without deleting the account.
--
-- * Reactivation is a request, not a toggle. account_requests holds the
--   pending decision so the Super Admin approval is enforced by data, not by
--   whether a button was rendered.
--
-- * interviews.status is left ALONE (interviews.php, dashboard.php and
--   pipeline.php all read it). The meeting lifecycle the interviewer moves
--   through lives in the new interviews.meeting_state column:
--       scheduled -> ready -> in_progress -> ended -> review_pending -> reviewed
--   Scoring is only reachable from review_pending onward, checked server-side.
-- ============================================================================

USE acme_ats;

-- ----------------------------------------------------------------------------
-- 1. users — pending reactivation state and the seat release flag
-- ----------------------------------------------------------------------------
SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='account_status');
SET @sql := IF(@t NOT LIKE '%pending_reactivation%',
  "ALTER TABLE users MODIFY COLUMN account_status ENUM('pending','active','rejected','suspended','disabled','pending_reactivation') NOT NULL DEFAULT 'active'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='seat_released');
SET @sql := IF(@col=0,
  "ALTER TABLE users ADD COLUMN seat_released TINYINT(1) NOT NULL DEFAULT 0 AFTER hr_account_limit",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. account_requests — reactivation approvals awaiting a Super Admin
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS account_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NULL,
  request_type ENUM('reactivation') NOT NULL DEFAULT 'reactivation',
  reason VARCHAR(500) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX account_request_open (status, created_at),
  INDEX account_request_user (user_id, status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 3. notifications — who did it, and what it points at
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='actor_id');
SET @sql := IF(@col=0,
  "ALTER TABLE notifications
     ADD COLUMN actor_id INT UNSIGNED NULL AFTER user_id,
     ADD COLUMN entity_type VARCHAR(40) NULL,
     ADD COLUMN entity_id INT UNSIGNED NULL,
     ADD COLUMN action_label VARCHAR(60) NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND CONSTRAINT_NAME='fk_notifications_actor');
SET @sql := IF(@fk=0,
  "ALTER TABLE notifications ADD CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 4. interviews — meeting lifecycle, live notes, and the final recommendation
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='interviews' AND COLUMN_NAME='meeting_state');
SET @sql := IF(@col=0,
  "ALTER TABLE interviews
     ADD COLUMN meeting_state ENUM('scheduled','ready','in_progress','ended','review_pending','reviewed') NOT NULL DEFAULT 'scheduled' AFTER status,
     ADD COLUMN live_notes TEXT NULL,
     ADD COLUMN notes_updated_at DATETIME NULL,
     ADD COLUMN notes_author_id INT UNSIGNED NULL,
     ADD COLUMN started_at DATETIME NULL,
     ADD COLUMN ended_at DATETIME NULL,
     ADD COLUMN recommendation VARCHAR(60) NULL,
     ADD COLUMN reviewed_at DATETIME NULL,
     ADD COLUMN reviewer_id INT UNSIGNED NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='interviews' AND INDEX_NAME='interview_meeting_state');
SET @sql := IF(@idx=0, "ALTER TABLE interviews ADD INDEX interview_meeting_state (meeting_state, starts_at)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Bring existing rows onto the new lifecycle without changing what anyone sees.
UPDATE interviews SET meeting_state = CASE
    WHEN status IN ('cancelled','no_show') THEN 'scheduled'
    WHEN status = 'completed' AND (score IS NOT NULL OR (feedback IS NOT NULL AND feedback <> '')) THEN 'reviewed'
    WHEN status = 'completed' THEN 'review_pending'
    ELSE 'scheduled'
  END
 WHERE meeting_state = 'scheduled';

-- Anything already reviewed keeps its timestamps sensible for the history view.
UPDATE interviews SET reviewed_at = COALESCE(reviewed_at, created_at) WHERE meeting_state = 'reviewed' AND reviewed_at IS NULL;
UPDATE interviews SET ended_at = COALESCE(ended_at, starts_at) WHERE meeting_state IN ('ended','review_pending','reviewed') AND ended_at IS NULL;
