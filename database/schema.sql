CREATE DATABASE IF NOT EXISTS acme_ats CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE acme_ats;

CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role ENUM('admin','recruiter','hiring_manager','employee') NOT NULL DEFAULT 'recruiter', active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE departments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL UNIQUE, description TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE jobs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, department_id INT UNSIGNED NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(200) NOT NULL UNIQUE, location VARCHAR(160), employment_type ENUM('full_time','part_time','contract','internship') DEFAULT 'full_time', tags VARCHAR(255) NULL, applicant_limit SMALLINT UNSIGNED NULL, description LONGTEXT, requirements TEXT NULL, is_urgent TINYINT(1) NOT NULL DEFAULT 0, status ENUM('draft','open','paused','closed') DEFAULT 'draft', owner_id INT UNSIGNED NULL, published_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(department_id) REFERENCES departments(id) ON DELETE SET NULL, FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB;
CREATE TABLE candidates (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(50), resume_path VARCHAR(255), profile_image VARCHAR(255) NULL, portfolio_url VARCHAR(255) NULL, source VARCHAR(80) DEFAULT 'career_site', rating TINYINT UNSIGNED DEFAULT 0, consent_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(email)) ENGINE=InnoDB;
CREATE TABLE applications (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, candidate_id INT UNSIGNED NOT NULL, job_id INT UNSIGNED NOT NULL, stage ENUM('new','screening','interview','offer','hired','rejected') DEFAULT 'new', status ENUM('active','withdrawn') DEFAULT 'active', cover_letter TEXT, why_us TEXT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY(candidate_id,job_id), FOREIGN KEY(candidate_id) REFERENCES candidates(id) ON DELETE CASCADE, FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE) ENGINE=InnoDB;
CREATE TABLE interviews (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, application_id INT UNSIGNED NOT NULL, interviewer_id INT UNSIGNED NULL, meeting_type ENUM('screening','interview') NOT NULL DEFAULT 'interview', starts_at DATETIME NOT NULL, ends_at DATETIME NULL, interview_type ENUM('phone','video','onsite','panel') NOT NULL DEFAULT 'video', meeting_url VARCHAR(500) NULL, meeting_provider VARCHAR(80) NOT NULL DEFAULT 'Zoom', room_code VARCHAR(20) NULL, room_status ENUM('idle','live') NOT NULL DEFAULT 'idle', room_last_ping DATETIME NULL, location VARCHAR(255) NULL, status ENUM('scheduled','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled', notes TEXT NULL, feedback TEXT NULL, score TINYINT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(application_id) REFERENCES applications(id) ON DELETE CASCADE, FOREIGN KEY(interviewer_id) REFERENCES users(id) ON DELETE SET NULL, INDEX interview_start(starts_at), INDEX interview_room_live(room_status,room_last_ping)) ENGINE=InnoDB;
CREATE TABLE offers (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, application_id INT UNSIGNED NOT NULL, salary DECIMAL(12,2), start_date DATE, status ENUM('draft','sent','accepted','declined','expired') DEFAULT 'draft', notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(application_id) REFERENCES applications(id) ON DELETE CASCADE) ENGINE=InnoDB;
CREATE TABLE employees (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, candidate_id INT UNSIGNED NULL, application_id INT UNSIGNED NULL UNIQUE, applied_position VARCHAR(180) NULL, employee_number VARCHAR(40) UNIQUE, job_title VARCHAR(180), department_id INT UNSIGNED NULL, start_date DATE, status ENUM('active','on_leave','terminated') DEFAULT 'active', FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(candidate_id) REFERENCES candidates(id) ON DELETE SET NULL, FOREIGN KEY(application_id) REFERENCES applications(id) ON DELETE SET NULL, FOREIGN KEY(department_id) REFERENCES departments(id) ON DELETE SET NULL) ENGINE=InnoDB;
CREATE TABLE referrals (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, referrer_name VARCHAR(120) NOT NULL, referrer_email VARCHAR(190) NOT NULL, candidate_name VARCHAR(120) NOT NULL, candidate_email VARCHAR(190) NOT NULL, job_id INT UNSIGNED NULL, notes TEXT NULL, status ENUM('new','contacted','hired','closed') DEFAULT 'new', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE SET NULL) ENGINE=InnoDB;
CREATE TABLE audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, action VARCHAR(120) NOT NULL, entity_type VARCHAR(80), entity_id INT UNSIGNED NULL, details JSON NULL, ip_address VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB;
CREATE TABLE settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL);

