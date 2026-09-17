-- ============================================================================
-- Acme ATS — Super Admin, permissions, and job posting approval workflow
-- ----------------------------------------------------------------------------
-- Run this ONCE against an existing acme_ats database (after schema.sql).
-- Safe to re-run: every statement is guarded against already existing.
--
-- Design notes
-- ------------
-- * jobs.status is left ALONE. The whole app (index.php, jobs.php, apply.php,
--   job-detail.php, dashboard.php, analytics.php, pipeline.php, refer.php,
--   candidate.php) treats status='open' as "publicly visible". So:
--       Published  == jobs.status = 'open'
--       Closed     == jobs.status = 'closed'
--   The new governance states live in jobs.approval_status, which never
--   affects public visibility on its own. A job can only reach status='open'
--   through an Admin, which is what makes the approval gate real.
--
-- * users.active is left ALONE for the same reason (auth.php and login.php
--   both filter on active=1). The richer lifecycle lives in
--   users.account_status, and active is kept as its mirror:
--       active = 1  only when account_status = 'active'
-- ============================================================================

USE acme_ats;

-- ----------------------------------------------------------------------------
-- 1. users.role — add super_admin at the top of the hierarchy
-- ----------------------------------------------------------------------------
SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role');
SET @sql := IF(@t NOT LIKE '%super_admin%',
  "ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','admin','recruiter','hiring_manager','employee') NOT NULL DEFAULT 'recruiter'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. users — account lifecycle, provisioning owner, HR/Recruiter cap
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='account_status');
SET @sql := IF(@col=0,
  "ALTER TABLE users ADD COLUMN account_status ENUM('pending','active','rejected','suspended','disabled') NOT NULL DEFAULT 'active' AFTER active",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='created_by');
SET @sql := IF(@col=0,
  "ALTER TABLE users ADD COLUMN created_by INT UNSIGNED NULL AFTER account_status",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='hr_account_limit');
SET @sql := IF(@col=0,
  "ALTER TABLE users ADD COLUMN hr_account_limit SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER created_by",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='approved_by');
SET @sql := IF(@col=0,
  "ALTER TABLE users ADD COLUMN approved_by INT UNSIGNED NULL, ADD COLUMN approved_at DATETIME NULL, ADD COLUMN status_note VARCHAR(500) NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_users_created_by');
SET @sql := IF(@fk=0,
  "ALTER TABLE users ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing rows: mirror the legacy active flag into the new lifecycle column.
UPDATE users SET account_status = IF(active=1,'active','disabled')
 WHERE account_status='active' AND active=0;

-- ----------------------------------------------------------------------------
-- 3. user_permissions — granular capability grants
--    Super Admin grants to Admins. Admin grants to their HR/Recruiters.
--    A missing row means "not granted". Super Admins bypass this table.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  permission VARCHAR(60) NOT NULL,
  granted_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY user_permission (user_id, permission),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 4. jobs — approval governance columns (visibility still driven by status)
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jobs' AND COLUMN_NAME='approval_status');
SET @sql := IF(@col=0,
  "ALTER TABLE jobs
     ADD COLUMN approval_status ENUM('none','draft','pending','approved','rejected','changes_requested') NOT NULL DEFAULT 'none' AFTER status,
     ADD COLUMN created_by INT UNSIGNED NULL AFTER owner_id,
     ADD COLUMN submitted_by INT UNSIGNED NULL,
     ADD COLUMN submitted_at DATETIME NULL,
     ADD COLUMN reviewed_by INT UNSIGNED NULL,
     ADD COLUMN reviewed_at DATETIME NULL,
     ADD COLUMN review_note TEXT NULL,
     ADD COLUMN source_pdf VARCHAR(255) NULL,
     ADD COLUMN salary_info VARCHAR(255) NULL,
     ADD COLUMN responsibilities TEXT NULL,
     ADD COLUMN qualifications TEXT NULL,
     ADD COLUMN preferred_skills TEXT NULL,
     ADD COLUMN experience_required VARCHAR(255) NULL,
     ADD COLUMN education_required VARCHAR(255) NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jobs' AND INDEX_NAME='jobs_approval');
SET @sql := IF(@idx=0, "ALTER TABLE jobs ADD INDEX jobs_approval (approval_status, status)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: pre-existing jobs were Admin-created, so they are already blessed.
UPDATE jobs SET approval_status = CASE WHEN status='draft' THEN 'draft' ELSE 'approved' END
 WHERE approval_status='none';
UPDATE jobs SET created_by = owner_id WHERE created_by IS NULL AND owner_id IS NOT NULL;

-- ----------------------------------------------------------------------------
-- 5. job_approvals — the full review history for one job
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_approvals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NULL,
  action ENUM('submitted','approved','rejected','changes_requested','published','resubmitted','withdrawn') NOT NULL,
  note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX job_approval_job (job_id, created_at),
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 6. notifications — approval hand-offs between Recruiter and Admin
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(60) NOT NULL DEFAULT 'general',
  title VARCHAR(200) NOT NULL,
  body VARCHAR(500) NULL,
  link VARCHAR(200) NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX notification_inbox (user_id, read_at, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 7. Seed the Super Admin + grandfather the existing Admin
--    Demo password is the same as the other seeded accounts: password
-- ----------------------------------------------------------------------------
INSERT INTO users (name, email, password_hash, role, active, account_status, hr_account_limit)
VALUES ('Super Admin','superadmin@acme.test',
        '$2y$10$d0ujHW5.pW4VY.wERrByzeGBmCDQrkkpzTlPawcHAnvQcA1iylBgS',
        'super_admin', 1, 'active', 0)
ON DUPLICATE KEY UPDATE role='super_admin', account_status='active', active=1;

-- Every pre-existing Admin keeps everything they could already do, plus a
-- starting allowance of 5 HR/Recruiter accounts.
UPDATE users SET hr_account_limit = 5 WHERE role='admin' AND hr_account_limit = 0;

INSERT IGNORE INTO user_permissions (user_id, permission, granted_by)
SELECT u.id, p.permission, (SELECT id FROM users WHERE role='super_admin' ORDER BY id LIMIT 1)
FROM users u
CROSS JOIN (
  SELECT 'manage_accounts' AS permission UNION ALL
  SELECT 'job_management'  UNION ALL
  SELECT 'job_posting'     UNION ALL
  SELECT 'audit_trail'     UNION ALL
  SELECT 'applicant_portal'
) p
WHERE u.role = 'admin';

-- Pre-existing recruiters keep job access so nothing they had disappears.
INSERT IGNORE INTO user_permissions (user_id, permission, granted_by)
SELECT u.id, p.permission, (SELECT id FROM users WHERE role='super_admin' ORDER BY id LIMIT 1)
FROM users u
CROSS JOIN (
  SELECT 'job_management' AS permission UNION ALL
  SELECT 'job_posting'
) p
WHERE u.role = 'recruiter';

-- Attribute any legacy recruiter to the first Admin so account counts read sensibly.
UPDATE users SET created_by = (SELECT id FROM (SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1) x)
 WHERE role='recruiter' AND created_by IS NULL;
