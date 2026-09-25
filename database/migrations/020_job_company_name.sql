-- ---------------------------------------------------------------------------
-- 020 — company name on a job posting
--
-- Safe to re-run.
--
-- WHY THIS COLUMN EXISTS
-- ----------------------
-- This ATS is single-tenant: the company is one row in `settings`
-- (company_name) and is the same for every posting, which is why "company
-- name" had nowhere to go when the parser started extracting it.
--
-- Job descriptions that arrive as files DO name a company, though -- an agency
-- recruiting for a client, a subsidiary, or a trading name. The column is
-- NULLable and means "this posting is for a company other than us". When it is
-- NULL, every page keeps showing settings.company_name exactly as before, so
-- nothing changes for a single-company install.
-- ---------------------------------------------------------------------------
USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'company_name');
SET @sql := IF(@col = 0,
  'ALTER TABLE jobs ADD COLUMN company_name VARCHAR(160) NULL AFTER title',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
