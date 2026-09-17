USE acme_ats;
-- schema.sql's candidates table already includes profile_image inline (it
-- was folded back in at some point after this migration was first written),
-- so this is guarded the same way the rest of this project's migrations
-- guard themselves, and stays a no-op on a fresh install.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='candidates' AND COLUMN_NAME='profile_image');
SET @sql := IF(@col=0,
  'ALTER TABLE candidates ADD COLUMN profile_image VARCHAR(255) NULL AFTER resume_path',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
