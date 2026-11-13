-- Removes ALL data from the Bhavi portal database.
-- Tables, columns, indexes, foreign keys and views are NOT touched — only rows are deleted.
-- IDs (AUTO_INCREMENT) start again from 1.
--
-- How to use:
--   1. phpMyAdmin → select the portal database → Export (keep a backup).
--   2. SQL tab → paste this file → Go.
--   3. Open the portal: setup.php opens automatically. Create the admin and manager logins there.
--      Default departments, leave types and office timing are added back automatically.

SET FOREIGN_KEY_CHECKS = 0;

-- Users, employees, clients
TRUNCATE TABLE users;
TRUNCATE TABLE employee_profiles;
TRUNCATE TABLE employee_documents;
TRUNCATE TABLE employee_salary_history;
TRUNCATE TABLE employee_page_access;
TRUNCATE TABLE clients;
TRUNCATE TABLE client_social_links;
TRUNCATE TABLE client_employee_assignments;
TRUNCATE TABLE brand_assets;

-- Settings
TRUNCATE TABLE departments;
TRUNCATE TABLE department_weekly_schedule;
TRUNCATE TABLE leave_types;
TRUNCATE TABLE portal_settings;
TRUNCATE TABLE portal_schema_versions;

-- Attendance and leaves
TRUNCATE TABLE employee_attendance_sessions;
TRUNCATE TABLE employee_attendance_days;
TRUNCATE TABLE attendance_reviews;
TRUNCATE TABLE leave_requests;
TRUNCATE TABLE holidays;

-- Work, tasks and reports
TRUNCATE TABLE work_assignment_attachments;
TRUNCATE TABLE work_assignments;
TRUNCATE TABLE daily_work_entries;
TRUNCATE TABLE daily_work_submissions;
TRUNCATE TABLE client_requirements;
TRUNCATE TABLE requirement_assignments;
TRUNCATE TABLE requirement_files;
TRUNCATE TABLE client_work_reports;
TRUNCATE TABLE todo_tasks;

-- Money
TRUNCATE TABLE client_payments;
TRUNCATE TABLE payslips;
TRUNCATE TABLE salary_adjustments;
TRUNCATE TABLE office_payments;
TRUNCATE TABLE vendor_payments;
TRUNCATE TABLE vendors;
TRUNCATE TABLE premium_tools;
TRUNCATE TABLE client_references;

-- Chat, notifications, wishes
TRUNCATE TABLE chat_attachments;
TRUNCATE TABLE chat_messages;
TRUNCATE TABLE chat_participants;
TRUNCATE TABLE chat_conversations;
TRUNCATE TABLE notifications;
TRUNCATE TABLE announcements;
TRUNCATE TABLE wish_recipients;
TRUNCATE TABLE wishes;

-- Logins
TRUNCATE TABLE login_attempts;
TRUNCATE TABLE portal_login_tokens;
TRUNCATE TABLE password_reset_tokens;

SET FOREIGN_KEY_CHECKS = 1;
