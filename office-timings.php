<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/office-timing.php';
$user = require_roles(['admin']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_default') {
            // One office timing for everyone who does not have their own shift.
            $start = office_time_input((string) ($_POST['office_start'] ?? ''), 'start time');
            $end = office_time_input((string) ($_POST['office_end'] ?? ''), 'end time');
            if ($start === $end) throw new InvalidArgumentException('Start and end time cannot be the same.');
            foreach (['office_start' => $start, 'office_end' => $end] as $key => $time) {
                query('INSERT INTO portal_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)', [$key, $time, $user['id']]);
            }
            flash('Office timing saved: ' . office_range_label($start, $end) . '. It now applies to every employee without their own shift.');
        } elseif ($action === 'save_shift') {
            // Own timing for one employee, e.g. night shift.
            $employee = positive_id($_POST['employee_id'] ?? null);
            if (!one("SELECT u.id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.role='employee' AND u.deleted_at IS NULL", [$employee])) throw new InvalidArgumentException('Choose an employee.');
            $start = office_time_input((string) ($_POST['shift_start'] ?? ''), 'shift start time');
            $end = office_time_input((string) ($_POST['shift_end'] ?? ''), 'shift end time');
            if ($start === $end) throw new InvalidArgumentException('Shift start and end time cannot be the same.');
            $label = text_input('shift_label', 60, false) ?: 'Own shift';
            query('UPDATE employee_profiles SET shift_start=?,shift_end=?,shift_label=?,work_timings=? WHERE user_id=?', [$start, $end, $label, office_range_label($start, $end), $employee]);
            flash('Shift saved for ' . (one('SELECT full_name FROM users WHERE id=?', [$employee])['full_name'] ?? 'employee') . ': ' . office_range_label($start, $end) . '.');
        } elseif ($action === 'reset_shift') {
            $employee = positive_id($_POST['employee_id'] ?? null);
            query("UPDATE employee_profiles SET shift_start=NULL,shift_end=NULL,shift_label='',work_timings='' WHERE user_id=?", [$employee]);
            flash('Employee moved back to the office timing.');
        } else {
            throw new InvalidArgumentException('Invalid request.');
        }
        redirect('office-timings.php');
    } catch (Throwable $e) {
        $error = mutation_error($e);
    }
}

$default = office_timing_default();
$people = rows("SELECT u.id,u.full_name,d.name AS department_name,ep.designation,ep.shift_start,ep.shift_end,ep.shift_label FROM users u JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.full_name");
$customPeople = array_values(array_filter($people, static fn($p) => !empty($p['shift_start']) && !empty($p['shift_end'])));
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null; foreach ($people as $p) if ((int) $p['id'] === $editId) $editing = $p;
$initials = static fn(string $n): string => strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2)))) ?: '?';

page_start('Office timings', 'office-timings.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / Office timings</p>
        <h1 class="ot-title">Office timings</h1>
        <p class="ot-sub">Set one office timing for everyone. Give a different timing only to people who work another shift, like a night shift.</p>
    </header>

    <div class="ot-grid">
        <section class="ot-card ot-default">
            <p class="ot-eyebrow">Default for all employees</p>
            <p class="ot-now"><?= h(office_range_label($default['start'], $default['end'])) ?></p>
            <form method="post" class="ot-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_default">
                <div class="ot-two">
                    <label><span>Office starts</span><input type="time" name="office_start" value="<?= h($default['start']) ?>" required></label>
                    <label><span>Office ends</span><input type="time" name="office_end" value="<?= h($default['end']) ?>" required></label>
                </div>
                <button class="ot-gold" type="submit">Save office timing</button>
                <small><?= count($people) - count($customPeople) ?> of <?= count($people) ?> employees follow this timing.</small>
            </form>
        </section>

        <section class="ot-card" id="shift-form">
            <h2><?= $editing ? 'Change shift · ' . h($editing['full_name']) : 'Different timing for an employee' ?></h2>
            <p class="ot-help">For night shift or any other timing. Everyone else keeps the office timing.</p>
            <form method="post" class="ot-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_shift">
                <label><span>Employee</span><select name="employee_id" required><option value="">Select employee</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $editing && (int) $editing['id'] === (int) $p['id'] ? 'selected' : '' ?>><?= h($p['full_name']) ?><?= $p['department_name'] ? ' · ' . h($p['department_name']) : '' ?></option><?php endforeach; ?></select></label>
                <div class="ot-two">
                    <label><span>Shift starts</span><input type="time" name="shift_start" value="<?= h($editing && $editing['shift_start'] ? substr($editing['shift_start'], 0, 5) : '21:00') ?>" required></label>
                    <label><span>Shift ends</span><input type="time" name="shift_end" value="<?= h($editing && $editing['shift_end'] ? substr($editing['shift_end'], 0, 5) : '06:00') ?>" required></label>
                </div>
                <label><span>Name of the shift <small>(optional)</small></span><input name="shift_label" maxlength="60" value="<?= h($editing['shift_label'] ?? '') ?>" placeholder="e.g. Night shift"></label>
                <div class="ot-actions"><button class="ot-dark" type="submit">Save shift</button><?php if ($editing): ?><a href="office-timings.php">Cancel</a><?php endif; ?></div>
            </form>
        </section>
    </div>

    <section class="ot-card">
        <div class="ot-card-head"><h2>Everyone’s timing</h2><span><?= count($customPeople) ?> on a different shift</span></div>
        <?php if (!$people): ?><p class="ot-help">No active employees yet.</p><?php endif; ?>
        <div class="ot-list">
            <?php foreach ($people as $p): $t = employee_timing($p); ?>
                <div class="ot-row<?= $t['custom'] ? ' is-custom' : '' ?>">
                    <span class="ot-avatar"><?= h($initials($p['full_name'])) ?></span>
                    <span class="ot-who"><strong><?= h($p['full_name']) ?></strong><small><?= h(implode(' · ', array_filter([$p['designation'], $p['department_name']]))) ?: 'Team member' ?></small></span>
                    <span class="ot-when"><strong><?= h($t['text']) ?></strong><em><?= h($t['label']) ?></em></span>
                    <span class="ot-row-actions">
                        <a href="office-timings.php?edit=<?= (int) $p['id'] ?>#shift-form"><?= $t['custom'] ? 'Change' : 'Set own shift' ?></a>
                        <?php if ($t['custom']): ?><form method="post" onsubmit="return confirm('Move <?= h(addslashes($p['full_name'])) ?> back to the office timing?')"><?= csrf_field() ?><input type="hidden" name="action" value="reset_shift"><input type="hidden" name="employee_id" value="<?= (int) $p['id'] ?>"><button type="submit">Use office timing</button></form><?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php page_end(); ?>
