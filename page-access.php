<?php
// Employee access: the Managing Director gives an employee access to Managing Director pages.
require_once __DIR__ . '/includes/layout.php';
$user = require_roles(['admin']);
if (!empty($user['real_role'])) fail(403, 'Only the Managing Director can give access.');
$error = null;
$features = page_access_features();
$people = rows("SELECT u.id,u.full_name,d.name AS department_name,ep.designation FROM users u JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.full_name");
$peopleIds = array_map('intval', array_column($people, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $employee = positive_id($_POST['employee_id'] ?? null);
        if (!in_array($employee, $peopleIds, true)) throw new InvalidArgumentException('Choose an active employee.');
        $picked = array_values(array_intersect(is_array($_POST['features'] ?? null) ? $_POST['features'] : [], array_keys($features)));
        db()->beginTransaction();
        query('DELETE FROM employee_page_access WHERE user_id=?', [$employee]);
        foreach ($picked as $key) query('INSERT INTO employee_page_access (user_id,feature,granted_by) VALUES (?,?,?)', [$employee, $key, $user['id']]);
        db()->commit();
        $name = (string) (one('SELECT full_name FROM users WHERE id=?', [$employee])['full_name'] ?? 'Employee');
        if ($picked) {
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)", [$employee, $user['id'], 'New access given', 'You can now open: ' . implode(', ', array_map(static fn($k) => $features[$k][0], $picked)) . '. Find them under “Access given” in the sidebar.']);
            flash($name . ' can now open: ' . implode(', ', array_map(static fn($k) => $features[$k][0], $picked)) . '.');
        } else {
            flash('All access removed for ' . $name . '.');
        }
        redirect('page-access.php?employee=' . $employee);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
    }
}

$grants = [];
foreach (rows('SELECT user_id,feature FROM employee_page_access') as $g) if (isset($features[$g['feature']])) $grants[(int) $g['user_id']][] = $g['feature'];
$selected = (int) ($_POST['employee_id'] ?? $_GET['employee'] ?? 0);
if (!in_array($selected, $peopleIds, true)) $selected = 0;
$selectedGrants = $grants[$selected] ?? [];

page_start('Employee access', 'page-access.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / Employee access</p>
        <h1 class="ot-title">Employee access</h1>
        <p class="ot-sub">Give an employee access to your pages — for example Client work report. They see those pages under “Access given” in their sidebar and can use them like you. Untick to take the access back.</p>
    </header>

    <div class="ot-grid pa-grid">
        <section class="ot-card">
            <h2>Give access</h2>
            <form method="get" class="ot-form"><label><span>Employee</span><select name="employee" onchange="this.form.submit()"><option value="">Select employee</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $selected === (int) $p['id'] ? 'selected' : '' ?>><?= h($p['full_name']) ?><?= $p['department_name'] ? ' · ' . h($p['department_name']) : '' ?></option><?php endforeach; ?></select></label><noscript><button class="ot-dark" type="submit">Open</button></noscript></form>
            <?php if ($selected): ?>
                <form method="post" class="ot-form"><?= csrf_field() ?><input type="hidden" name="employee_id" value="<?= $selected ?>">
                    <div class="pa-list">
                        <?php foreach ($features as $key => [$label]): ?>
                            <label class="pa-item"><input type="checkbox" name="features[]" value="<?= h($key) ?>" <?= in_array($key, $selectedGrants, true) ? 'checked' : '' ?>><span><?= h($label) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <p class="ot-help">⚠ Salary, Tools / Apps (passwords) and Employees show private details — give them only to people you trust.</p>
                    <div class="ot-actions"><button class="ot-gold" type="submit">Save access</button></div>
                </form>
            <?php else: ?><p class="ot-help">Select an employee to choose which pages they can open.</p><?php endif; ?>
        </section>

        <section class="ot-card">
            <div class="ot-card-head"><h2>Who has access</h2><span><?= count($grants) ?> employee<?= count($grants) === 1 ? '' : 's' ?></span></div>
            <?php if (!$grants): ?><p class="ot-help">No employee has extra access yet.</p><?php endif; ?>
            <div class="ot-list">
                <?php foreach ($people as $p): if (empty($grants[(int) $p['id']])) continue; ?>
                    <div class="ot-row is-custom">
                        <span class="ot-who"><strong><?= h($p['full_name']) ?></strong><small><?= h(implode(' · ', array_filter([$p['designation'], $p['department_name']]))) ?></small></span>
                        <span class="pa-chips"><?php foreach ($grants[(int) $p['id']] as $key): ?><em><?= h($features[$key][0]) ?></em><?php endforeach; ?></span>
                        <span class="ot-row-actions"><a href="page-access.php?employee=<?= (int) $p['id'] ?>">Change</a></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<?php page_end(); ?>
