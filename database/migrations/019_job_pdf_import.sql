-- ---------------------------------------------------------------------------
-- 019 — PDF job description import
--
-- Adds the three columns the importer needs on the jobs table. Safe to re-run:
-- every ALTER is guarded by an information_schema check, the same way every
-- migration in this folder is.
--
-- A NOTE ON THE TABLE NAME
-- ------------------------
-- The feature request called this table `job_posts`. There is no such table —
-- postings have always lived in `jobs` (see database/schema.sql), and every
-- page, query and foreign key in the app points there. Adding `job_posts`
-- would create a second home for the same rows, so the columns go on `jobs`.
--
-- A NOTE ON original_pdf_path VS source_pdf
-- -----------------------------------------
-- `source_pdf` already exists and holds a PUBLIC path under
-- assets/uploads/job-descriptions/, which is web-servable — anyone who guesses
-- the filename can read a draft job description. `original_pdf_path` holds a
-- stored name inside storage/job_attachments/, which the web server refuses to
-- serve; job-attachment.php delivers it after a permission check.
--
-- New imports write `original_pdf_path` only. `source_pdf` is kept and
-- backfilled so existing rows keep working, and is now read-only legacy data —
-- the same treatment candidates.resume_path got when resumes moved to
-- storage/ (see migration 004).
-- ---------------------------------------------------------------------------
USE acme_ats;

-- 1. The stored PDF, as a bare filename inside storage/job_attachments/.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'original_pdf_path');
SET @sql := IF(@col = 0,
  'ALTER TABLE jobs ADD COLUMN original_pdf_path VARCHAR(255) NULL AFTER source_pdf',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Benefits. The parser reads a "Benefits" / "What we offer" section, and
--    there was nowhere to put it: it used to be dropped on the floor.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'benefits');
SET @sql := IF(@col = 0,
  'ALTER TABLE jobs ADD COLUMN benefits TEXT NULL AFTER salary_info',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Application deadline. DATE, not DATETIME: a closing date has no time of
--    day, and the parser refuses to invent one. A deadline it could not read
--    unambiguously is shown to the recruiter as text and never written here.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'application_deadline');
SET @sql := IF(@col = 0,
  'ALTER TABLE jobs ADD COLUMN application_deadline DATE NULL AFTER benefits',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Backfill: an older import already has its file under assets/uploads. It
--    stays where it is (moving files in a migration is not re-runnable), but
--    the new column records that a PDF exists, marked with its legacy prefix so
--    job-attachment.php knows which folder to read.
UPDATE jobs
   SET original_pdf_path = CONCAT('legacy:', source_pdf)
 WHERE original_pdf_path IS NULL
   AND source_pdf IS NOT NULL
   AND source_pdf <> '';
