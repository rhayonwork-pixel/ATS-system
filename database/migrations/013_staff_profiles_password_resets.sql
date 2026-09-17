-- ============================================================================
-- Acme ATS — Staff profiles and Super Admin approved password resets
-- ----------------------------------------------------------------------------
-- Run AFTER migration-candidate-interviews.sql. Safe to re-run.
--
-- Design notes
-- ------------
-- * users.profile_image mirrors candidates.profile_image, so staff photos reuse
--   the upload helper and the avatar markup that already exist.
--
-- * A forgotten password is a REQUEST, decided by a Super Admin. The reset token
--   is created only on approval, is single use, and expires. Nothing anywhere
--   stores or reveals the old password — approval simply lets that one account
--   set a new one.
-- ============================================================================

USE acme_ats;

-- ----------------------------------------------------------------------------
-- 1. users — profile picture and the display name shown around the app
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='profile_image');
SET @sql := IF(@col=0,
  "ALTER TABLE users
     ADD COLUMN profile_image VARCHAR(255) NULL AFTER email,
     ADD COLUMN job_title VARCHAR(120) NULL AFTER profile_image,
     ADD COLUMN password_changed_at DATETIME NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. password_reset_requests — the Super Admin's decision queue
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NULL,
  status ENUM('pending','approved','rejected','used','expired') NOT NULL DEFAULT 'pending',
  reset_token CHAR(64) NULL,
  token_expires_at DATETIME NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NULL,
  requested_ip VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY reset_token_unique (reset_token),
  INDEX reset_open (status, created_at),
  INDEX reset_user (user_id, status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 3. How long an approved reset link stays usable, in hours
-- ----------------------------------------------------------------------------
INSERT INTO settings(setting_key, setting_value)
VALUES ('password_reset_hours', '24')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
