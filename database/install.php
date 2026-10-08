<?php
declare(strict_types=1);

function portal_install(PDO $pdo, string $database): void
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $database)) {
        throw new RuntimeException('Invalid database name.');
    }
    $lock = 'bhavi_schema_' . $database;
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $stmt->execute([$lock]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Database setup is busy. Please try again.');
    }
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$database`");
        $exists = $pdo->query("SHOW TABLES LIKE 'portal_schema_versions'")->fetchColumn();
        if (!$exists) {
            $sql = file_get_contents(__DIR__ . '/Bhavi_Team_Portal_Full_Database.sql');
            $sql = preg_replace('/^--.*$/m', '', $sql);
            $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS bhavi_team_portal\s+CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\s*;/i', '', $sql);
            $sql = preg_replace('/USE bhavi_team_portal\s*;/i', '', $sql);
            $sql = str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql);
            $sql = str_replace('CREATE VIEW ', 'CREATE OR REPLACE VIEW ', $sql);
            $sql = str_replace('INSERT INTO departments', 'INSERT IGNORE INTO departments', $sql);
            $sql = str_replace('INSERT INTO leave_types', 'INSERT IGNORE INTO leave_types', $sql);
            foreach (explode(';', $sql) as $statement) {
                if (trim($statement) !== '') {
                    $pdo->exec($statement);
                }
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
        $pdo->exec("CREATE OR REPLACE VIEW v_employee_totals AS SELECT COUNT(*) AS total_employees, COALESCE(SUM(account_status='active'),0) AS active_employees, COALESCE(SUM(account_status='inactive'),0) AS inactive_employees FROM users WHERE role='employee' AND deleted_at IS NULL");
        $pdo->exec('CREATE OR REPLACE VIEW v_client_totals AS SELECT COUNT(*) AS total_clients,COALESCE(SUM(is_active=1),0) AS active_clients FROM clients WHERE deleted_at IS NULL');
        $pdo->exec("CREATE OR REPLACE VIEW v_employee_directory AS SELECT u.id,u.full_name,u.email,u.username,u.account_status,u.avatar_path,ep.designation,ep.joining_date,ep.manager_id,d.id AS department_id,d.code AS department_code,d.name AS department_name FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.deleted_at IS NULL");
        $pdo->exec('CREATE OR REPLACE VIEW v_daily_work_export AS SELECT ROW_NUMBER() OVER (PARTITION BY s.id ORDER BY e.row_order,e.id) AS s_no,s.work_date,s.employee_id,u.full_name AS employee_name,d.name AS department_name,c.client_name,s.submission_status,s.submitted_at,s.review_status,s.manager_remark,e.* FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id JOIN departments d ON d.id=s.department_id JOIN daily_work_entries e ON e.submission_id=s.id JOIN clients c ON c.id=e.client_id');
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
        $pdo->exec('CREATE OR REPLACE VIEW v_daily_work_export AS SELECT ROW_NUMBER() OVER (PARTITION BY s.id ORDER BY e.row_order,e.id) AS s_no,s.work_date,s.employee_id,u.full_name AS employee_name,d.name AS department_name,c.client_name,s.submission_status,s.submitted_at,s.review_status,s.manager_remark,e.* FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id JOIN departments d ON d.id=s.department_id JOIN daily_work_entries e ON e.submission_id=s.id LEFT JOIN clients c ON c.id=e.client_id');
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
    } finally {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lock]);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $config = require dirname(__DIR__) . '/config.php';
    $pdo = new PDO("mysql:host={$config['db_host']};port={$config['db_port']};charset=utf8mb4", $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    portal_install($pdo, $config['db_name']);
    echo "Database ready. Open setup.php to create your administrator and manager accounts.\n";
}
