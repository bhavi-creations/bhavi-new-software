<?php
// Included by the authenticated employee daily work page (employee-daily-work.php).
$formValues = $editEntry ?? [];
if ($error && ($_POST['action'] ?? '') === 'save_entry') {
    foreach ($_POST as $key => $value) if (is_string($value)) $formValues[$key] = $value;
}
$socialBatch = $socialWork && !$editEntry;
$isEditing = (bool) $editEntry;

// ---------- Summary for the selected day ----------
$dayLogin = one('SELECT login_at FROM employee_attendance_sessions WHERE employee_id=? AND DATE(login_at)=? ORDER BY login_at LIMIT 1', [$user['id'], $date]);
$minutesLogged = array_sum(array_map(static fn($e) => (int) ($e['time_spent_minutes'] ?? 0), $entries));
$hoursLabel = intdiv($minutesLogged, 60) . 'h' . ($minutesLogged % 60 ? ' ' . ($minutesLogged % 60) . 'm' : '');
$entryAssignmentIds = array_map('intval', array_filter(array_column($entries, 'assignment_id')));
$pendingAssignments = array_values(array_filter($assignments, static fn($a) => ($a['status'] ?? 'pending') !== 'completed'));
$isToday = $date === today();
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
$sheetSubmitted = $sheet && ($sheet['submission_status'] ?? '') === 'submitted';

// ---------- Work types for this employee's department ----------
$deptCode = (string) ($sheet['department_code'] ?? $workDepartment);
$deptName = (string) ($sheet['department_name'] ?? ($user['department_name'] ?? ''));
$workTypes = department_work_types($deptCode, $deptName);
$typeRequired = $creativeWork;
$editingTypeMap = ['reel' => 'reel', 'poster' => 'poster', 'thumbnail' => 'thumbnail', 'carousel' => 'carousel', 'video_editing' => 'video_editing', 'motion_graphics' => 'motion_graphics', 'image_editing' => 'image_editing', 'other' => 'design_other'];

