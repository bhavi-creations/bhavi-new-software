<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/office-timing.php';
require_once __DIR__ . '/includes/salary-calc.php';
$user = require_roles(['admin']);
$error = null;
const SHIFT_MAX_SLOTS = 4;

/** Hours a day from the form ('' = not set), 0.5–24. */
function shift_hours_input(): ?string
{
    $hours = is_string($_POST['shift_hours'] ?? null) ? trim($_POST['shift_hours']) : '';
    if ($hours === '') return null;
    if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/D', $hours) || (float) $hours < 0.5 || (float) $hours > 24) throw new InvalidArgumentException('Enter working hours between 0.5 and 24.');
    return number_format((float) $hours, 2, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_default') {
            // One office timing for everyone who does not have their own shift.
            $start = office_time_input($_POST['office_start'] ?? '', 'start time');
            $end = office_time_input($_POST['office_end'] ?? '', 'end time');
            if ($start === $end) throw new InvalidArgumentException('Start and end time cannot be the same.');
            foreach (['office_start' => $start, 'office_end' => $end] as $key => $time) {
                query('INSERT INTO portal_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)', [$key, $time, $user['id']]);
            }
            flash('Office timing saved: ' . office_range_label($start, $end) . '. It now applies to every employee without their own shift.');
        } elseif ($action === 'save_shift') {
            // Own timing for one employee: one shift (e.g. night shift) or split timings (e.g. 7–10 PM + 5–8 AM).
            $employee = positive_id($_POST['employee_id'] ?? null);
            if (!one("SELECT u.id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.role='employee' AND u.deleted_at IS NULL", [$employee])) throw new InvalidArgumentException('Choose an employee.');
            $posted = is_array($_POST['slots'] ?? null) ? array_slice(array_values($_POST['slots']), 0, SHIFT_MAX_SLOTS) : [];
            if (isset($_POST['shift_start'])) $posted = [['start' => $_POST['shift_start'], 'end' => $_POST['shift_end'] ?? '']]; // older form
            $slots = [];
            foreach ($posted as $i => $slot) {
                if (!is_array($slot) || (time12_value($slot['start'] ?? null) === null && time12_value($slot['end'] ?? null) === null)) continue;
                $start = office_time_input($slot['start'] ?? '', 'start time for timing ' . ($i + 1));
                $end = office_time_input($slot['end'] ?? '', 'end time for timing ' . ($i + 1));
                if ($start === $end) throw new InvalidArgumentException('Start and end time cannot be the same (timing ' . ($i + 1) . ').');
                $slots[] = [$start, $end];
            }
            if (!$slots) throw new InvalidArgumentException('Enter at least one timing (start and end).');
            $hours = shift_hours_input();
            $label = text_input('shift_label', 60, false) ?: (count($slots) > 1 ? 'Split timing' : 'Own shift');
            $timing = employee_timing(['shift_start' => $slots[0][0], 'shift_end' => $slots[0][1], 'shift_label' => $label, 'shift_slots' => json_encode($slots), 'shift_hours' => $hours]);
            query('UPDATE employee_profiles SET shift_start=?,shift_end=?,shift_label=?,shift_slots=?,shift_hours=?,work_timings=? WHERE user_id=?', [$slots[0][0], $slots[0][1], $label, count($slots) > 1 ? json_encode($slots) : null, $hours, mb_substr($timing['text'], 0, 100), $employee]);
            flash('Timing saved for ' . (one('SELECT full_name FROM users WHERE id=?', [$employee])['full_name'] ?? 'employee') . ': ' . $timing['text'] . '.');
        } elseif ($action === 'save_duty') {
            // Working more than this sends the day to Attendance approvals as extra work.
            $hours = is_string($_POST['duty_hours'] ?? null) ? trim($_POST['duty_hours']) : '';
            if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/D', $hours) || (float) $hours < 1 || (float) $hours > 16) throw new InvalidArgumentException('Enter duty hours between 1 and 16.');
            query('INSERT INTO portal_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)', ['duty_hours', (string) (float) $hours, $user['id']]);
            flash('Duty hours saved: ' . duty_hours_label((float) $hours) . ' a day. More than this goes to Attendance approvals as extra work.');
        } elseif ($action === 'reset_shift') {
            $employee = positive_id($_POST['employee_id'] ?? null);
            query("UPDATE employee_profiles SET shift_start=NULL,shift_end=NULL,shift_label='',shift_slots=NULL,shift_hours=NULL,work_timings='' WHERE user_id=?", [$employee]);
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
$people = rows("SELECT u.id,u.full_name,d.name AS department_name,ep.designation,ep.shift_start,ep.shift_end,ep.shift_label,ep.shift_slots,ep.shift_hours FROM users u JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.full_name");
$customPeople = array_values(array_filter($people, static fn($p) => !empty($p['shift_start']) && !empty($p['shift_end'])));
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null; foreach ($people as $p) if ((int) $p['id'] === $editId) $editing = $p;
$editTiming = $editing && !empty($editing['shift_start']) ? employee_timing($editing) : null;
$formSlots = $editTiming ? $editTiming['slots'] : [['21:00', '06:00']];
$initials = static fn(string $n): string => strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2)))) ?: '?';

