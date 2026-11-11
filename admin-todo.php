<?php
require __DIR__ . '/includes/layout.php';
$user = require_roles(['admin','manager']);

function todo_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') return null;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Please enter valid dates (YYYY-MM-DD).');
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    [$action, $target] = array_pad(explode(':', (string) ($_POST['action'] ?? 'save'), 2), 2, '');
    $tasks = is_array($_POST['tasks'] ?? null) ? $_POST['tasks'] : [];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $savedIds = [];
        foreach ($tasks as $index => $task) {
            if (!is_array($task)) continue;
            $id = (int) ($task['id'] ?? 0);
            $title = trim((string) ($task['title'] ?? ''));
            $expected = todo_date($task['expected_date'] ?? null);
            $end = todo_date($task['end_date'] ?? null);
            $remark = trim((string) ($task['remark'] ?? ''));
            if ($title === '' && $expected === null && $end === null && $remark === '' && !$id) continue;
            if ($title === '') throw new InvalidArgumentException('Every task needs a task name.');
            if (mb_strlen($title) > 500) throw new InvalidArgumentException('Task names can be at most 500 characters.');
            if ($id) {
                $stmt = $pdo->prepare('UPDATE todo_tasks SET title=?,expected_date=?,end_date=?,remark=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $expected, $end, $remark, $id, $user['id']]);
                if (!one('SELECT id FROM todo_tasks WHERE id=? AND user_id=?', [$id, $user['id']])) throw new InvalidArgumentException('That task no longer exists. Refresh the page.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO todo_tasks (user_id,title,expected_date,end_date,remark) VALUES (?,?,?,?,?)');
                $stmt->execute([$user['id'], $title, $expected, $end, $remark]);
                $id = (int) $pdo->lastInsertId();
            }
            $savedIds[(string) $index] = $id;
        }
        $targetId = $savedIds[$target] ?? 0;
        $message = 'Tasks saved.';
        if ($action === 'complete' && $targetId) {
            $pdo->prepare("UPDATE todo_tasks SET status='completed',completed_at=NOW(),end_date=COALESCE(end_date,CURDATE()) WHERE id=? AND user_id=?")->execute([$targetId, $user['id']]);
            $message = 'Task marked as completed.';
        } elseif ($action === 'reopen' && $targetId) {
            $pdo->prepare("UPDATE todo_tasks SET status='open',completed_at=NULL WHERE id=? AND user_id=?")->execute([$targetId, $user['id']]);
            $message = 'Task reopened.';
        } elseif ($action === 'delete' && $targetId) {
            $pdo->prepare('DELETE FROM todo_tasks WHERE id=? AND user_id=?')->execute([$targetId, $user['id']]);
            $message = 'Task deleted.';
        }
        $pdo->commit();
        flash($message);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash(mutation_error($e), 'error');
    }
    redirect('admin-todo.php');
}

$tasks = rows('SELECT id,title,expected_date,end_date,remark,status FROM todo_tasks WHERE user_id=? ORDER BY id', [$user['id']]);
if (!$tasks) $tasks = [['id' => '', 'title' => '', 'expected_date' => '', 'end_date' => '', 'remark' => '', 'status' => 'open']];
$openCount = count(array_filter($tasks, fn($t) => $t['id'] && $t['status'] === 'open'));
$doneCount = count(array_filter($tasks, fn($t) => $t['status'] === 'completed'));
$overdueCount = count(array_filter($tasks, fn($t) => $t['id'] && $t['status'] === 'open' && $t['expected_date'] && $t['expected_date'] < today()));
$totalCount = count(array_filter($tasks, fn($t) => (bool) $t['id']));