INSERT INTO departments(name,description) VALUES ('Engineering','Build and scale the Acme platform'),('Design','Make complex things feel simple'),('People','Support an exceptional employee experience'),('Finance','Manage budgets, payments, and reporting'),('Marketing','Grow awareness and demand for Acme'),('Operations','Keep the business running smoothly'),('Sales','Bring in new customers and revenue'),('Customer Support','Help customers succeed with Acme') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO users(name,email,password_hash,role) VALUES ('Admin User','admin@acme.test', '$2y$10$d0ujHW5.pW4VY.wERrByzeGBmCDQrkkpzTlPawcHAnvQcA1iylBgS', 'admin'),('Alex Recruiter','recruiter@acme.test', '$2y$10$d0ujHW5.pW4VY.wERrByzeGBmCDQrkkpzTlPawcHAnvQcA1iylBgS', 'recruiter') ON DUPLICATE KEY UPDATE email=email;
INSERT INTO jobs(department_id,title,slug,location,tags,applicant_limit,description,requirements,status,published_at) SELECT d.id,'Senior Product Engineer','senior-product-engineer','Remote / New York','React,TypeScript,Remote',30,'Build the future of work with a thoughtful engineering team.','5+ years building production web applications\nStrong experience with React and TypeScript\nComfortable owning features end-to-end, from design to deploy\nClear written and verbal communication in an async, remote-first team','open',NOW() FROM departments d WHERE d.name='Engineering' ON DUPLICATE KEY UPDATE title=title;
INSERT INTO settings(setting_key,setting_value) VALUES ('company_name','Acme'),('default_leave_days','20'),('careers_headline','Do the best work of your career.'),('default_applicant_limit',''),('logo_path','') ON DUPLICATE KEY UPDATE setting_key=setting_key;
-- Demo password for both seeded users: password

-- Attendance and payroll foundation
CREATE TABLE IF NOT EXISTS work_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'Asia/Manila',
  start_time TIME NOT NULL DEFAULT '09:00:00',
  end_time TIME NOT NULL DEFAULT '18:00:00',
  break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  work_days SET('mon','tue','wed','thu','fri','sat','sun') NOT NULL DEFAULT 'mon,tue,wed,thu,fri',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employee_schedules (
  employee_id INT UNSIGNED PRIMARY KEY,
  schedule_id INT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY(schedule_id) REFERENCES work_schedules(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  holiday_date DATE NOT NULL UNIQUE,
  is_paid TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  clock_in DATETIME NULL,
  clock_out DATETIME NULL,
  break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  worked_minutes SMALLINT UNSIGNED NULL,
  status ENUM('present','late','absent','half_day','on_leave','holiday','incomplete') NOT NULL DEFAULT 'present',
  source ENUM('self_service','admin','import') NOT NULL DEFAULT 'self_service',
  notes VARCHAR(500) NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY employee_work_date(employee_id, work_date),
  INDEX attendance_date(work_date),
  FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_breaks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id BIGINT UNSIGNED NOT NULL,
  break_start DATETIME NOT NULL,
  break_end DATETIME NULL,
  FOREIGN KEY(attendance_id) REFERENCES attendance_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_corrections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id BIGINT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  requested_clock_in DATETIME NULL,
  requested_clock_out DATETIME NULL,
  reason VARCHAR(500) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(attendance_id) REFERENCES attendance_records(id) ON DELETE CASCADE,
  FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO work_schedules(name) VALUES ('Standard 9 to 6') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO settings(setting_key,setting_value) VALUES ('attendance_timezone','Asia/Manila'),('attendance_grace_minutes','10') ON DUPLICATE KEY UPDATE setting_key=setting_key;

-- Candidate CRM (merged in from the old static prototype's candidate profile page)
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

-- Stage-specific human reviews (screening vs interview kept fully separate).
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

-- Simulated AI candidate analysis (prototype). One row per application;
-- re-running "Analyze Application" overwrites it. category_scores/strengths/
-- concerns are stored as JSON text so this stays portable across MySQL
-- versions, and so the structure is reusable by future automation.
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
-- 010_create_interview_signals.sql
-- Adds the peer-connection signaling storage the browser-based interview
-- room needs: one row per interview holding the current SDP offer/answer,
-- and an append-only queue of ICE candidates per side. AJAX polling
-- (api/interview/*.php) reads and writes these — InfinityFree has no
-- WebSocket support, so polling is the transport, not the compromise.

CREATE TABLE IF NOT EXISTS interview_signals (
  interview_id BIGINT UNSIGNED PRIMARY KEY,
  offer_sdp LONGTEXT NULL,
  offer_updated_at DATETIME NULL,
  answer_sdp LONGTEXT NULL,
  answer_updated_at DATETIME NULL,
  connected_at DATETIME NULL,
  ended_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS interview_ice_candidates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  interview_id BIGINT UNSIGNED NOT NULL,
  role ENUM('host','candidate') NOT NULL,
  candidate LONGTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE,
  INDEX ice_lookup (interview_id, role, id)
) ENGINE=InnoDB;

