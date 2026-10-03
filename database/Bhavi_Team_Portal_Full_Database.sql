-- Bhavi Team Portal: complete UI schema, MySQL 8.0.16+.
-- Fresh-install schema, NOT a migration for an existing database.
-- Import using phpMyAdmin or mysql. No real accounts or passwords are seeded.
-- In PHP use prepared statements, password_hash/password_verify and role checks.
-- All counts, permissions, notifications and uploads need application handlers.
-- Set application dates to Asia/Kolkata; set DB connection time_zone='+05:30'.

CREATE DATABASE IF NOT EXISTS bhavi_team_portal
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bhavi_team_portal;
SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE departments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL UNIQUE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

INSERT INTO departments (code, name) VALUES
  ('design_video', 'Design & video'), ('website', 'Website'),
  ('seo', 'SEO'), ('telecaller', 'Telecaller'), ('social_media', 'Social media');

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(254) NULL UNIQUE,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('manager','admin','employee') NOT NULL,
  account_status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  avatar_path VARCHAR(1024) NULL,
  must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
  last_login_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role_status (role, account_status),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE employee_profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  department_id BIGINT UNSIGNED NOT NULL,
  designation VARCHAR(150) NOT NULL,
  joining_date DATE NOT NULL,
  manager_id BIGINT UNSIGNED NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
  FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_name VARCHAR(150) NOT NULL,
  website_url VARCHAR(2048) NOT NULL,
  logo_path VARCHAR(1024) NOT NULL,
  logo_original_name VARCHAR(255) NULL,
  logo_mime_type VARCHAR(100) NULL,
  logo_size_bytes BIGINT UNSIGNED NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_clients_name (client_name),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE client_employee_assignments (
  client_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id, employee_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
  FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id) ON DELETE RESTRICT,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Stores Logo, Intro, Outro and PSD brand assets; uploaded file bytes live on disk/storage.
CREATE TABLE brand_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  asset_type ENUM('logo','intro','outro','psd','other') NOT NULL,
  title VARCHAR(200) NOT NULL,
  original_file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(1024) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_assets_client_type (client_id, asset_type),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE client_requirements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  brief TEXT NOT NULL,
  approved_text TEXT NULL,
  due_date DATE NULL,
  status ENUM('assigned','in_progress','completed','cancelled') NOT NULL DEFAULT 'assigned',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_requirements_client_due (client_id, due_date),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE requirement_assignments (
  requirement_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (requirement_id, employee_id),
  FOREIGN KEY (requirement_id) REFERENCES client_requirements(id) ON DELETE RESTRICT,
  FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id) ON DELETE RESTRICT,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- JPEG/reference image, MP4/video, TXT/approved text, and other attachments.
CREATE TABLE requirement_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requirement_id BIGINT UNSIGNED NOT NULL,
  file_type ENUM('image','video','text','other') NOT NULL,
  description VARCHAR(255) NULL,
  original_file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(1024) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (requirement_id) REFERENCES client_requirements(id) ON DELETE RESTRICT,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- One sheet per employee/day. Department is a snapshot for historic reports.
CREATE TABLE daily_work_submissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  department_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL DEFAULT (CURRENT_DATE),
  submission_status ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
  submitted_at DATETIME NULL,
  review_status ENUM('pending','reviewed','changes_requested') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  manager_remark TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employee_day (employee_id, work_date),
  INDEX idx_work_date_department (work_date, department_id, submission_status),
  FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id) ON DELETE RESTRICT,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (submission_status <> 'submitted' OR submitted_at IS NOT NULL)
) ENGINE=InnoDB;

-- One row per client/task. Common fields link each row to the employee's dated sheet.
-- Only populate department-appropriate fields; others remain NULL.
CREATE TABLE daily_work_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  submission_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  requirement_id BIGINT UNSIGNED NULL,
  row_order INT UNSIGNED NOT NULL,
  remark TEXT NULL,

  -- Designer: Video, Poster, Carousel, Changes, Remark.
  video_count INT UNSIGNED NULL,
  poster_count INT UNSIGNED NULL,
  carousel_count INT UNSIGNED NULL,
  design_changes_count INT UNSIGNED NULL,

  -- Website: Page/task, New, Changes, Status, Remark.
  website_page_task VARCHAR(255) NULL,
  website_new_count INT UNSIGNED NULL,
  website_changes_count INT UNSIGNED NULL,
  website_status ENUM('not_started','in_progress','completed') NULL,

  -- SEO: Task, Quantity (e.g. 3 pages / 1 post), Status, Remark.
  seo_task VARCHAR(255) NULL,
  seo_quantity DECIMAL(10,2) UNSIGNED NULL,
  seo_quantity_unit VARCHAR(50) NULL,
  seo_status ENUM('not_started','in_progress','completed') NULL,

  -- Telecaller: Calls, Connected, Follow-ups, Leads, Remark.
  calls_count INT UNSIGNED NULL,
  connected_count INT UNSIGNED NULL,
  followups_count INT UNSIGNED NULL,
  leads_count INT UNSIGNED NULL,

  -- Social media: Platform, Posts, Reels, Replies, Remark.
  social_platform VARCHAR(100) NULL,
  posts_count INT UNSIGNED NULL,
  reels_count INT UNSIGNED NULL,
  replies_count INT UNSIGNED NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sheet_row (submission_id, row_order),
  INDEX idx_entries_client (client_id),
  FOREIGN KEY (submission_id) REFERENCES daily_work_submissions(id) ON DELETE RESTRICT,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
  FOREIGN KEY (requirement_id) REFERENCES client_requirements(id) ON DELETE RESTRICT,
  CHECK (row_order > 0),
  CHECK (connected_count IS NULL OR calls_count IS NULL OR connected_count <= calls_count)
) ENGINE=InnoDB;