function todo_card(int|string $index, array $task): void
{
    $done = $task['status'] === 'completed';
    $late = $task['expected_date'] && $task['end_date'] && $task['end_date'] > $task['expected_date'];
    $overdue = !$done && $task['expected_date'] && !$task['end_date'] && $task['expected_date'] < today();
    $state = $done ? 'done' : (($late || $overdue) ? 'late' : 'open');
    $number = is_int($index) ? $index + 1 : '';
    $name = 'tasks[' . $index . ']';
    $due = '';
    if ($done) {
        $due = 'Completed' . ($task['end_date'] ? ' on ' . date('d M Y', strtotime($task['end_date'])) : '') . ($late ? ' · after expected date' : '');
    } elseif ($task['expected_date']) {
        $days = (int) round((strtotime($task['expected_date']) - strtotime(today())) / 86400);
        $due = $days > 1 ? "Due in $days days" : ($days === 1 ? 'Due tomorrow' : ($days === 0 ? 'Due today' : 'Overdue by ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's')));
    } elseif ($task['id']) {
        $due = 'No expected date set';
    }
    ?><article class="todo-card todo-<?= $state ?>" data-todo-card>
        <input type="hidden" name="<?= $name ?>[id]" value="<?= h($task['id']) ?>">
        <header class="todo-card-head">
            <span class="todo-num" data-todo-num><?= $number === '' ? '' : str_pad((string) $number, 2, '0', STR_PAD_LEFT) ?></span>
            <div class="todo-head-text"><h2 data-todo-number>Task-<?= $number ?></h2><?php if ($due): ?><small class="todo-due"><?= h($due) ?></small><?php elseif (!$task['id']): ?><small class="todo-due">New task · not saved yet</small><?php endif; ?></div>
            <span class="todo-pill todo-pill-<?= $state ?>"><?= $done ? 'Completed' : ($late ? 'Late' : ($overdue ? 'Overdue' : 'Pending')) ?></span>
            <?php if ($task['id']): ?><button type="submit" class="todo-icon-btn danger" name="action" value="delete:<?= $index ?>" data-confirm-click="Delete this task?" title="Delete task" aria-label="Delete task"><?= icon('delete') ?></button><?php else: ?><button type="button" class="todo-icon-btn" data-todo-remove title="Remove task" aria-label="Remove task">×</button><?php endif; ?>
        </header>
        <div class="todo-card-body">
            <label class="todo-field"><span class="todo-label">Task</span><input type="text" name="<?= $name ?>[title]" value="<?= h($task['title']) ?>" maxlength="500" placeholder="What needs to be done?" required></label>
            <div class="todo-dates">
                <label class="todo-field"><span class="todo-label">Expected date</span><input type="date" name="<?= $name ?>[expected_date]" value="<?= h($task['expected_date']) ?>" data-todo-expected></label>
                <label class="todo-field"><span class="todo-label">Ending date</span><input type="date" name="<?= $name ?>[end_date]" value="<?= h($task['end_date']) ?>" data-todo-end class="<?= $late ? 'is-late' : '' ?>"></label>
            </div>
            <p class="todo-late-note"<?= $late ? '' : ' hidden' ?> data-todo-late-note>⚠ Ending date is later than the expected date</p>
            <label class="todo-field"><span class="todo-label">Remark</span><textarea name="<?= $name ?>[remark]" rows="2" placeholder="Additional notes (optional)"><?= h($task['remark']) ?></textarea></label>
        </div>
        <footer class="todo-card-foot">
            <?php if ($done): ?><button type="submit" class="todo-btn todo-btn-ghost" name="action" value="reopen:<?= $index ?>">↺ Reopen</button>
            <?php else: ?><button type="submit" class="todo-btn todo-btn-success" name="action" value="complete:<?= $index ?>">✓ Mark completed</button><?php endif; ?>
        </footer>
    </article><?php
}

page_start('To Do', 'admin-todo.php');
?>
<form method="post" class="todo-page" id="todoForm"><?= csrf_field() ?>
    <section class="todo-summary" aria-label="Task summary">
        <div class="todo-stat"><span>Total tasks</span><strong><?= $totalCount ?></strong></div>
        <div class="todo-stat todo-stat-open"><span>Pending</span><strong><?= $openCount ?></strong></div>
        <div class="todo-stat todo-stat-late"><span>Overdue</span><strong><?= $overdueCount ?></strong></div>
        <div class="todo-stat todo-stat-done"><span>Completed</span><strong><?= $doneCount ?></strong></div>
    </section>
    <div class="todo-toolbar">
        <div><h2>My tasks</h2><p class="muted">Plan your work, set the expected date and mark each task completed when it's done.</p></div>
        <div class="todo-toolbar-actions"><button type="button" class="todo-btn todo-btn-outline" data-todo-add>＋ More</button><button type="submit" class="todo-btn todo-btn-primary" name="action" value="save">Save tasks</button></div>
    </div>
    <div class="todo-list" data-todo-list>
        <?php foreach ($tasks as $i => $task) todo_card($i, $task); ?>
    </div>
    <template id="todoTemplate"><?php todo_card('__INDEX__', ['id' => '', 'title' => '', 'expected_date' => '', 'end_date' => '', 'remark' => '', 'status' => 'open']); ?></template>
</form>
<script>
(function () {
    var form = document.getElementById('todoForm'), list = form.querySelector('[data-todo-list]'), tpl = document.getElementById('todoTemplate');
    var next = list.querySelectorAll('[data-todo-card]').length;
    function renumber() {
        list.querySelectorAll('[data-todo-card]').forEach(function (card, i) {
            card.querySelector('[data-todo-number]').textContent = 'Task-' + (i + 1);
            card.querySelector('[data-todo-num]').textContent = String(i + 1).padStart(2, '0');
        });
    }
    function checkLate(card) {
        var exp = card.querySelector('[data-todo-expected]'), end = card.querySelector('[data-todo-end]'), note = card.querySelector('[data-todo-late-note]');
        var late = exp.value && end.value && end.value > exp.value;
        end.classList.toggle('is-late', !!late); note.hidden = !late;
    }
    form.querySelector('[data-todo-add]').addEventListener('click', function () {
        var html = tpl.innerHTML.split('__INDEX__').join(String(next++));
        list.insertAdjacentHTML('beforeend', html);
        renumber();
        var card = list.lastElementChild; card.querySelector('input[type=text]').focus();
    });
    list.addEventListener('input', function (e) { var card = e.target.closest('[data-todo-card]'); if (card && e.target.type === 'date') checkLate(card); });
    list.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-todo-remove]');
        if (remove) { remove.closest('[data-todo-card]').remove(); renumber(); return; }
        var del = e.target.closest('[data-confirm-click]');
        if (del && !confirm(del.getAttribute('data-confirm-click'))) e.preventDefault();
    });
    // Delete/reopen should not be blocked by empty required fields on other cards.
    form.addEventListener('click', function (e) {
        var btn = e.target.closest('button[type=submit]');
        if (btn) form.noValidate = /^(delete|reopen):/.test(btn.value);
    });
})();
</script>
<?php page_end();
