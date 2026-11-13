<?php
// Page access: the Managing Director can give an employee access to Managing Director pages
// (e.g. Client work report). On a page they were given, the employee works with the same rights
// as the Managing Director; everywhere else they stay an employee.

/** Pages that can be given, as key => [label, main page, every file that page needs]. */
function page_access_features(): array
{
    return [
        'employees' => ['Employees', 'admin-employees.php', ['admin-employees.php', 'admin-add-employee.php', 'employee-profile.php', 'employee-photo.php', 'download-employee-document.php']],
        'attendance' => ['Attendance', 'employee-attendance.php', ['employee-attendance.php', 'download-attendance.php']],
        'attendance_approvals' => ['Attendance approvals', 'attendance-approvals.php', ['attendance-approvals.php']],
        'salary' => ['Salary', 'employee-salary.php', ['employee-salary.php']],
        'daily_work_report' => ['Daily work report', 'manager-dailywork.php', ['manager-dailywork.php', 'download-work.php', 'manager-work-details.php', 'manager-review-work.php']],
        'client_work_report' => ['Client work report', 'admin-client-work-report.php', ['admin-client-work-report.php', 'download-client-report.php']],
        'tasks' => ['Tasks (assign work)', 'manager-assign-work.php', ['manager-assign-work.php', 'manager-assigned-status.php', 'download-assignments.php', 'assignment-file.php', 'admin-all-work.php']],
        'clients' => ['Projects & clients', 'add-client.php', ['add-client.php', 'admin-add-client.php', 'client-profile.php', 'client-logo.php', 'client-reveal-password.php', 'download-asset.php', 'download-all-logos.php']],
        'vendors' => ['Vendors', 'vendors.php', ['vendors.php']],
        'leave_requests' => ['Leave requests', 'manager-leave-requist.php', ['manager-leave-requist.php']],
        'office_timings' => ['Office timings', 'office-timings.php', ['office-timings.php']],
        'announcements' => ['Announcements', 'manager-notification.php', ['manager-notification.php']],
        'reports' => ['Reports & analytics', 'manager-monthly-design-report.php', ['manager-monthly-design-report.php']],
        'wishes' => ['Wishes', 'wishes.php', ['wishes.php', 'wish-poster.php']],
        'tools' => ['Tools / Apps (passwords)', 'tools.php', ['tools.php']],
        'references' => ['References', 'references.php', ['references.php']],
        'departments' => ['Departments', 'departments.php', ['departments.php']],
        'client_requests' => ['Client requests', 'client-reuirement.php', ['client-reuirement.php', 'download-requirement-file.php']],
        'payslips' => ['Employee payslips', 'payslips.php', ['payslips.php', 'download-payslip.php']],
        'holidays' => ['Holidays', 'admin-holidays.php', ['admin-holidays.php']],
    ];
}

/** Feature keys given to one employee. */
function employee_access_list(int $userId): array
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        try {
            $stmt = db()->prepare('SELECT feature FROM employee_page_access WHERE user_id=?');
            $stmt->execute([$userId]);
            $cache[$userId] = array_values(array_intersect($stmt->fetchAll(PDO::FETCH_COLUMN), array_keys(page_access_features())));
        } catch (Throwable $e) {
            $cache[$userId] = []; // table not created yet
        }
    }
    return $cache[$userId];
}

/** Feature key that the current request's page belongs to and the employee was given, or null. */
function page_access_for_request(int $userId): ?string
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') return null;
    $features = page_access_features();
    foreach (employee_access_list($userId) as $key) {
        if (in_array($script, $features[$key][2], true)) return $key;
    }
    return null;
}
