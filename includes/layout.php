<?php
require_once __DIR__ . '/bootstrap.php';

function icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'people' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V3h8v4M3 12a23 23 0 0 0 18 0M12 11v4"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18m-13 4h2m4 0h2"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4m-4 5v2"/>',
        'document' => '<path d="M14 3H5v18h14V8Zm0 0v5h5M8 12h8m-8 4h6"/>',
        'logout' => '<path d="M9 3H4v18h5m5-14 5 5-5 5M9 12h12"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'edit' => '<path d="m16 3 5 5-12 12-6 1 1-6L16 3Z"/><path d="m14 5 5 5"/>',
        'delete' => '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
    ];
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
function page_start(string $title, string $active = ''): void
{
    $user = require_roles(['admin','manager','employee','client']);
    $dashboard = dashboard_path($user);
    $nav = [$dashboard => 'Dashboard', 'admin-employees.php' => is_staff()?'Employees':'My details', 'add-client.php' => 'Clients'];
    if ($user['role'] === 'admin') { $nav['admin-add-employee.php'] = 'Add employee'; }
    if (is_staff()) {
        $nav += ['admin-client-work-report.php' => 'Client work report', 'manager-assign-work.php' => 'Assign work', 'manager-dailywork.php' => 'Daily work reports', 'manager-monthly-design-report.php' => 'Monthly design report', 'manager-assigned-status.php' => 'Assigned work status'];
    } else {
        $nav += ['employee-brands-assets.php' => 'Brand assets', 'client-reuirement.php' => 'Client requirements', 'apply-leaves.php' => 'Apply leave', 'my-leave-requests.php' => 'My leave requests', 'check-leave.php' => 'My leave calendar'];
    }
    if (!is_staff()) {
        $nav['employee-daily-work.php'] = 'Update daily work';
        $nav['employee-assigned-work.php'] = 'My assigned work';
    }
    if ($user['role']==='admin') $nav['departments.php']='Departments';
    if ($user['role']==='admin') $nav['employee-attendance.php']='Employee attendance';
    if (is_staff()) $nav['manager-leave-requist.php']='Leave requests';
    if (!is_staff()) $nav['employee-work-history.php']='My submitted work';
    if (!is_staff()) $nav['employee-profile.php']='My profile & salary';
    $nav['payslips.php'] = is_staff() ? 'Employee payslips' : 'My payslips';
    $nav['admin-holidays.php'] = 'Holidays';
    $unread=count_value('SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND read_at IS NULL',[$user['id']]);
    $nav['manager-notification.php'] = 'Notifications'.($unread?' ('.$unread.')':'');
    $nav['change-password.php'] = 'Change password';
    if ($user['role']==='client') $nav=['client-dashboard.php'=>'Dashboard','client-details.php'=>'Details','client-payments.php'=>'Payments','client-reports.php'=>'Reports','change-password.php'=>'Change password'];
    $attendance = null;
    if ($user['role'] === 'employee') {
        $attendance = one('SELECT id,login_at FROM employee_attendance_sessions WHERE employee_id=? AND logout_at IS NULL AND login_at>=CURDATE() AND login_at<CURDATE()+INTERVAL 1 DAY ORDER BY login_at DESC LIMIT 1',[$user['id']]);
    }
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($title) ?> | Bhavi</title><link rel="stylesheet" href="assets/css/portal.css?v=<?= filemtime(__DIR__.'/../assets/css/portal.css') ?>"><?php if ($user['role']==='client'): ?><link rel="stylesheet" href="assets/css/client-portal.css?v=<?= filemtime(__DIR__.'/../assets/css/client-portal.css') ?>"><?php endif; ?></head>
<body data-role="<?= h($user['role']) ?>"><div class="portal-layout">
<aside class="portal-sidebar" id="portalSidebar"><a class="portal-brand" href="<?= h($dashboard) ?>"><span class="brand-icon">B</span><span><strong>bhavi</strong><small>TEAM WORKSPACE</small></span></a>
<nav aria-label="Main navigation"><?php foreach ($nav as $href => $label): ?><a href="<?= h($href) ?>" class="<?= $active === $href ? 'active' : '' ?>" <?= $active === $href ? 'aria-current="page"' : '' ?>><?= icon(str_contains($href,'dashboard')?'dashboard':(str_contains($href,'employee') && !str_contains($href,'work') && !str_contains($href,'brands')?'people':(str_contains($href,'notification')?'bell':(str_contains($href,'password')?'lock':(str_contains($href,'leave') || str_contains($href,'holiday')?'calendar':(str_contains($href,'client') || str_contains($href,'department')?'briefcase':'document')))))) ?><span><?= h($label) ?></span></a><?php endforeach; ?></nav>
<?php if ($user['role']==='employee'): ?><form class="attendance-control" data-attendance-date="<?= today() ?>" method="post" action="attendance.php"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $attendance?'logout':'login' ?>"><?php if ($attendance): ?><p>Login saved at <time><?= h(date('g:i A',strtotime($attendance['login_at']))) ?></time></p><button class="attendance-button" type="submit"><?= icon('logout') ?>Logout</button><?php else: ?><button class="attendance-button" type="submit"><?= icon('document') ?>Login</button><?php endif; ?></form><?php endif; ?>
<form class="signout" method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?>Sign out</button></form></aside>
<div class="portal-main"><header class="portal-topbar"><div class="topbar-left"><button type="button" class="menu-toggle" aria-label="Toggle menu" aria-controls="portalSidebar" aria-expanded="false">☰</button><span>Workspace <span class="muted">/ <?= h($title) ?></span></span></div><div class="topbar-account"><time datetime="<?= today() ?>"><?= date('d M Y') ?></time><div class="account-name"><strong><?= h($user['full_name']) ?></strong><small><?= h($user['department_name'] ?? 'Team workspace') ?></small></div><span class="badge role-badge"><?= h(ucfirst($user['role'])) ?></span></div></header>
<main class="portal-content"><div class="page-heading"><h1><?= h($title) ?></h1></div>
<?php foreach ($_SESSION['flash'] ?? [] as $message): ?><div class="alert <?= h($message['type']) ?>" role="status"><?= h($message['message']) ?></div><?php endforeach; unset($_SESSION['flash']);
}
function page_end(): void
{
    ?></main><footer class="portal-footer">Bhavi Creations · Team workspace</footer></div></div><script src="assets/js/portal.js?v=<?= filemtime(__DIR__.'/../assets/js/portal.js') ?>" defer></script></body></html><?php
}
function error_message(?string $error): void { if ($error): ?><div class="alert error" role="alert"><?= h($error) ?></div><?php endif; }
function empty_row(int $columns, string $message = 'No records yet.'): void { echo '<tr><td colspan="' . $columns . '" class="empty">' . h($message) . '</td></tr>'; }
function status_badge(string $status): string { return '<span class="badge status-' . h($status) . '">' . h(ucfirst(str_replace('_', ' ', $status))) . '</span>'; }
function detail_button(string $id): void { ?><button class="icon-button" type="button" data-dialog="<?= h($id) ?>" title="View details" aria-label="View details"><?= icon('eye') ?></button><?php }
function detail_dialog(string $id, string $title, array $fields): void
{
    ?><dialog id="<?= h($id) ?>" class="detail-dialog"><div class="dialog-heading"><h2><?= h($title) ?></h2><button type="button" data-close-dialog aria-label="Close details">×</button></div><dl class="details-list"><?php foreach ($fields as $label => $value): ?><dt><?= h($label) ?></dt><dd><?= h($value ?: '—') ?></dd><?php endforeach; ?></dl></dialog><?php
}
function delete_button(int $id, string $action, string $message): void
{
    $class=$action==='delete_assignment'?'inline-form assignment-delete':'inline-form';
    ?><form method="post" class="<?= h($class) ?>" data-confirm="<?= h($message) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="<?= h($action) ?>"><input type="hidden" name="id" value="<?= $id ?>"><button type="submit" class="icon-button danger" title="Delete" aria-label="Delete"><?= icon('delete') ?></button></form><?php
}
function mutation_error(Throwable $e): string
{
    if ($e instanceof InvalidArgumentException || $e instanceof CredentialStorageException) { return $e->getMessage(); }
    error_log($e->getMessage());
    if ($e instanceof PDOException) {
        $code=(int)($e->errorInfo[1]??0);
        if ($code===1062) return 'This username, email or employee ID is already in use. Please choose another.';
        if (in_array($code,[1054,1146,1364],true)) return 'Hosting database schema is out of date or incomplete. Upload the matching database/install.php and run the database update through setup.php before retrying.';
        if (in_array($code,[1044,1045,1142,1143],true)) return 'Hosting database permission denied. Give the configured database user access to the portal tables and retry.';
        if (in_array($code,[1205,1213],true)) return 'Another request is updating this record. Please retry saving.';
        if (in_array($code,[1114,1021],true)) return 'Hosting database storage is full. Ask hosting support to check the database quota and disk space.';
    }
    return 'Unable to save this change. Please check the PHP error log for this request, or contact the administrator.';
}
