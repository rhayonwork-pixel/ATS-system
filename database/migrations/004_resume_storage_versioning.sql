-- ============================================================================
-- Acme ATS — Resume storage, versioning and full-text search
-- ----------------------------------------------------------------------------
-- Run AFTER migration-add-candidate.sql. Safe to re-run.
--
-- IMPORTANT: as of this update, apply.php (the public application form) also
-- depends on the candidate_documents table this migration creates, not just
-- the internal Add Candidate form. Both routes now store a resume the same
-- way; candidates.php and pipeline.php also read from it (with a defensive
-- fallback to the legacy candidates.resume_path column if this migration has
-- not been run yet, so nothing breaks in the meantime -- but resumes uploaded
-- through apply.php before this migration is run will not be retrievable
-- until it is).
-- ============================================================================

USE acme_ats;

-- ----------------------------------------------------------------------------
-- 1. candidates.resume_text — extracted text, for keyword search
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='candidates' AND COLUMN_NAME='resume_text');
SET @sql := IF(@col=0,
  "ALTER TABLE candidates ADD COLUMN resume_text LONGTEXT NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FULLTEXT needs InnoDB 5.6+. Wrapped so an older engine does not abort the
-- migration — search then falls back to LIKE, which candidates.php handles.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='candidates' AND INDEX_NAME='ft_resume_text');
SET @sql := IF(@idx=0,
  "ALTER TABLE candidates ADD FULLTEXT INDEX ft_resume_text (resume_text)",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. candidate_documents — one row per uploaded file, versioned
--
--    is_primary = 1 is the live resume. Uploading a replacement demotes the
--    previous one to 0 rather than deleting it, so history is preserved.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidate_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NULL,          -- NULL while still a pending upload
  upload_token CHAR(32) NULL,              -- links a parse to its later save
  stored_name VARCHAR(255) NOT NULL,       -- name on disk, never user-supplied
  original_name VARCHAR(255) NOT NULL,     -- what the recruiter saw
  extension VARCHAR(8) NOT NULL,
  mime_type VARCHAR(100) NULL,
  byte_size INT UNSIGNED NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 1,
  parsed TINYINT(1) NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY stored_name_unique (stored_name),
  INDEX doc_candidate (candidate_id, is_primary, created_at),
  INDEX doc_token (upload_token),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