page_start('Office timings', 'office-timings.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / Office timings</p>
        <h1 class="ot-title">Office timings</h1>
        <p class="ot-sub">Set one office timing for everyone. Give a different timing only to people who work another shift — a night shift, or split timings like 7–10 PM and 5–8 AM.</p>
    </header>

    <div class="ot-grid">
        <section class="ot-card ot-default">
            <p class="ot-eyebrow">Default for all employees</p>
            <p class="ot-now"><?= h(office_range_label($default['start'], $default['end'])) ?></p>
            <form method="post" class="ot-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_default">
                <div class="ot-two">
                    <label><span>Office starts</span><?= time12_field('office_start', $default['start'], true, 'Office starts') ?></label>
                    <label><span>Office ends</span><?= time12_field('office_end', $default['end'], true, 'Office ends') ?></label>
                </div>
                <button class="ot-gold" type="submit">Save office timing</button>
                <small><?= count($people) - count($customPeople) ?> of <?= count($people) ?> employees follow this timing.</small>
            </form>
            <form method="post" class="ot-form ot-duty"><?= csrf_field() ?><input type="hidden" name="action" value="save_duty">
                <label><span>Duty hours per day <small>(more than this goes to Managing Director approval as extra work)</small></span><input type="number" name="duty_hours" min="1" max="16" step="0.25" value="<?= h((string) duty_hours()) ?>" required></label>
                <button class="ot-dark" type="submit">Save duty hours</button>
            </form>
        </section>

        <section class="ot-card" id="shift-form">
            <h2><?= $editing ? 'Change timing · ' . h($editing['full_name']) : 'Different timing for an employee' ?></h2>
            <p class="ot-help">One shift (e.g. night shift) or split timings — add a second timing for people who work at night and again in the morning. Everyone else keeps the office timing.</p>
            <form method="post" class="ot-form" data-shift-form><?= csrf_field() ?><input type="hidden" name="action" value="save_shift">
                <label><span>Employee</span><select name="employee_id" required><option value="">Select employee</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $editing && (int) $editing['id'] === (int) $p['id'] ? 'selected' : '' ?>><?= h($p['full_name']) ?><?= $p['department_name'] ? ' · ' . h($p['department_name']) : '' ?></option><?php endforeach; ?></select></label>
                <div class="ot-slots" data-slots>
                    <?php for ($i = 0; $i < SHIFT_MAX_SLOTS; $i++): $slot = $formSlots[$i] ?? null; ?>
                        <div class="ot-slot" data-slot<?= $slot || $i === 0 ? '' : ' hidden' ?>>
                            <p class="ot-slot-title">Timing <?= $i + 1 ?><?php if ($i > 0): ?> <button type="button" class="ot-slot-remove" data-slot-remove aria-label="Remove timing <?= $i + 1 ?>">✕</button><?php endif; ?></p>
                            <div class="ot-two">
                                <label><span>Starts</span><?= time12_field('slots[' . $i . '][start]', $slot[0] ?? null, $i === 0, 'Timing ' . ($i + 1) . ' starts') ?></label>
                                <label><span>Ends</span><?= time12_field('slots[' . $i . '][end]', $slot[1] ?? null, $i === 0, 'Timing ' . ($i + 1) . ' ends') ?></label>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="ot-add-slot" data-slot-add>+ Add another timing</button>
                <div class="ot-two">
                    <label><span>Working hours a day <small>(optional)</small></span><input type="number" name="shift_hours" min="0.5" max="24" step="0.25" value="<?= h($editing['shift_hours'] ?? '') ?>" placeholder="Auto from timings" data-shift-hours></label>
                    <label><span>Name of the shift <small>(optional)</small></span><input name="shift_label" maxlength="60" value="<?= h($editing['shift_label'] ?? '') ?>" placeholder="e.g. Night + morning"></label>
                </div>
                <p class="ot-help" data-hours-hint></p>
                <div class="ot-actions"><button class="ot-dark" type="submit">Save timing</button><?php if ($editing): ?><a href="office-timings.php">Cancel</a><?php endif; ?></div>
            </form>
        </section>
    </div>

    <section class="ot-card">
        <div class="ot-card-head"><h2>Everyone’s timing</h2><span><?= count($customPeople) ?> on a different timing</span></div>
        <?php if (!$people): ?><p class="ot-help">No active employees yet.</p><?php endif; ?>
        <div class="ot-list">
            <?php foreach ($people as $p): $t = employee_timing($p); ?>
                <div class="ot-row<?= $t['custom'] ? ' is-custom' : '' ?>">
                    <span class="ot-avatar"><?= h($initials($p['full_name'])) ?></span>
                    <span class="ot-who"><strong><?= h($p['full_name']) ?></strong><small><?= h(implode(' · ', array_filter([$p['designation'], $p['department_name']]))) ?: 'Team member' ?></small></span>
                    <span class="ot-when"><strong><?= h($t['text']) ?></strong><em><?= h($t['label']) ?></em></span>
                    <span class="ot-row-actions">
                        <a href="office-timings.php?edit=<?= (int) $p['id'] ?>#shift-form"><?= $t['custom'] ? 'Change' : 'Set own timing' ?></a>
                        <?php if ($t['custom']): ?><form method="post" data-confirm="Move <?= h($p['full_name']) ?> back to the office timing?"><?= csrf_field() ?><input type="hidden" name="action" value="reset_shift"><input type="hidden" name="employee_id" value="<?= (int) $p['id'] ?>"><button type="submit">Use office timing</button></form><?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<script>
(() => {
    const form = document.querySelector('[data-shift-form]');
    if (!form) return;
    const slots = [...form.querySelectorAll('[data-slot]')], add = form.querySelector('[data-slot-add]'), hint = form.querySelector('[data-hours-hint]');
    const minutesOf = (slot, which) => {
        const get = part => slot.querySelector(`select[name$="[${which}][${part}]"]`)?.value || '';
        const h = parseInt(get('h'), 10), m = parseInt(get('m'), 10);
        if (!h || isNaN(m)) return null;
        return (h % 12 + (get('ap') === 'PM' ? 12 : 0)) * 60 + m;
    };
    const update = () => {
        const visible = slots.filter(s => !s.hidden);
        add.hidden = visible.length >= slots.length;
        let total = 0;
        visible.forEach(s => { const a = minutesOf(s, 'start'), b = minutesOf(s, 'end'); if (a !== null && b !== null && a !== b) total += (b - a + 1440) % 1440; });
        hint.textContent = total ? `Timings add up to ${Math.floor(total / 60)} hrs${total % 60 ? ' ' + (total % 60) + ' min' : ''} a day. Leave “Working hours” empty to use this.` : '';
    };
    add.addEventListener('click', () => { const next = slots.find(s => s.hidden); if (next) next.hidden = false; update(); });
    form.addEventListener('click', e => {
        const remove = e.target.closest('[data-slot-remove]');
        if (!remove) return;
        const slot = remove.closest('[data-slot]');
        slot.querySelectorAll('select').forEach(s => { s.value = s.name.endsWith('[ap]') ? 'AM' : ''; });
        slot.hidden = true; update();
    });
    form.addEventListener('change', update); update();
})();
</script>
<?php page_end(); ?>
