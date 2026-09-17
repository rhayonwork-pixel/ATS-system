-- Run this once against an existing acme_ats database to pick up the
-- candidate CRM features merged in from the old static prototype:
-- recruiter notes, structured interview feedback, and "suggest a
-- different role" on the candidate profile page.
-- Safe to re-run (CREATE TABLE IF NOT EXISTS).
USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jobs' AND COLUMN_NAME='requirements');
SET @sql := IF(@col=0, 'ALTER TABLE jobs ADD COLUMN requirements TEXT NULL AFTER description', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='referrals' AND COLUMN_NAME='notes');
SET @sql := IF(@col=0, 'ALTER TABLE referrals ADD COLUMN notes TEXT NULL AFTER job_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS candidate_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NULL,
  note TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
  FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS candidate_feedback (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NULL,
  fit ENUM('strong-fit','potential-fit','not-a-fit') NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(application_id) REFERENCES applications(id) ON DELETE CASCADE,
  FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS candidate_role_suggestions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  suggested_job_id INT UNSIGNED NOT NULL,
  note TEXT NULL,
  author_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(application_id) REFERENCES applications(id) ON DELETE CASCADE,
  FOREIGN KEY(suggested_job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
