-- Run this once against an existing acme_ats database to pick up the
-- candidate profile's stage-specific reviews (screening vs interview, kept
-- fully separate) and the simulated AI candidate analysis feature. Safe to
-- re-run.
USE acme_ats;

CREATE TABLE IF NOT EXISTS stage_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  stage_type ENUM('screening','interview') NOT NULL,
  rating TINYINT UNSIGNED NULL,
  feedback TEXT NULL,
  notes TEXT NULL,
  reviewer_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY(application_id, stage_type),
  FOREIGN KEY(application_id) REFERENCES applications(id) ON DELETE CASCADE,
  FOREIGN KEY(reviewer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS candidate_ai_analysis (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL UNIQUE,
  overall_score TINYINT UNSIGNED NOT NULL,
  category_scores TEXT NOT NULL,
  summary TEXT NOT NULL,
  strengths TEXT NOT NULL,
  concerns TEXT NOT NULL,
  recommendation VARCHAR(80) NOT NULL,
  ai_notes TEXT NOT NULL,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;
