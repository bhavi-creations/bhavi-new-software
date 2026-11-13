-- Clears all working data and keeps only employees, clients and portal settings.
-- Same result as: php database/reset-data.php --yes  (that script also deletes the uploaded files).
-- Run in phpMyAdmin on the portal database AFTER taking an Export backup.
--
-- Kept: users, employee_profiles, employee_documents, employee_salary_history,
--       employee_page_access, clients, client_social_links, client_employee_assignments,
--       brand_assets, departments, department_weekly_schedule, leave_types,
--       portal_settings, portal_schema_versions

SET FOREIGN_KEY_CHECKS = 0;

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

-- Login tokens and attempts (everyone signs in again)
TRUNCATE TABLE login_attempts;
TRUNCATE TABLE portal_login_tokens;
TRUNCATE TABLE password_reset_tokens;

SET FOREIGN_KEY_CHECKS = 1;

UPDATE users SET last_seen_at = NULL;
