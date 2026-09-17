-- Run this once against an existing acme_ats database to remove Payroll
-- and Leave management, which are no longer part of this ATS. Safe to
-- re-run. This is destructive for these specific tables only — everything
-- else (candidates, applications, employees, attendance, etc.) is untouched.
USE acme_ats;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS payslips;
DROP TABLE IF EXISTS payroll_deductions;
DROP TABLE IF EXISTS payroll_entries;
DROP TABLE IF EXISTS pay_periods;
DROP TABLE IF EXISTS employee_compensation;
DROP TABLE IF EXISTS leave_requests;
SET FOREIGN_KEY_CHECKS = 1;

DELETE FROM settings WHERE setting_key='payroll_currency';