// ---------- Assigned tasks the employee can report on ----------
// Only unfinished tasks. A finished task is listed only while editing the entry that reported it.
$editingAssignmentId = (int) ($editEntry['assignment_id'] ?? 0);
$pickTasks = array_values(array_filter($assignments, static fn($a) => (($a['status'] ?? 'pending') !== 'completed' && empty($a['completed_at'])) || ($editingAssignmentId && (int) $a['id'] === $editingAssignmentId)));
$pickTasks = array_slice($pickTasks, 0, 12);
$selectedAssignment = (string) ($formValues['assignment_id'] ?? '');
if (!$isEditing && !($error && ($_POST['action'] ?? '') === 'save_entry')) {
    foreach ($pickTasks as $task) {
        if (!in_array((int) $task['id'], $entryAssignmentIds, true) && ($task['status'] ?? '') !== 'completed') { $selectedAssignment = (string) $task['id']; break; }
    }
}
// "Submit this task" on My assigned work opens this form with that task picked.
if (!$isEditing && !empty($_GET['assignment'])) {
    foreach ($pickTasks as $task) if ((int) $task['id'] === (int) $_GET['assignment']) $selectedAssignment = (string) $task['id'];
}
$selectedTask = null;
foreach ($pickTasks as $task) if ((string) $task['id'] === $selectedAssignment) $selectedTask = $task;
if ($selectedTask && !$isEditing && !($error && ($_POST['action'] ?? '') === 'save_entry')) {
    $formValues['client_id'] = $formValues['client_id'] ?? $selectedTask['client_id'];
    $formValues['task_title'] = $formValues['task_title'] ?? $selectedTask['title'];
    if ($creativeWork && empty($formValues['work_type'])) $formValues['work_type'] = $editingTypeMap[(string) ($selectedTask['editing_type'] ?? '')] ?? '';
}
$timeValue = $formValues['time_spent_hours'] ?? (isset($formValues['time_spent_minutes']) && $formValues['time_spent_minutes'] !== null && $formValues['time_spent_minutes'] !== '' ? format_work_duration($formValues['time_spent_minutes']) : '');
$timeChips = ['30 min' => '30 min', '1 hour' => '1 hr', '1 hour 30 min' => '1 hr 30 min', '2 hours' => '2 hrs', '3 hours' => '3 hrs'];
$statusValue = $formValues['task_status'] ?? 'completed';
?>
<div class="dw-page">
    <header class="dw-head">
        <div>
            <p class="dw-crumb">Dashboard / Update daily work</p>
            <h1 class="dw-title">Update daily work</h1>
            <p class="dw-sub">Add each piece of work you did<?= $isToday ? ' today' : ' on this day' ?> — your manager sees it instantly.</p>
        </div>
        <form method="get" class="dw-datenav" aria-label="Choose work date">
            <a href="employee-daily-work.php?date=<?= h($prevDate) ?>" aria-label="Previous day">‹</a>
            <label class="sr-only" for="dw-date">Work date</label>
            <input id="dw-date" type="date" name="date" value="<?= h($date) ?>" max="<?= h(today()) ?>" onchange="this.form.submit()">
            <?php if ($date < today()): ?><a href="employee-daily-work.php?date=<?= h($nextDate) ?>" aria-label="Next day">›</a><?php else: ?><span class="is-disabled" aria-hidden="true">›</span><?php endif; ?>
        </form>
    </header>

    <div class="dw-grid">
        <section class="dw-card" id="add-work">
            <div class="dw-card-head"><span class="dw-step"><?= $isEditing ? '✎' : count($entries) + 1 ?></span><h2><?= $isEditing ? 'Edit work entry' : 'New work entry' ?></h2><?php if ($isEditing): ?><a class="dw-cancel" href="employee-daily-work.php?date=<?= h($date) ?>">Cancel edit</a><?php endif; ?></div>

        <?php if ($socialBatch):
            $socialTaskRows = [['task_status' => 'completed']];
            if ($error && ($_POST['action'] ?? '') === 'save_social_tasks' && is_array($_POST['tasks'] ?? null) && $_POST['tasks']) {
                $socialTaskRows = [];
                foreach (array_values($_POST['tasks']) as $task) $socialTaskRows[] = is_array($task) ? array_filter($task, static fn($v) => is_string($v) || is_array($v)) : [];
            }
            $taskClients = clients();
            $socialChoices = [];
            foreach (rows('SELECT client_id,id,platform_name,url FROM client_social_links ORDER BY client_id,id') as $link) $socialChoices[(int) $link['client_id']][] = $link;
            $renderSocialTask = static function ($index, array $values = []) use ($taskClients, $socialChoices): void {
                $prefix = 'tasks[' . $index . ']'; $id = 'social-task-' . $index . '-'; $selected = array_map('strval', $values['platforms'] ?? []); ?>
                <fieldset class="daily-task-card dw-social-task" data-daily-task>
                <legend data-daily-task-heading>Client task <?= is_int($index) ? $index + 1 : '__NUMBER__' ?></legend>
                <div class="dw-two">
                  <label class="dw-field"><span>Client</span><select id="<?= h($id) ?>client" name="<?= h($prefix) ?>[client_id]" data-social-client required><option value="">Choose client</option><?= options($taskClients, 'client_name', $values['client_id'] ?? null) ?></select></label>
                  <label class="dw-field"><span>Content type</span><select id="<?= h($id) ?>type" name="<?= h($prefix) ?>[work_type]" required><option value="">Choose type</option><?php foreach (['poster' => 'Poster', 'reel' => 'Reel', 'carousel' => 'Carousel'] as $type => $label): ?><option value="<?= h($type) ?>" <?= ($values['work_type'] ?? '') === $type ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                  <label class="dw-field"><span>Quantity</span><input id="<?= h($id) ?>count" name="<?= h($prefix) ?>[item_count]" type="number" min="1" max="999999" step="1" value="<?= h($values['item_count'] ?? '1') ?>" required></label>
                </div>
                <div class="dw-field"><span>Social media accounts used</span><div class="status-choice dw-platforms" data-social-platforms><?php foreach ($socialChoices[(int) ($values['client_id'] ?? 0)] ?? [] as $link): ?><label><input type="checkbox" name="<?= h($prefix) ?>[platforms][]" value="<?= (int) $link['id'] ?>" <?= in_array((string) $link['id'], $selected, true) ? 'checked' : '' ?>><?= h($link['platform_name']) ?></label><?php endforeach; ?><span class="muted" data-social-empty><?= empty($socialChoices[(int) ($values['client_id'] ?? 0)]) ? 'Select a client with social accounts saved in its profile.' : '' ?></span></div></div>
                <label class="dw-field"><span>Remark (optional)</span><textarea id="<?= h($id) ?>remark" name="<?= h($prefix) ?>[remark]" maxlength="5000"><?= h($values['remark'] ?? '') ?></textarea></label>
                <div class="form-actions"><button class="dw-btn dw-btn-ghost" type="button" data-remove-daily-task>Remove client</button></div></fieldset><?php
            };
        ?>
            <p class="dw-help">Add each client separately. Choose Poster, Reel or Carousel, enter quantity, and tick the accounts where you published.</p>
            <form method="post" class="dw-form" data-daily-work-form data-social-work-form><?= csrf_field() ?><input type="hidden" name="action" value="save_social_tasks"><div data-daily-tasks><?php foreach ($socialTaskRows as $index => $values) $renderSocialTask($index, $values); ?></div><input type="hidden" name="task_count" value="<?= count($socialTaskRows) ?>"><div class="dw-actions"><button type="submit" class="dw-btn dw-btn-gold">Save &amp; submit all work</button><button type="button" class="dw-btn dw-btn-dark" data-add-daily-task>+ Add client</button></div><p class="dw-help" data-daily-task-summary aria-live="polite"></p></form>
            <template id="daily-task-template"><?php $renderSocialTask('__INDEX__', ['item_count' => '1', 'task_status' => 'completed']); ?></template>
            <script>window.socialClientPlatforms=<?= json_encode($socialChoices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;</script><script src="assets/js/daily-work.js?v=<?= filemtime(__DIR__ . '/../assets/js/daily-work.js') ?>" defer></script>

        <?php else: ?>
            <form method="post" class="dw-form" data-dw-form>
                <?= csrf_field() ?><input type="hidden" name="action" value="save_entry"><input type="hidden" name="entry_id" value="<?= h($editEntry['id'] ?? '') ?>"><input type="hidden" name="submit_mode" value="submitted">

                <?php if ($pickTasks): ?>
                <fieldset class="dw-fieldset">
                    <legend>What did you work on? <small>· pick an assigned task</small></legend>
                    <div class="dw-tasks">
                        <?php foreach ($pickTasks as $task): $done = in_array((int) $task['id'], $entryAssignmentIds, true); ?>
                            <label class="dw-task">
                                <input type="radio" name="assignment_id" value="<?= (int) $task['id'] ?>" data-client="<?= h($task['client_id'] ?? '') ?>" data-title="<?= h($task['title']) ?>" data-type="<?= h($creativeWork ? ($editingTypeMap[(string) ($task['editing_type'] ?? '')] ?? '') : '') ?>" <?= $selectedAssignment === (string) $task['id'] ? 'checked' : '' ?>>
                                <span><strong><?= h($task['title']) ?></strong><small><?= h($task['client_name'] ?: 'Internal work') ?><?= $done ? ' · already reported today' : '' ?></small></span>
                            </label>
                        <?php endforeach; ?>
                        <label class="dw-task is-other">
                            <input type="radio" name="assignment_id" value="" data-client="" data-title="" data-type="" <?= $selectedAssignment === '' ? 'checked' : '' ?>>
                            <span><strong>Other work</strong><small>Not in my assigned list</small></span>
                        </label>
                    </div>
                </fieldset>
                <?php endif; ?>

                <?php if ($workTypes): ?>
                <fieldset class="dw-fieldset">
                    <legend>Type of work<?= $typeRequired ? ' *' : ' <small>· optional</small>' ?></legend>
                    <div class="dw-chips" data-dw-types>
                        <?php foreach ($workTypes as $value => $label): ?>
                            <label class="dw-chip"><input type="radio" name="work_type" value="<?= h($value) ?>" <?= ($formValues['work_type'] ?? '') === $value ? 'checked' : '' ?> <?= $typeRequired ? 'required' : '' ?>><span><?= h($label) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php endif; ?>

                <div class="dw-two">
                    <label class="dw-field"><span>Client</span>
                        <select id="client_id" name="client_id" data-dw-client><option value="">No client / internal work</option><?= options(clients(), 'client_name', $formValues['client_id'] ?? null) ?></select>
                        <?php if ($pickTasks): ?><small>Filled automatically from the task</small><?php endif; ?>
                    </label>
                    <label class="dw-field"><span><?= $creativeWork ? 'Title' : 'Task / page' ?></span>
                        <input id="task_title" name="task_title" value="<?= h($formValues['task_title'] ?? '') ?>" maxlength="255" required data-dw-title placeholder="<?= $creativeWork ? 'e.g. Diwali reel' : 'e.g. Contact page' ?>">
                    </label>
                </div>

                <fieldset class="dw-fieldset">
                    <legend>Time spent<?= $creativeWork ? ' *' : '' ?></legend>
                    <div class="dw-chips" data-dw-time>
                        <?php foreach ($timeChips as $value => $label): ?><button type="button" class="dw-chip-btn<?= $timeValue === $value ? ' active' : '' ?>" data-value="<?= h($value) ?>"><?= h($label) ?></button><?php endforeach; ?>
                        <label class="dw-chip-other"><span>Other</span><input id="daily-time" name="time_spent_hours" type="text" maxlength="32" placeholder="02:30" value="<?= h($timeValue) ?>" <?= $creativeWork ? 'required' : '' ?> data-dw-time-input></label>
                    </div>
                    <small class="dw-help"><?= $creativeWork ? '' : 'Optional · ' ?>HH:MM, "1 hour 30 min" or hours · max 24 hours</small>
                </fieldset>

                <fieldset class="dw-fieldset">
                    <legend>Status *</legend>
                    <div class="dw-two dw-status">
                        <label class="dw-option"><input type="radio" name="task_status" value="pending" <?= $statusValue === 'pending' ? 'checked' : '' ?> required><span><strong class="is-pending">Still pending</strong><small>Will continue tomorrow</small></span></label>
                        <label class="dw-option"><input type="radio" name="task_status" value="completed" <?= $statusValue === 'completed' ? 'checked' : '' ?> required><span><strong>Completed</strong><small>Marks the assigned task done</small></span></label>
                    </div>
                </fieldset>

                <?php if (!$creativeWork && metric_definitions($workDepartment)): ?>
                <details class="dw-metrics"<?= array_filter(array_intersect_key($formValues, metric_definitions($workDepartment)), static fn($v) => $v !== '' && $v !== '0' && $v !== 0) ? ' open' : '' ?>>
                    <summary>Quantities <small>· optional</small></summary>
                    <?php render_metrics($workDepartment, $formValues, '-new'); ?>
                </details>
                <?php endif; ?>

                <label class="dw-field"><span>Output link <small>(optional — Drive / Canva / live page link to the final work)</small></span>
                    <input type="url" name="output_link" maxlength="500" placeholder="https://drive.google.com/…" value="<?= h($formValues['output_link'] ?? '') ?>">
                </label>

                <label class="dw-field"><span>Remark</span>
                    <textarea id="remark" name="remark" maxlength="5000" placeholder="Anything your manager should know — changes made, waiting for client content, etc."><?= h($formValues['remark'] ?? '') ?></textarea>
                </label>

                <div class="dw-actions">
                    <button class="dw-btn dw-btn-gold" type="submit" name="after_save" value="history"><?= $isEditing ? 'Save changes' : 'Save &amp; submit' ?></button>
                    <?php if (!$isEditing): ?><button class="dw-btn dw-btn-dark" type="submit" name="after_save" value="another">Save &amp; add another</button><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
        </section>

        <aside class="dw-side">
            <section class="dw-summary">
                <p class="dw-summary-date"><?= h(strtoupper(date('D, j M', strtotime($date)))) ?><?= $isToday ? '' : ' · past day' ?></p>
                <div class="dw-summary-grid">
                    <div><strong><?= count($entries) ?></strong><span>Entries submitted</span></div>
                    <div><strong><?= h($hoursLabel) ?></strong><span>Time logged</span></div>
                    <div><strong class="is-gold"><?= count($pendingAssignments) ?></strong><span>Tasks pending</span></div>
                    <div><strong><?= $dayLogin ? h(date('g:i A', strtotime($dayLogin['login_at']))) : '—' ?></strong><span>Logged in</span></div>
                </div>
            </section>

            <section class="dw-entries">
                <div class="dw-entries-head"><h3><?= $isToday ? 'Today’s entries' : 'Entries for ' . h(date('j M', strtotime($date))) ?></h3><?php if ($sheetSubmitted): ?><span class="dw-badge">Sent to manager</span><?php endif; ?></div>
                <?php if (!$entries): ?>
                    <div class="dw-empty"><span class="dw-empty-icon"><?= icon('document') ?></span><strong>Nothing submitted yet</strong><small>Each entry you save will show here, and you can edit it until end of day.</small></div>
                <?php else: ?>
                    <div class="dw-entry-list">
                        <?php foreach ($entries as $entry): $typeLabel = work_type_label($entry['work_type'] ?? null); ?>
                            <article class="dw-entry<?= $editEntry && (int) $editEntry['id'] === (int) $entry['id'] ? ' is-editing' : '' ?>">
                                <div>
                                    <strong><?= h($entry['task_title'] ?: 'Work entry') ?></strong>
                                    <small><?= h(implode(' · ', array_filter([$entry['client_name'] ?? '', $typeLabel, $entry['time_spent_minutes'] ? format_work_duration($entry['time_spent_minutes']) : '']))) ?></small>
                                    <?php if (!empty($entry['output_link'])): ?><a href="<?= h($entry['output_link']) ?>" target="_blank" rel="noopener noreferrer">Output ↗</a><?php endif; ?>
                                </div>
                                <div class="dw-entry-side">
                                    <span class="dw-pill <?= ($entry['task_status'] ?? '') === 'completed' ? 'is-done' : 'is-pending' ?>"><?= ($entry['task_status'] ?? '') === 'completed' ? 'Done' : 'Pending' ?></span>
                                    <?php if (!$socialWork): ?><a href="employee-daily-work.php?date=<?= h($date) ?>&amp;edit_entry=<?= (int) $entry['id'] ?>#add-work">Edit</a><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <a class="dw-history" href="employee-work-history.php?date=<?= h($date) ?>">View submitted work →</a>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>
<?php if (!$socialBatch): ?>
<script>
(() => {
    const form = document.querySelector('[data-dw-form]');
    if (!form) return;
    const client = form.querySelector('[data-dw-client]'), title = form.querySelector('[data-dw-title]');
    const timeInput = form.querySelector('[data-dw-time-input]'), chips = form.querySelectorAll('[data-dw-time] .dw-chip-btn');
    let autoTitle = title.value;
    // Picking an assigned task fills client, title and (for designers) the work type.
    form.querySelectorAll('[name="assignment_id"]').forEach(radio => radio.addEventListener('change', () => {
        if (!radio.checked) return;
        if (radio.value) {
            if (radio.dataset.client) client.value = radio.dataset.client;
            if (!title.value || title.value === autoTitle) { title.value = radio.dataset.title; autoTitle = radio.dataset.title; }
            const type = radio.dataset.type && form.querySelector(`[name="work_type"][value="${radio.dataset.type}"]`);
            if (type) type.checked = true;
        } else if (title.value === autoTitle) {
            title.value = ''; autoTitle = ''; client.value = ''; title.focus();
        }
    }));
    // Time chips write into the "Other" box so one field is always submitted.
    const syncChips = () => chips.forEach(c => c.classList.toggle('active', c.dataset.value === timeInput.value.trim()));
    chips.forEach(chip => chip.addEventListener('click', () => { timeInput.value = chip.dataset.value; syncChips(); }));
    timeInput.addEventListener('input', syncChips);
})();
</script>
<?php endif; ?>
