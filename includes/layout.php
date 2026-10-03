<?php
require_once __DIR__ . '/bootstrap.php';

function icon(string $name): string
{
    $paths = [
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'edit' => '<path d="m16 3 5 5-12 12-6 1 1-6L16 3Z"/><path d="m14 5 5 5"/>',
        'delete' => '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
    ];
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
function page_start(string $title, string $active = ''): void
{
    $user = require_roles();
    $dashboard = dashboard_path($user);
    $nav = [$dashboard => 'Dashboard', 'admin-employees.php' => 'Employees', 'add-client.php' => 'Clients'];
    if ($user['role'] === 'admin') { $nav['admin-add-employee.php'] = 'Add employee / manager'; }
    if (is_staff()) {
        $nav += ['manager-assign-work.php' => 'Assign work', 'manager-dailywork.php' => 'Daily work reports', 'manager-leave-requist.php' => 'Leave requests'];
    } else {
        $nav += ['employee-brands-assets.php' => 'Brand assets', 'client-reuirement.php' => 'Client requirements', 'apply-leaves.php' => 'Apply leave', 'check-leave.php' => 'My leave calendar'];
    }
    $nav['admin-holidays.php'] = 'Holidays';
    $nav['manager-notification.php'] = 'Notifications';
    $nav['change-password.php'] = 'Change password';
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($title) ?> | Bhavi</title><link rel="stylesheet" href="assets/css/portal.css"></head>
<body><div class="portal-layout">
<aside class="portal-sidebar" id="portalSidebar"><a class="portal-brand" href="<?= h($dashboard) ?>"><span class="brand-icon">B</span><span><strong>bhavi</strong><small>TEAM WORKSPACE</small></span></a>
<nav aria-label="Main navigation"><?php foreach ($nav as $href => $label): ?><a href="<?= h($href) ?>" class="<?= $active === $href ? 'active' : '' ?>" <?= $active === $href ? 'aria-current="page"' : '' ?>><?= h($label) ?></a><?php endforeach; ?></nav>
<form class="signout" method="post" action="logout.php"><?= csrf_field() ?><button type="submit">Sign out</button></form></aside>
<div class="portal-main"><header class="portal-topbar"><div class="topbar-left"><button type="button" class="menu-toggle" aria-label="Toggle menu" aria-controls="portalSidebar" aria-expanded="false">☰</button><span>Workspace <span class="muted">/ <?= h($title) ?></span></span></div><div class="topbar-account"><time datetime="<?= today() ?>"><?= date('d M Y') ?></time><div class="account-name"><strong><?= h($user['full_name']) ?></strong><small><?= h($user['department_name'] ?? 'Team workspace') ?></small></div><span class="badge role-badge"><?= h(ucfirst($user['role'])) ?></span></div></header>
<main class="portal-content"><div class="page-heading"><h1><?= h($title) ?></h1></div>
<?php foreach ($_SESSION['flash'] ?? [] as $message): ?><div class="alert <?= h($message['type']) ?>" role="status"><?= h($message['message']) ?></div><?php endforeach; unset($_SESSION['flash']);
}
function page_end(): void
{
    ?></main><footer class="portal-footer">Bhavi Creations · Team workspace</footer></div></div><script src="assets/js/portal.js" defer></script></body></html><?php
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
    ?><form method="post" class="inline-form" data-confirm="<?= h($message) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="<?= h($action) ?>"><input type="hidden" name="id" value="<?= $id ?>"><button type="submit" class="icon-button danger" title="Delete" aria-label="Delete"><?= icon('delete') ?></button></form><?php
}
function mutation_error(Throwable $e): string
{
    if ($e instanceof InvalidArgumentException) { return $e->getMessage(); }
    error_log($e->getMessage());
    return $e instanceof PDOException && $e->getCode() === '23000' ? 'This username, email or date is already in use. Please choose another.' : 'Unable to save this change. Please try again.';
}
