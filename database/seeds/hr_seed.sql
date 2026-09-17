-- Default settings + attendance schedule seed
INSERT INTO settings(setting_key,setting_value) VALUES ('company_name','Acme'),('default_leave_days','20'),('careers_headline','Do the best work of your career.'),('default_applicant_limit',''),('logo_path','') ON DUPLICATE KEY UPDATE setting_key=setting_key;
INSERT INTO work_schedules(name) VALUES ('Standard 9 to 6') ON DUPLICATE KEY UPDATE name=name;
INSERT INTO settings(setting_key,setting_value) VALUES ('attendance_timezone','Asia/Manila'),('attendance_grace_minutes','10') ON DUPLICATE KEY UPDATE setting_key=setting_key;
