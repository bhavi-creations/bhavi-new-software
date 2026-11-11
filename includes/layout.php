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
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'tasks' => '<path d="M10 6h11M10 12h11M10 18h11M3 6l1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17"/>',
        'folder' => '<path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
        'megaphone' => '<path d="M3 10v4h4l7 4V6L7 10H3Zm14-1a4 4 0 0 1 0 6"/>',
        'chart' => '<path d="M3 21h18M6 17v-5m5 5V7m5 10v-8m4 8V4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
        'chat' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/>',
        'money' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 10v4m12-4v4"/>',
        'inbox' => '<path d="M22 13h-6l-2 3h-4l-2-3H2M5 5h14l3 8v6H2v-6Z"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'pen' => '<path d="m12 19 7-7 3 3-7 7-3-3Z"/><path d="m18 13-1.5-7.5L2 2l3.5 14.5L13 18l5-5ZM2 2l7.6 7.6"/><circle cx="11" cy="11" r="2"/>',
        'video' => '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="m16 10 6-3v10l-6-3"/>',
        'code' => '<path d="m8 8-5 4 5 4m8-8 5 4-5 4M14 4l-4 16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'printer' => '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2Z"/>',
        'more' => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
    ];
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
function page_start(string $title, string $active = ''): void
{
    $user = require_roles(['admin','manager','employee','client']);
    $dashboard = dashboard_path($user);
    if ($user['role'] === 'admin') {
        $nav = [$dashboard => 'Dashboard', 'departments.php' => 'Department', 'admin-employees.php' => 'Employee', 'manager-assign-work.php' => 'Assign work', 'add-client.php' => 'Client'];
    } else {
        $nav = [$dashboard => 'Dashboard', 'admin-employees.php' => is_staff()?'Employees':'My details', 'add-client.php' => 'Clients'];
    }
    if ($user['role']==='employee') { unset($nav['add-client.php']); $nav['add-client.php#brand-assets']='Brand assets'; }
    if (is_staff()) {
        $nav += ['admin-client-work-report.php' => 'Client work report', 'manager-assign-work.php' => 'Assign work', 'manager-dailywork.php' => 'Daily work reports', 'manager-monthly-design-report.php' => 'Work report', 'client-reuirement.php' => 'Client requests', 'manager-assigned-status.php' => 'Assigned work status'];
    } else {
        $nav += ['add-client.php#brand-assets' => 'Brand assets'];
    }
    if (!is_staff()) {
        $nav['employee-daily-work.php'] = 'Update daily work';
        $nav['employee-assigned-work.php'] = 'My assigned work';
    }
    if ($user['role']==='admin') $nav['employee-attendance.php']='Employee attendance';
    if (is_staff()) $nav['manager-leave-requist.php']='Leave requests';
    if (!is_staff()) $nav['employee-work-history.php']='My submitted work';
    if (!is_staff()) $nav['employee-profile.php']='My profile & salary';
    if (is_staff()) $nav['payslips.php'] = 'Employee payslips';
    $nav['admin-holidays.php'] = 'Holidays';
    $unread=count_value('SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND read_at IS NULL AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)',[$user['id']]);
    $nav['manager-notification.php'] = 'Notifications'.($unread?' ('.$unread.')':'');
    if (in_array($user['role'],['admin','manager','employee'],true)) $nav['chat.php']='Chat';
    $nav['change-password.php'] = 'Change password';
    if (!is_staff() && $user['role']==='employee') $nav['apply-leaves.php']='Apply leave';
    if ($user['role']==='client') $nav=['client-dashboard.php'=>'Dashboard','client-reuirement.php'=>'Request work','client-details.php'=>'Details','client-payments.php'=>'Payments','client-reports.php'=>'Reports','change-password.php'=>'Change password'];
    $adminNav = null;
    if ($user['role'] === 'admin') {
        $todayReports = count_value("SELECT COUNT(*) FROM daily_work_submissions s WHERE s.work_date=? AND s.submission_status='submitted' AND EXISTS (SELECT 1 FROM daily_work_entries e WHERE e.submission_id=s.id)", [today()]);
        $pendingLeaves = count_value("SELECT COUNT(*) FROM leave_requests WHERE status='pending'");
        $adminNav = [
            'Overview' => [
                ['href' => $dashboard, 'label' => 'Dashboard', 'icon' => 'dashboard'],
                ['href' => 'admin-employees.php', 'label' => 'Employees', 'icon' => 'people'],
                ['href' => 'employee-attendance.php', 'label' => 'Attendance', 'icon' => 'clock'],
                ['label' => 'Work Reports', 'icon' => 'document', 'badge' => $todayReports, 'children' => [
                    ['href' => 'manager-dailywork.php', 'label' => 'Daily Work Report'],
                    ['href' => 'admin-client-work-report.php', 'label' => 'Client Work Report'],
                ]],
                ['href' => 'manager-assign-work.php', 'label' => 'Tasks', 'icon' => 'tasks'],
                ['href' => 'add-client.php', 'label' => 'Projects & Clients', 'icon' => 'folder'],
            ],
            'Manage' => [
                ['href' => 'manager-leave-requist.php', 'label' => 'Leave Requests', 'icon' => 'calendar', 'badge' => $pendingLeaves],
                ['href' => 'manager-notification.php', 'label' => 'Announcements', 'icon' => 'megaphone', 'badge' => $unread],
                ['href' => 'manager-monthly-design-report.php', 'label' => 'Reports & Analytics', 'icon' => 'chart'],
                ['href' => 'change-password.php', 'label' => 'Settings', 'icon' => 'settings'],
            ],
            'More' => [
                ['href' => 'departments.php', 'label' => 'Departments', 'icon' => 'briefcase'],
                ['href' => 'manager-assigned-status.php', 'label' => 'Assigned Work Status', 'icon' => 'tasks'],
                ['href' => 'client-reuirement.php', 'label' => 'Client Requests', 'icon' => 'inbox'],
                ['href' => 'payslips.php', 'label' => 'Employee Payslips', 'icon' => 'money'],
                ['href' => 'admin-holidays.php', 'label' => 'Holidays', 'icon' => 'calendar'],
                ['href' => 'chat.php', 'label' => 'Chat', 'icon' => 'chat'],
            ],
        ];
    }
    $attendance = null;
    if ($user['role'] === 'employee') {
        $attendance = one('SELECT id,login_at FROM employee_attendance_sessions WHERE employee_id=? AND logout_at IS NULL AND login_at>=CURDATE() AND login_at<CURDATE()+INTERVAL 1 DAY ORDER BY login_at DESC LIMIT 1',[$user['id']]);
    }
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($title) ?> | Bhavi</title><link rel="stylesheet" href="assets/css/portal.css?v=<?= filemtime(__DIR__.'/../assets/css/portal.css') ?>"><?php if ($user['role']==='client'): ?><link rel="stylesheet" href="assets/css/client-portal.css?v=<?= filemtime(__DIR__.'/../assets/css/client-portal.css') ?>"><?php endif; ?></head>
<body data-role="<?= h($user['role']) ?>"><div class="portal-layout">
<aside class="portal-sidebar" id="portalSidebar"><button type="button" class="sidebar-close" aria-label="Close menu" title="Close menu">×</button><a class="portal-brand" href="<?= h($dashboard) ?>"><span class="brand-icon">B</span><span><strong>bhavi</strong><small>TEAM WORKSPACE</small></span></a>
<?php if ($user['role']==='employee'): ?><form class="attendance-control sidebar-attendance" data-attendance-date="<?= today() ?>" method="post" action="attendance.php"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $attendance?'logout':'login' ?>"><?php if ($attendance): ?><span data-attendance-time>Login saved at <?= h(date('g:i A',strtotime($attendance['login_at']))) ?></span><button class="attendance-button" type="submit"><?= icon('logout') ?>Logout</button><?php else: ?><button class="attendance-button" type="submit"><?= icon('document') ?>Login</button><?php endif; ?></form><?php endif; ?>
<?php if ($adminNav): ?><nav aria-label="Main navigation" class="sectioned-nav"><?php foreach ($adminNav as $section => $items): ?><p class="nav-section-title"><?= h($section) ?></p><?php foreach ($items as $item): $badge=(int)($item['badge'] ?? 0); if (isset($item['children'])): $groupActive=in_array($active, array_column($item['children'],'href'), true); ?><details class="nav-group"<?= $groupActive ? ' open' : '' ?>><summary class="<?= $groupActive ? 'group-active' : '' ?>"><?= icon($item['icon']) ?><span><?= h($item['label']) ?></span><?php if ($badge): ?><em class="nav-badge"><?= $badge ?></em><?php endif; ?><i class="nav-chevron"><?= icon('chevron') ?></i></summary><div class="nav-sub"><?php foreach ($item['children'] as $child): ?><a href="<?= h($child['href']) ?>" class="<?= $active === $child['href'] ? 'active' : '' ?>" <?= $active === $child['href'] ? 'aria-current="page"' : '' ?>><span><?= h($child['label']) ?></span></a><?php endforeach; ?></div></details><?php else: ?><a href="<?= h($item['href']) ?>" class="<?= $active === $item['href'] ? 'active' : '' ?>" <?= $active === $item['href'] ? 'aria-current="page"' : '' ?>><?= icon($item['icon']) ?><span><?= h($item['label']) ?></span><?php if ($badge): ?><em class="nav-badge"><?= $badge ?></em><?php endif; ?></a><?php endif; endforeach; endforeach; ?></nav><?php else: ?>
<nav aria-label="Main navigation"><?php foreach ($nav as $href => $label): ?><a href="<?= h($href) ?>" class="<?= $active === $href || str_starts_with($active,explode('#',$href)[0]) && str_contains($href,'#') ? 'active' : '' ?>" <?= $active === $href ? 'aria-current="page"' : '' ?>><?= icon(str_contains($href,'dashboard')?'dashboard':(str_contains($href,'employee') && !str_contains($href,'work') && !str_contains($href,'brands')?'people':(str_contains($href,'notification')?'bell':(str_contains($href,'password')?'lock':(str_contains($href,'leave') || str_contains($href,'holiday')?'calendar':(str_contains($href,'client') || str_contains($href,'department')?'briefcase':'document')))))) ?><span><?= h($label) ?></span></a><?php endforeach; ?></nav><?php endif; ?>
<form class="signout" method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?>Sign out</button></form></aside>
<div class="portal-main"><header class="portal-topbar"><div class="topbar-left"><button type="button" class="menu-toggle" aria-label="Toggle menu" aria-controls="portalSidebar" aria-expanded="false">☰</button><span>Workspace <span class="muted">/ <?= h($title) ?></span></span></div><div class="topbar-account"><time datetime="<?= today() ?>"><?= date('d M Y') ?></time><div class="account-name"><strong><?= h($user['full_name']) ?></strong><small><?= h($user['department_name'] ?? 'Team workspace') ?></small></div><span class="badge role-badge"><?= h(ucfirst($user['role'])) ?></span></div></header>
<main class="portal-content<?= $user['role']==='admin' && $title==='Admin dashboard'?' admin-dashboard-shell':'' ?>"><?php if (!($user['role']==='admin' && in_array($title,['Admin dashboard','Departments'],true)) && !in_array($title,['Add employee','Edit employee','Add client','Edit client','New assignment','Assigned work status','Employee attendance','Leave requests','Chat'],true)): ?><div class="page-heading"><h1><?= h($title) ?></h1></div><?php endif; ?>
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
