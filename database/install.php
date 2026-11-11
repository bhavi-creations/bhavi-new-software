<?php
declare(strict_types=1);

const PORTAL_SCHEMA_VERSION = 34;

function portal_ensure_schema(PDO $pdo, string $database): void
{
    try {
        $version = (int) $pdo->query('SELECT MAX(version) FROM portal_schema_versions')->fetchColumn();
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1146) {
            throw $e;
        }
        $version = 0;
    }
    if ($version < PORTAL_SCHEMA_VERSION) {
        portal_install($pdo, $database);
    }
}

function portal_install_view(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // Application pages use their own prepared queries. Export/reporting
        // views are optional when the hosting account cannot create views.
        if (!in_array((int) ($e->errorInfo[1] ?? 0), [1044, 1142, 1143, 1227], true)) {
            throw $e;
        }
        error_log('Optional database view skipped: ' . $e->getMessage());
    }
}

function portal_install(PDO $pdo, string $database): void
{
    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $database)) {
        throw new RuntimeException('Invalid database name.');
    }
    $lock = 'bhavi_schema_' . substr(hash('sha256', $database), 0, 40);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $stmt->execute([$lock]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Database setup is busy. Please try again.');
    }
    try {
        try {
            // Hosting accounts normally use a database created in the panel.
            $pdo->exec("USE `$database`");
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1049) {
                throw $e;
            }
            $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$database`");
        }
        // Repair an interrupted base import too; existing tables and records
        // are preserved, regardless of whether the version table exists.
        $sql = file_get_contents(__DIR__ . '/Bhavi_Team_Portal_Full_Database.sql');
        if ($sql === false) {
            throw new RuntimeException('The base database schema file is missing.');
        }
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS bhavi_team_portal\s+CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\s*;/i', '', $sql);
        $sql = preg_replace('/USE bhavi_team_portal\s*;/i', '', $sql);
        $sql = str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql);
        $sql = str_replace('CREATE VIEW ', 'CREATE OR REPLACE VIEW ', $sql);
        $sql = str_replace('INSERT INTO departments', 'INSERT IGNORE INTO departments', $sql);
        $sql = str_replace('INSERT INTO leave_types', 'INSERT IGNORE INTO leave_types', $sql);
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            // Create final views after all table migrations are applied.
            if ($statement !== '' && !preg_match('/^CREATE OR REPLACE VIEW\s/i', $statement)) {
                $pdo->exec($statement);
            }
        }
        $addColumn = static function (string $table, string $column, string $definition) use ($pdo): void {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            if (!(int) $stmt->fetchColumn()) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        };
        $addColumn('users', 'deleted_at', 'DATETIME NULL');
        $addColumn('clients', 'deleted_at', 'DATETIME NULL');
        $hadTaskFields = $pdo->query("SHOW COLUMNS FROM daily_work_entries LIKE 'task_title'")->fetch();
        $addColumn('daily_work_entries', 'assignment_id', 'BIGINT UNSIGNED NULL');
        $addColumn('daily_work_entries', 'task_title', 'VARCHAR(255) NULL');
        $addColumn('daily_work_entries', 'task_status', "ENUM('pending','completed') NOT NULL DEFAULT 'pending'");
        if (!$hadTaskFields) {
            $pdo->exec("UPDATE daily_work_entries SET task_title=COALESCE(NULLIF(website_page_task,''),NULLIF(seo_task,''),'Daily work'),task_status=CASE WHEN website_status='completed' OR seo_status='completed' THEN 'completed' ELSE 'pending' END");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS work_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id BIGINT UNSIGNED NOT NULL,
            department_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            work_date DATE NOT NULL,
            status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
            employee_remark TEXT NULL,
            assigned_by BIGINT UNSIGNED NOT NULL,
            deleted_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_assignment_employee_date (employee_id, work_date),
            FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id),
            FOREIGN KEY (department_id) REFERENCES departments(id),
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (assigned_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attempt_key CHAR(64) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempt (attempt_key, attempted_at)
        ) ENGINE=InnoDB");
        if (!$pdo->query("SHOW INDEX FROM daily_work_entries WHERE Key_name='uq_sheet_assignment'")->fetch()) {
            $pdo->exec('ALTER TABLE daily_work_entries ADD UNIQUE KEY uq_sheet_assignment (submission_id,assignment_id)');
        }
        $foreignKey = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='daily_work_entries' AND COLUMN_NAME='assignment_id' AND REFERENCED_TABLE_NAME='work_assignments'")->fetchColumn();
        if (!$foreignKey) {
            $pdo->exec('ALTER TABLE daily_work_entries ADD CONSTRAINT fk_entry_assignment FOREIGN KEY (assignment_id) REFERENCES work_assignments(id)');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_schema_versions (
            version INT PRIMARY KEY, installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS payslips (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id BIGINT UNSIGNED NOT NULL,
            pay_month DATE NOT NULL,
            file_content MEDIUMBLOB NOT NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payslip_employee_month (employee_id,pay_month),
            FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id),
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $addColumn('employee_profiles', 'role_title', "VARCHAR(150) NOT NULL DEFAULT 'Employee'");
        $pdo->exec('ALTER TABLE daily_work_entries MODIFY client_id BIGINT UNSIGNED NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id BIGINT UNSIGNED NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_content MEDIUMBLOB NOT NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_employee_documents (employee_id),
            FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id),
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (1),(2),(3)');
        $addColumn('employee_documents','file_path','VARCHAR(255) NULL');
        $pdo->exec('ALTER TABLE employee_documents MODIFY file_content MEDIUMBLOB NULL');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (4)');

        // Employee records and dated compensation snapshots.
        foreach (['employee_code'=>'VARCHAR(50) NULL','phone'=>'VARCHAR(30) NULL','guardian_phone'=>'VARCHAR(30) NULL','relieving_date'=>'DATE NULL','benefit_pf'=>'BOOLEAN NOT NULL DEFAULT 0','benefit_esi'=>'BOOLEAN NOT NULL DEFAULT 0','benefit_other'=>'BOOLEAN NOT NULL DEFAULT 0','other_benefits'=>"VARCHAR(255) NOT NULL DEFAULT ''",'esi_basis'=>"ENUM('gross','half') NOT NULL DEFAULT 'half'"] as $column=>$definition) $addColumn('employee_profiles',$column,$definition);
        if (!$pdo->query("SHOW INDEX FROM employee_profiles WHERE Key_name='uq_employee_code'")->fetch()) $pdo->exec('ALTER TABLE employee_profiles ADD UNIQUE KEY uq_employee_code (employee_code)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_salary_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL, effective_from DATE NOT NULL, effective_to DATE NULL,
            benefit_pf BOOLEAN NOT NULL, benefit_esi BOOLEAN NOT NULL, benefit_other BOOLEAN NOT NULL,
            other_benefits VARCHAR(255) NOT NULL DEFAULT '', esi_basis ENUM('gross','half') NOT NULL DEFAULT 'half',
            pf_base DECIMAL(12,2) NOT NULL, esi_base DECIMAL(12,2) NOT NULL,
            employee_pf DECIMAL(12,2) NOT NULL, company_pf DECIMAL(12,2) NOT NULL,
            employee_esi DECIMAL(12,2) NOT NULL, company_esi DECIMAL(12,2) NOT NULL,
            net_salary DECIMAL(12,2) NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_salary_start (employee_id,effective_from),
            FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id), FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        foreach (['phone'=>'VARCHAR(30) NULL','starting_date'=>'DATE NULL','ending_date'=>'DATE NULL','package'=>"VARCHAR(255) NOT NULL DEFAULT ''",'social_media_url'=>"VARCHAR(2048) NOT NULL DEFAULT ''",'gmb_url'=>"VARCHAR(2048) NOT NULL DEFAULT ''",'monthly_reels'=>'INT UNSIGNED NOT NULL DEFAULT 0','monthly_posters'=>'INT UNSIGNED NOT NULL DEFAULT 0','monthly_carousels'=>'INT UNSIGNED NOT NULL DEFAULT 0','payment_total'=>'DECIMAL(12,2) NOT NULL DEFAULT 0','paid_amount'=>'DECIMAL(12,2) NOT NULL DEFAULT 0','remaining_amount'=>'DECIMAL(12,2) GENERATED ALWAYS AS (payment_total-paid_amount) STORED'] as $column=>$definition) $addColumn('clients',$column,$definition);
        $pdo->exec("CREATE TABLE IF NOT EXISTS client_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED NOT NULL,
            payment_date DATE NOT NULL, amount DECIMAL(12,2) NOT NULL,
            request_key VARCHAR(80) NOT NULL UNIQUE, created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_client_payment (client_id,payment_date),
            FOREIGN KEY (client_id) REFERENCES clients(id), FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (5)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS client_social_links (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            platform_name VARCHAR(100) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_client_social_links (client_id),
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO client_social_links (client_id,platform_name,url)
            SELECT c.id,'Social media',c.social_media_url FROM clients c
            WHERE c.social_media_url<>'' AND NOT EXISTS (
                SELECT 1 FROM client_social_links l WHERE l.client_id=c.id AND l.url=c.social_media_url
            )");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (6)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_attendance_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id BIGINT UNSIGNED NOT NULL,
            login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            logout_at DATETIME NULL,
            active_employee_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN logout_at IS NULL THEN employee_id ELSE NULL END) STORED,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_active_employee_session (active_employee_id),
            INDEX idx_employee_attendance_history (employee_id,login_at),
            FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (7)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_attendance_days (
            employee_id BIGINT UNSIGNED NOT NULL,
            attendance_date DATE NOT NULL,
            status ENUM('pending','present','leave') NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (employee_id,attendance_date),
            FOREIGN KEY (employee_id) REFERENCES employee_profiles(user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (8)');
        $addColumn('work_assignments','time_spent_hours','DECIMAL(7,2) NOT NULL DEFAULT 0.00');
        $addColumn('daily_work_entries','time_spent_hours','DECIMAL(7,2) NULL');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (9)');
        $hasAssignmentMinutes=(bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='work_assignments' AND COLUMN_NAME='time_spent_minutes'")->fetchColumn();
        $hasEntryMinutes=(bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='daily_work_entries' AND COLUMN_NAME='time_spent_minutes'")->fetchColumn();
        $addColumn('work_assignments','time_spent_minutes','INT UNSIGNED NOT NULL DEFAULT 0');
        $addColumn('daily_work_entries','time_spent_minutes','INT UNSIGNED NULL');
        $addColumn('daily_work_entries','work_type',"ENUM('poster','video_editing','carousel') NULL");
        if (!$hasAssignmentMinutes) $pdo->exec('UPDATE work_assignments SET time_spent_minutes=ROUND(time_spent_hours*60)');
        if (!$hasEntryMinutes) $pdo->exec('UPDATE daily_work_entries SET time_spent_minutes=ROUND(time_spent_hours*60) WHERE time_spent_hours IS NOT NULL');
        portal_install_view($pdo, "CREATE OR REPLACE VIEW v_employee_totals AS SELECT COUNT(*) AS total_employees, COALESCE(SUM(account_status='active'),0) AS active_employees, COALESCE(SUM(account_status='inactive'),0) AS inactive_employees FROM users WHERE role='employee' AND deleted_at IS NULL");
        portal_install_view($pdo, 'CREATE OR REPLACE VIEW v_client_totals AS SELECT COUNT(*) AS total_clients,COALESCE(SUM(is_active=1),0) AS active_clients FROM clients WHERE deleted_at IS NULL');
        portal_install_view($pdo, "CREATE OR REPLACE VIEW v_employee_directory AS SELECT u.id,u.full_name,u.email,u.username,u.account_status,u.avatar_path,ep.designation,ep.joining_date,ep.manager_id,d.id AS department_id,d.code AS department_code,d.name AS department_name FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.deleted_at IS NULL");
        portal_install_view($pdo, 'CREATE OR REPLACE VIEW v_daily_work_export AS SELECT ROW_NUMBER() OVER (PARTITION BY s.id ORDER BY e.row_order,e.id) AS s_no,s.work_date,s.employee_id,u.full_name AS employee_name,d.name AS department_name,c.client_name,s.submission_status,s.submitted_at,s.review_status,s.manager_remark,e.* FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id JOIN departments d ON d.id=s.department_id JOIN daily_work_entries e ON e.submission_id=s.id LEFT JOIN clients c ON c.id=e.client_id');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (10)');
        $addColumn('employee_profiles','other_benefit_amount','DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        $addColumn('employee_salary_history','other_benefit_amount','DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (11)');
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('manager','admin','employee','client') NOT NULL");
        $addColumn('users','credential_cipher','TEXT NULL');
        $addColumn('clients','user_id','BIGINT UNSIGNED NULL UNIQUE');
        $addColumn('clients','client_type',"ENUM('premium','non-premium') NOT NULL DEFAULT 'non-premium'");
        $addColumn('client_social_links','username',"VARCHAR(255) NOT NULL DEFAULT ''");
        $addColumn('client_social_links','password_cipher','TEXT NULL');
        $addColumn('employee_profiles','pf_number',"VARCHAR(100) NOT NULL DEFAULT ''");
        $addColumn('employee_profiles','esi_number',"VARCHAR(100) NOT NULL DEFAULT ''");
        $pdo->exec("CREATE TABLE IF NOT EXISTS client_work_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED NOT NULL,
            report_date DATE NOT NULL, work_type ENUM('reel','poster','carousel','other') NOT NULL DEFAULT 'other',
            title VARCHAR(255) NOT NULL DEFAULT '', content_source ENUM('client','own') NULL,
            received_at DATETIME NULL, started_at DATETIME NULL, sent_at DATETIME NULL,
            approved_at DATETIME NULL, uploaded_at DATETIME NULL, platform VARCHAR(255) NOT NULL DEFAULT '',
            remarks TEXT NULL, created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_client_report_date (client_id,report_date),
            FOREIGN KEY (client_id) REFERENCES clients(id), FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (12)');
        $pdo->exec('ALTER TABLE work_assignments MODIFY client_id BIGINT UNSIGNED NULL');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (13)');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (14)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_login_tokens (
            selector CHAR(32) PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
            validator_hash CHAR(64) NOT NULL, password_fingerprint CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_token_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        // An unfinished previous day must retain its empty logout time without
        // blocking a fresh attendance login on the next calendar date.
        $addColumn('employee_attendance_sessions','active_attendance_date','DATE GENERATED ALWAYS AS (CASE WHEN logout_at IS NULL THEN DATE(login_at) ELSE NULL END) STORED');
        if (!$pdo->query("SHOW INDEX FROM employee_attendance_sessions WHERE Key_name='uq_attendance_open_day'")->fetch()) $pdo->exec('ALTER TABLE employee_attendance_sessions ADD UNIQUE KEY uq_attendance_open_day (employee_id,active_attendance_date)');
        if ($pdo->query("SHOW INDEX FROM employee_attendance_sessions WHERE Key_name='uq_active_employee_session'")->fetch()) $pdo->exec('ALTER TABLE employee_attendance_sessions DROP INDEX uq_active_employee_session');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (15)');
        $addColumn('clients','monthly_blogs','INT UNSIGNED NOT NULL DEFAULT 0');
        $addColumn('clients','monthly_work_remark','TEXT NULL');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (16)');
        $addColumn('clients','domain_provider','VARCHAR(100) NULL');
        $addColumn('clients','domain_username','VARCHAR(255) NULL');
        $addColumn('clients','domain_password_cipher','TEXT NULL');
        $addColumn('clients','domain_expiry','DATE NULL');
        $addColumn('clients','gmb_username','VARCHAR(255) NULL');
        $addColumn('clients','gmb_password_cipher','TEXT NULL');
        $addColumn('clients','gmb_access_granted','TINYINT(1) NOT NULL DEFAULT 0');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (17)');
        $addColumn('clients','domain_name','VARCHAR(255) NULL');
        $pdo->exec("INSERT IGNORE INTO leave_types (name) VALUES ('Permission')");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (18)');
        $addColumn('client_requirements','department_id','BIGINT UNSIGNED NULL');
        if (!$pdo->query("SHOW INDEX FROM client_requirements WHERE Key_name='idx_requirement_department' ")->fetch()) $pdo->exec('ALTER TABLE client_requirements ADD INDEX idx_requirement_department (department_id,due_date)');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (19)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_conversations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_type ENUM('group','private') NOT NULL,
            title VARCHAR(120) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_chat_conversation_updated (updated_at),
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_participants (
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            added_by BIGINT UNSIGNED NOT NULL,
            joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (conversation_id,user_id),
            INDEX idx_chat_participant_user (user_id,conversation_id),
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (added_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_chat_message_conversation (conversation_id,id),
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (20)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_attachments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id BIGINT UNSIGNED NOT NULL,
            original_file_name VARCHAR(255) NOT NULL,
            storage_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size_bytes BIGINT UNSIGNED NOT NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_chat_attachment_message (message_id),
            FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (21)');
        $addColumn('work_assignments','editing_type','VARCHAR(50) NULL');
        $addColumn('work_assignments','reference_link','TEXT NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS work_assignment_attachments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assignment_id BIGINT UNSIGNED NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_assignment_attachment_assignment (assignment_id),
            FOREIGN KEY (assignment_id) REFERENCES work_assignments(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (22)');
        $addColumn('client_work_reports','reviews_received','INT UNSIGNED NOT NULL DEFAULT 0');
        $addColumn('client_work_reports','replies_sent','INT UNSIGNED NOT NULL DEFAULT 0');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (23)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS todo_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(500) NOT NULL DEFAULT '',
            expected_date DATE NULL,
            end_date DATE NULL,
            remark TEXT NULL,
            status ENUM('open','completed') NOT NULL DEFAULT 'open',
            completed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_todo_user_status (user_id,status),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (24)');
        $addColumn('departments','head_user_id','BIGINT UNSIGNED NULL');
        $addColumn('departments','description',"VARCHAR(255) NOT NULL DEFAULT ''");
        $addColumn('departments','card_color',"VARCHAR(20) NOT NULL DEFAULT 'navy'");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (25)');
        foreach (['date_of_birth'=>'DATE NULL','gender'=>"VARCHAR(20) NOT NULL DEFAULT ''",'address'=>"VARCHAR(500) NOT NULL DEFAULT ''",'work_timings'=>"VARCHAR(100) NOT NULL DEFAULT ''",'employment_type'=>"VARCHAR(20) NOT NULL DEFAULT 'full_time'"] as $column=>$definition) $addColumn('employee_profiles',$column,$definition);
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (26)');
        $addColumn('clients','industry','VARCHAR(60) NOT NULL DEFAULT \'\'');
        $addColumn('clients','contact_person','VARCHAR(150) NOT NULL DEFAULT \'\'');
        $addColumn('clients','contact_designation','VARCHAR(150) NOT NULL DEFAULT \'\'');
        $addColumn('clients','whatsapp','VARCHAR(30) NOT NULL DEFAULT \'\'');
        $addColumn('clients','email','VARCHAR(254) NOT NULL DEFAULT \'\'');
        $addColumn('clients','city','VARCHAR(100) NOT NULL DEFAULT \'\'');
        $addColumn('clients','gst_number','VARCHAR(30) NOT NULL DEFAULT \'\'');
        $addColumn('clients','address','VARCHAR(500) NOT NULL DEFAULT \'\'');
        $addColumn('clients','services','TEXT NULL');
        $addColumn('clients','account_manager_id','BIGINT UNSIGNED NULL');
        $addColumn('clients','lead_department_id','BIGINT UNSIGNED NULL');
        $addColumn('clients','billing_cycle','VARCHAR(20) NOT NULL DEFAULT \'monthly\'');
        $addColumn('clients','team_notes','TEXT NULL');
        $addColumn('clients','client_status','VARCHAR(20) NOT NULL DEFAULT \'active\'');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (27)');
        $addColumn('work_assignments','priority',"VARCHAR(10) NOT NULL DEFAULT 'normal'");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (28)');
        $addColumn('employee_attendance_days','marked_status','VARCHAR(10) NULL');
        $addColumn('employee_attendance_days','marked_by','BIGINT UNSIGNED NULL');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (29)');
        $announcementsExisted = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='announcements'")->fetchColumn();
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            audience VARCHAR(255) NOT NULL DEFAULT 'All employees',
            recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
            sender_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_announcements_created (created_at)
        ) ENGINE=InnoDB");
        if (!$announcementsExisted) {
            // Carry over announcements that were sent before this table existed.
            $pdo->exec("INSERT INTO announcements (title,message,audience,recipient_count,sender_id,created_at)
                SELECT n.title,n.message,'Employees',COUNT(*),MIN(n.sender_id),MIN(n.created_at)
                FROM notifications n JOIN users sender ON sender.id=n.sender_id AND sender.role IN ('admin','manager')
                JOIN users recipient ON recipient.id=n.recipient_id AND recipient.role='employee'
                WHERE n.notification_type='general' AND n.title NOT LIKE '%task assigned' AND n.title NOT LIKE '%tasks assigned'
                  AND n.title NOT IN ('Work assignment updated','Reminder: task pending') AND n.title NOT LIKE '%submitted an assigned task update' AND n.title NOT LIKE 'Work report %'
                GROUP BY n.title,n.message,DATE(n.created_at)");
        }
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (30)');
        // Chat read receipts ("Seen"), unread counts and online presence.
        $addColumn('chat_participants','last_read_message_id','BIGINT UNSIGNED NOT NULL DEFAULT 0');
        $addColumn('users','last_seen_at','DATETIME NULL');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (31)');
        // Daily work: department-specific work types (Reel, Thumbnail, New page…) and an output link per entry.
        $pdo->exec('ALTER TABLE daily_work_entries MODIFY COLUMN work_type VARCHAR(40) NULL');
        $addColumn('daily_work_entries','output_link','VARCHAR(500) NULL');
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (32)');
        // Assigned work timer: start / pause / resume / finish. Older installs were missing the base timer columns.
        $hadResumeColumn = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='work_assignments' AND COLUMN_NAME='timer_resumed_at'")->fetchColumn();
        $addColumn('work_assignments','started_at','DATETIME NULL');
        $addColumn('work_assignments','completed_at','DATETIME NULL');
        $addColumn('work_assignments','time_spent_seconds','INT UNSIGNED NULL');
        $addColumn('work_assignments','timer_resumed_at','DATETIME NULL');
        $addColumn('work_assignments','timer_accumulated_seconds','INT UNSIGNED NOT NULL DEFAULT 0');
        if (!$hadResumeColumn) {
            // Timers that were already running keep running from their original start time.
            $pdo->exec('UPDATE work_assignments SET timer_resumed_at=started_at WHERE started_at IS NOT NULL AND completed_at IS NULL');
        }
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (33)');
        // Payslips up to 100 MB are kept as files in storage/payslips (older ones stay in file_content).
        $addColumn('payslips','file_path','VARCHAR(255) NULL');
        $pdo->exec('ALTER TABLE payslips MODIFY COLUMN file_content MEDIUMBLOB NULL');
        // Office timing set by admin (default for everyone) and optional per-employee shift (e.g. night shift).
        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_settings (
            setting_key VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT IGNORE INTO portal_settings (setting_key,setting_value) VALUES ('office_start','10:00'),('office_end','19:00')");
        $addColumn('employee_profiles','shift_start','TIME NULL');
        $addColumn('employee_profiles','shift_end','TIME NULL');
        $addColumn('employee_profiles','shift_label',"VARCHAR(60) NOT NULL DEFAULT ''");
        $pdo->exec('INSERT IGNORE INTO portal_schema_versions (version) VALUES (34)');
    } finally {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lock]);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require_once dirname(__DIR__) . '/includes/database.php';
    $config = require dirname(__DIR__) . '/config.php';
    $pdo = portal_connect($config, false);
    portal_install($pdo, $config['db_name']);
    echo 'Database ready (schema version ' . PORTAL_SCHEMA_VERSION . "). Existing records preserved. Open the portal to sign in, or setup.php for a new installation.\n";
}
