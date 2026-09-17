USE acme_ats;

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

CREATE TABLE IF NOT EXISTS employee_compensation (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  salary DECIMAL(12,2) NOT NULL,
  pay_frequency ENUM('monthly','semi_monthly','biweekly','weekly') NOT NULL DEFAULT 'monthly',
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  currency CHAR(3) NOT NULL DEFAULT 'PHP',
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pay_periods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status ENUM('open','processing','approved','paid','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payroll_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pay_period_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  basic_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay DECIMAL(12,2) GENERATED ALWAYS AS (basic_pay + overtime_pay + allowances - deductions) STORED,
  status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
  notes VARCHAR(500) NULL,
  UNIQUE KEY period_employee(pay_period_id, employee_id),
  FOREIGN KEY(pay_period_id) REFERENCES pay_periods(id) ON DELETE CASCADE,
  FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payroll_deductions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payroll_entry_id BIGINT UNSIGNED NOT NULL,
  deduction_type VARCHAR(100) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  notes VARCHAR(300) NULL,
  FOREIGN KEY(payroll_entry_id) REFERENCES payroll_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payslips (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payroll_entry_id BIGINT UNSIGNED NOT NULL UNIQUE,
  issued_at DATETIME NULL,
  file_path VARCHAR(255) NULL,
  viewed_at DATETIME NULL,
  FOREIGN KEY(payroll_entry_id) REFERENCES payroll_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO work_schedules(name) VALUES ('Standard 9 to 6') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO settings(setting_key,setting_value) VALUES ('attendance_timezone','Asia/Manila'),('attendance_grace_minutes','10'),('payroll_currency','PHP') ON DUPLICATE KEY UPDATE setting_key=setting_key;