CREATE TABLE leave_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;
INSERT INTO leave_types (name) VALUES ('Personal'), ('Sick'), ('Casual'), ('Other');

-- Calendar shows days BETWEEN from_date AND to_date, with approval status.
-- working_days must be calculated by PHP excluding holidays/nonworking days.
CREATE TABLE leave_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  leave_type_id BIGINT UNSIGNED NOT NULL,
  from_date DATE NOT NULL,
  to_date DATE NOT NULL,
  working_days DECIMAL(6,2) UNSIGNED NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  decision_note TEXT NULL,
  decided_by BIGINT UNSIGNED NULL,
  decided_at DATETIME NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_leave_employee_dates (employee_id, from_date, to_date),
  INDEX idx_leave_status_dates (status, from_date, to_date),
  FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id) ON DELETE RESTRICT,
  FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT,
  FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (to_date >= from_date),
  CHECK (working_days > 0),
  CHECK (status NOT IN ('approved','rejected') OR (decided_by IS NOT NULL AND decided_at IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE holidays (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_name VARCHAR(150) NOT NULL,
  holiday_date DATE NOT NULL UNIQUE,
  description TEXT NULL,
  is_published BOOLEAN NOT NULL DEFAULT TRUE,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Supports hybrid department schedules; ISO weekday 1=Mon through 7=Sun.
CREATE TABLE department_weekly_schedule (
  department_id BIGINT UNSIGNED NOT NULL,
  iso_weekday TINYINT UNSIGNED NOT NULL,
  is_working_day BOOLEAN NOT NULL DEFAULT TRUE,
  work_mode ENUM('office','remote','off') NOT NULL DEFAULT 'office',
  PRIMARY KEY (department_id, iso_weekday),
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
  CHECK (iso_weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NULL,
  notification_type ENUM('work_submitted','leave_applied','leave_decision','holiday','requirement','general') NOT NULL,
  title VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  submission_id BIGINT UNSIGNED NULL,
  leave_request_id BIGINT UNSIGNED NULL,
  holiday_id BIGINT UNSIGNED NULL,
  requirement_id BIGINT UNSIGNED NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_recipient (recipient_id, read_at, created_at),
  FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (submission_id) REFERENCES daily_work_submissions(id) ON DELETE RESTRICT,
  FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE RESTRICT,
  FOREIGN KEY (holiday_id) REFERENCES holidays(id) ON DELETE RESTRICT,
  FOREIGN KEY (requirement_id) REFERENCES client_requirements(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Auto employee/client dashboard counts: no manually maintained counter columns.
CREATE VIEW v_employee_totals AS
SELECT COUNT(*) AS total_employees,
       COALESCE(SUM(account_status = 'active'), 0) AS active_employees,
       COALESCE(SUM(account_status = 'inactive'), 0) AS inactive_employees
FROM users WHERE role = 'employee';

CREATE VIEW v_client_totals AS
SELECT COUNT(*) AS total_clients,
       COALESCE(SUM(is_active = TRUE), 0) AS active_clients
FROM clients;

CREATE VIEW v_employee_directory AS
SELECT u.id, u.full_name, u.email, u.username, u.account_status,
       u.avatar_path, ep.designation, ep.joining_date, ep.manager_id,
       d.id AS department_id, d.code AS department_code, d.name AS department_name
FROM users u
JOIN employee_profiles ep ON ep.user_id = u.id
JOIN departments d ON d.id = ep.department_id
WHERE u.role = 'employee';

-- Day-wise export includes S.no, date, employee, department, client and ALL work fields.
CREATE VIEW v_daily_work_export AS
SELECT ROW_NUMBER() OVER (
         PARTITION BY s.id ORDER BY e.row_order, e.id
       ) AS s_no,
       s.work_date, s.employee_id, u.full_name AS employee_name,
       d.name AS department_name, c.client_name,
       s.submission_status, s.submitted_at, s.review_status,
       s.manager_remark, e.*
FROM daily_work_submissions s
JOIN users u ON u.id = s.employee_id
JOIN departments d ON d.id = s.department_id
JOIN daily_work_entries e ON e.submission_id = s.id
JOIN clients c ON c.id = e.client_id;

-- Manager daily counts include employees with no submission on that day.
-- Run with a prepared :work_date parameter in the application:
-- SELECT u.id, u.full_name, d.name AS department_name,
--        COALESCE(s.submission_status, 'not_submitted') AS daily_status,
--        COUNT(e.id) AS entry_count
-- FROM users u
-- JOIN employee_profiles ep ON ep.user_id = u.id
-- JOIN departments d ON d.id = ep.department_id
-- LEFT JOIN daily_work_submissions s
--   ON s.employee_id = u.id AND s.work_date = :work_date
-- LEFT JOIN daily_work_entries e ON e.submission_id = s.id
-- WHERE u.role = 'employee' AND u.account_status = 'active'
-- GROUP BY u.id, u.full_name, d.name, s.submission_status;

-- Employee check-leave calendar, scoped to the signed-in employee:
-- SELECT * FROM leave_requests WHERE employee_id = :employee_id
--   AND from_date <= :month_end AND to_date >= :month_start;
-- SELECT * FROM holidays WHERE is_published = TRUE
--   AND holiday_date BETWEEN :month_start AND :month_end;

-- Atomic Submit-to-Sir flow belongs in PHP:
-- 1. Begin transaction; verify current user owns sheet and validate all entries.
-- 2. Update sheet to submitted, set submitted_at=NOW().
-- 3. Insert work_submitted notification for employee's manager (or chosen manager).
-- 4. Commit; send any email/WhatsApp separately if configured.
-- SQL alone does not send external messages or create an Excel download.
