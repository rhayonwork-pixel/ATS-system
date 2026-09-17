-- Run this once against an existing acme_ats database to support the
-- "Add to Employees" action on a hired candidate's profile: it links an
-- employee record back to the exact application they were hired from, and
-- keeps both the position they applied for and the position they were
-- hired into. Safe to re-run.
USE acme_ats;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='application_id');
SET @sql := IF(@col=0, 'ALTER TABLE employees ADD COLUMN application_id INT UNSIGNED NULL UNIQUE AFTER candidate_id, ADD FOREIGN KEY(application_id) REFERENCES applications(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='applied_position');
SET @sql := IF(@col=0, 'ALTER TABLE employees ADD COLUMN applied_position VARCHAR(180) NULL AFTER application_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO departments(name,description) VALUES
  ('Finance','Manage budgets, payments, and reporting'),
  ('Marketing','Grow awareness and demand for Acme'),
  ('Operations','Keep the business running smoothly'),
  ('Sales','Bring in new customers and revenue'),
  ('Customer Support','Help customers succeed with Acme')
ON DUPLICATE KEY UPDATE name=name;
