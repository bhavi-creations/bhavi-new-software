<?php
declare(strict_types=1);

/*
 * Clears all working data (attendance, tasks, daily work, chat, payments, leaves,
 * notifications, payslips, todos, wishes, vendors, ...) and keeps only:
 *   - users / employees / clients and their master details
 *   - departments, leave types, office timings and the schema version
 * Table structure is not touched, so every page keeps working with empty data.
 *
 * Run from the project folder (take a database backup first):
 *   php database/reset-data.php           -> shows what will be kept / cleared
 *   php database/reset-data.php --yes     -> clears the data
 * On hosting: BHAVI_APP_ENV=live php database/reset-data.php --yes
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Tables whose rows are kept. Every other table in the database is emptied.
const RESET_KEEP_TABLES = [
    // Employees and logins (admin, manager, employees, clients)
    'users',
    'employee_profiles',
    'employee_documents',
    'employee_salary_history',
    'employee_page_access',
    // Clients
    'clients',
    'client_social_links',
    'client_employee_assignments',
    'brand_assets',
    // Settings the portal needs to work
    'departments',
    'department_weekly_schedule',
    'leave_types',
    'portal_settings',
    'portal_schema_versions',
];

// Upload folders used only by the cleared tables; their files are removed too.
const RESET_FILE_DIRS = [
    'storage/chat',
    'storage/payslips',
    'storage/requirements',
    'uploads/work-assignments',
    'uploads/wishes',
];

require_once dirname(__DIR__) . '/includes/database.php';
require_once __DIR__ . '/install.php';
$config = require dirname(__DIR__) . '/config.php';
$pdo = portal_connect($config, false);
// Make sure every table exists before deciding what to clear.
portal_install($pdo, $config['db_name']);
$pdo->exec('USE `' . $config['db_name'] . '`');

$tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$clear = array_values(array_diff($tables, RESET_KEEP_TABLES));
$count = static fn (string $t): int => (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();

echo "Database: {$config['db_name']}\n\nKeep:\n";
foreach (array_intersect($tables, RESET_KEEP_TABLES) as $t) {
    printf("  %-34s %d rows\n", $t, $count($t));
}
echo "\nClear:\n";
foreach ($clear as $t) {
    printf("  %-34s %d rows\n", $t, $count($t));
}

if (!in_array('--yes', $argv, true)) {
    echo "\nNothing changed. Take a backup, then run again with --yes to clear the tables above.\n";
    exit(0);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
try {
    foreach ($clear as $t) {
        $pdo->exec("TRUNCATE TABLE `$t`");
    }
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

// Reset per-user "live" fields that point at cleared data.
$pdo->exec('UPDATE users SET last_seen_at=NULL');

$removed = 0;
foreach (RESET_FILE_DIRS as $dir) {
    $path = dirname(__DIR__) . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $name = $file->getFilename();
        // Keep folder protection files (.htaccess, index.html, ...).
        if ($name[0] === '.' || preg_match('/^index\./i', $name)) {
            continue;
        }
        if ($file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname())) {
            $removed++;
        }
    }
}

echo "\nDone. Cleared " . count($clear) . " tables and removed $removed uploaded files. Employees and clients are kept.\n";
