-- ============================================================================
-- Acme ATS — Notification categories, priorities, and the meeting widget
-- ----------------------------------------------------------------------------
-- Run AFTER migration-waiting-room.sql. Safe to re-run.
--
-- The brief proposed a fresh notifications table. This project already has one
-- with 300-odd call sites across the approval, seat, reactivation and password
-- workflows, so the existing table is EXTENDED rather than replaced:
--
--   brief                 this table
--   ------------------    --------------------------------------------------
--   category              category      (new, backfilled from `type`)
--   priority              priority      (new, backfilled from `type`)
--   title                 title         (already present)
--   message               body          (already present, kept)
--   action_url            link          (already present, kept)
--   is_read               read_at       (already present — a timestamp says
--                                        both whether AND when, so it is kept
--                                        and is_read is exposed as a derived
--                                        value in the API instead)
-- ============================================================================

USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='category');
SET @sql := IF(@col=0,
  "ALTER TABLE notifications
     ADD COLUMN category ENUM('application','interview','message','system') NOT NULL DEFAULT 'system' AFTER type,
     ADD COLUMN priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium' AFTER category",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='idx_user_unread');
SET @sql := IF(@idx=0,
  "ALTER TABLE notifications ADD INDEX idx_user_unread (user_id, read_at, created_at)",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill from the existing `type` values so the filter chips work on the
-- notifications that are already in the table.
UPDATE notifications SET category = CASE
    WHEN type IN ('job_approval','job_decision')                       THEN 'application'
    WHEN type LIKE 'interview%' OR type LIKE '%reactivation%'          THEN 'interview'
    WHEN type IN ('account','account_approval','account_suspended',
                  'account_disabled','permissions','seat_limit',
                  'password_reset')                                    THEN 'system'
    ELSE 'system'
  END
 WHERE category = 'system';

-- Reactivation and password items are account matters, not interviews.
UPDATE notifications SET category='system'
 WHERE type IN ('reactivation_request','reactivation_approved','reactivation_rejected','password_reset');

UPDATE notifications SET priority = CASE
    WHEN type IN ('reactivation_request','password_reset','account_suspended',
                  'account_disabled','job_approval')                   THEN 'high'
    WHEN type IN ('account_approval','job_decision','permissions','seat_limit') THEN 'medium'
    ELSE 'low'
  END
 WHERE priority = 'medium';
