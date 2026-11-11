<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/calendar.php';
$viewer = require_roles();
$error = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId && !is_staff()) fail(403, 'Only administrators and managers can edit holidays.');
$record = $editId ? one('SELECT * FROM holidays WHERE id=? AND is_published=1', [$editId]) : null;
if ($editId && !$record) fail(404, 'Holiday not found.');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_roles(['admin', 'manager']); check_csrf();
    try {
        if (($_POST['action'] ?? '') === 'delete_holiday') {
            query('UPDATE holidays SET is_published=0 WHERE id=?', [positive_id($_POST['id'] ?? null)]);
            flash('Holiday deleted.');
        } elseif (($_POST['action'] ?? '') === 'save_holiday') {
            $name = text_input('holiday_name', 150); $date = date_input('holiday_date'); $description = text_input('description', 3000, false);
            if ($editId) query('UPDATE holidays SET holiday_name=?,holiday_date=?,description=? WHERE id=? AND is_published=1', [$name, $date, $description, $editId]);
            else {
                $existing = one('SELECT id,is_published FROM holidays WHERE holiday_date=?', [$date]);
                if ($existing && $existing['is_published']) throw new InvalidArgumentException('A holiday is already listed for this date. Edit that holiday instead.');
                if ($existing) query('UPDATE holidays SET holiday_name=?,description=?,created_by=?,is_published=1 WHERE id=?', [$name, $description, $user['id'], $existing['id']]);
                else query('INSERT INTO holidays (holiday_name,holiday_date,description,created_by) VALUES (?,?,?,?)', [$name, $date, $description, $user['id']]);
            }
            flash('Holiday saved. It is now visible to the whole team.');
        } else throw new InvalidArgumentException('Invalid action.');
        redirect('admin-holidays.php' . (!empty($_POST['holiday_date']) ? '?month=' . substr((string) $_POST['holiday_date'], 0, 7) : ''));
    } catch (Throwable $e) { $error = mutation_error($e); }
}

$holidays = rows('SELECT h.*,u.full_name AS added_by FROM holidays h JOIN users u ON u.id=h.created_by WHERE h.is_published=1 ORDER BY h.holiday_date');
$byDate = [];
foreach ($holidays as $holiday) $byDate[$holiday['holiday_date']][] = $holiday;

$view = ($_GET['view'] ?? '') === 'year' ? 'year' : 'month';
$month = calendar_month();
$first = new DateTimeImmutable($month . '-01');
$daysInMonth = (int) $first->format('t');
$todayYmd = today();
$prevMonth = $first->modify('-1 month')->format('Y-m');
$nextMonth = $first->modify('+1 month')->format('Y-m');
$year = (int) ($_GET['year'] ?? $first->format('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

// Month at a glance
$sundays = 0; $holidayDays = 0; $monthHolidays = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = $month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
    $isSunday = date('w', strtotime($date)) === '0';
    if ($isSunday) $sundays++;
    if (!empty($byDate[$date])) { if (!$isSunday) $holidayDays++; foreach ($byDate[$date] as $hol) $monthHolidays[] = $hol; }
}
$workingDays = $daysInMonth - $sundays - $holidayDays;
$upcomingInMonth = array_filter($monthHolidays, static fn($hd) => $hd['holiday_date'] >= $todayYmd);
$nextHoliday = one('SELECT holiday_name,holiday_date FROM holidays WHERE is_published=1 AND holiday_date>? ORDER BY holiday_date LIMIT 1', [$first->format('Y-m-t')]);
$relative = static function (string $date) use ($todayYmd): string {
    $diff = (int) round((strtotime($date) - strtotime($todayYmd)) / 86400);
    if ($diff === 0) return 'Today';
    if ($diff === 1) return 'Tomorrow';
    if ($diff === -1) return 'Yesterday';
    return $diff > 0 ? 'In ' . $diff . ' days' : abs($diff) . ' days ago';
};
$query = static fn(array $p): string => 'admin-holidays.php?' . http_build_query($p);

page_start('Holidays', 'admin-holidays.php'); error_message($error);
?>
<div class="hc-page">
    <header class="hc-head">
        <div>
            <p class="hc-crumb">Dashboard / Holidays</p>
            <h1 class="hc-title">Holiday calendar</h1>
            <p class="hc-sub">Office holidays and weekly offs at Bhavi Creations.</p>
        </div>
        <nav class="hc-seg" aria-label="Calendar view">
            <a href="<?= h($query(['month' => $month])) ?>" class="<?= $view === 'month' ? 'active' : '' ?>">Month</a>
            <a href="<?= h($query(['view' => 'year', 'year' => $first->format('Y'), 'month' => $month])) ?>" class="<?= $view === 'year' ? 'active' : '' ?>">Year list</a>
        </nav>
    </header>

    <div class="hc-grid">
        <?php if ($view === 'month'): ?>
        <section class="hc-card hc-cal">
            <div class="hc-cal-head">
                <a class="hc-arrow" href="<?= h($query(['month' => $prevMonth])) ?>" aria-label="Previous month">‹</a>
                <div class="hc-cal-title">
                    <h2><?= h($first->format('F Y')) ?></h2>
                    <?php if ($month !== date('Y-m')): ?><a href="admin-holidays.php">Back to today</a><?php else: ?><span>This month</span><?php endif; ?>
                </div>
                <a class="hc-arrow" href="<?= h($query(['month' => $nextMonth])) ?>" aria-label="Next month">›</a>
            </div>
            <div class="hc-week" aria-hidden="true">
                <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName): ?><span class="<?= $dayName === 'Sun' ? 'is-sun' : '' ?>"><?= $dayName ?></span><?php endforeach; ?>
            </div>
            <div class="hc-days">
                <?php for ($i = 1; $i < (int) $first->format('N'); $i++): ?><span class="hc-blank"></span><?php endfor; ?>
                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    $date = $month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                    $dayHolidays = $byDate[$date] ?? [];
                    $isSunday = date('w', strtotime($date)) === '0';
                    $classes = 'hc-day' . ($dayHolidays ? ' is-holiday' : ($isSunday ? ' is-off' : '')) . ($date === $todayYmd ? ' is-today' : '') . ($date < $todayYmd ? ' is-past' : '');
                ?>
                    <div class="<?= $classes ?>">
                        <span class="hc-num"><?= $d ?><?php if ($date === $todayYmd): ?> <em>Today</em><?php endif; ?></span>
                        <?php foreach ($dayHolidays as $hol): ?>
                            <button type="button" class="hc-chip" data-dialog="holiday-<?= (int) $hol['id'] ?>" title="<?= h($hol['holiday_name']) ?>"><?= h($hol['holiday_name']) ?></button>
                        <?php endforeach; ?>
                        <?php if ($dayHolidays): ?><small>Office holiday</small><?php elseif ($isSunday): ?><small>Weekly off</small><?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="hc-legend"><span><i class="is-holiday"></i>Office holiday</span><span><i class="is-off"></i>Weekly off (Sunday)</span><span><i class="is-today"></i>Today</span></div>
        </section>
        <?php else: ?>
        <section class="hc-card hc-year">
            <div class="hc-cal-head">
                <a class="hc-arrow" href="<?= h($query(['view' => 'year', 'year' => $year - 1, 'month' => $month])) ?>" aria-label="Previous year">‹</a>
                <div class="hc-cal-title"><h2><?= $year ?> holidays</h2><span><?= count(array_filter($holidays, static fn($hd) => (int) substr($hd['holiday_date'], 0, 4) === $year)) ?> office holidays</span></div>
                <a class="hc-arrow" href="<?= h($query(['view' => 'year', 'year' => $year + 1, 'month' => $month])) ?>" aria-label="Next year">›</a>
            </div>
            <?php $lastMonth = null; $any = false; foreach ($holidays as $hol): if ((int) substr($hol['holiday_date'], 0, 4) !== $year) continue; $any = true; $m = substr($hol['holiday_date'], 0, 7); ?>
                <?php if ($m !== $lastMonth): $lastMonth = $m; ?><h3 class="hc-year-month"><?= h(date('F', strtotime($m . '-01'))) ?></h3><?php endif; ?>
                <div class="hc-year-row<?= $hol['holiday_date'] < $todayYmd ? ' is-past' : '' ?>">
                    <span class="hc-date"><small><?= h(strtoupper(date('D', strtotime($hol['holiday_date'])))) ?></small><b><?= h(date('j', strtotime($hol['holiday_date']))) ?></b></span>
                    <div class="hc-year-text"><strong><?= h($hol['holiday_name']) ?></strong><small><?= h(date('j M Y', strtotime($hol['holiday_date']))) ?> · <?= h($relative($hol['holiday_date'])) ?><?= $hol['description'] ? ' · ' . h($hol['description']) : '' ?></small></div>
                    <div class="hc-actions">
                        <button type="button" class="hc-icon-btn" data-dialog="holiday-<?= (int) $hol['id'] ?>" title="View details" aria-label="View details"><?= icon('eye') ?></button>
                        <?php if (is_staff()): ?>
                            <a class="hc-icon-btn" href="<?= h($query(['edit' => $hol['id'], 'month' => $m])) ?>#holiday-form" title="Edit holiday" aria-label="Edit holiday"><?= icon('edit') ?></a>
                            <?php delete_button((int) $hol['id'], 'delete_holiday', 'Delete this holiday?'); ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$any): ?><div class="hc-empty">No office holidays listed for <?= $year ?> yet.</div><?php endif; ?>
        </section>
        <?php endif; ?>

        <aside class="hc-side">
            <section class="hc-glance">
                <p class="hc-glance-label"><?= h(strtoupper($first->format('F'))) ?> at a glance</p>
                <div class="hc-glance-grid">
                    <div><strong><?= $workingDays ?></strong><span>Working days</span></div>
                    <div><strong class="is-gold"><?= count($monthHolidays) ?></strong><span><?= count($monthHolidays) === 1 ? 'Holiday' : 'Holidays' ?></span></div>
                    <div><strong><?= $sundays ?></strong><span>Sundays</span></div>
                </div>
            </section>

            <section class="hc-card hc-list">
                <h3>Holidays this month</h3>
                <?php foreach ($monthHolidays as $hol): ?>
                    <button type="button" class="hc-list-item<?= $hol['holiday_date'] < $todayYmd ? ' is-past' : '' ?>" data-dialog="holiday-<?= (int) $hol['id'] ?>">
                        <span class="hc-date"><small><?= h(strtoupper(date('D', strtotime($hol['holiday_date'])))) ?></small><b><?= h(date('j', strtotime($hol['holiday_date']))) ?></b></span>
                        <span class="hc-list-text"><strong><?= h($hol['holiday_name']) ?></strong><small><?= h($relative($hol['holiday_date'])) ?></small></span>
                    </button>
                <?php endforeach; ?>
                <?php if (!$upcomingInMonth): ?>
                    <div class="hc-empty">No <?= $monthHolidays ? 'more ' : '' ?>holidays scheduled in <?= h($first->format('F')) ?><?php if ($nextHoliday): ?> · next: <a href="<?= h($query(['month' => substr($nextHoliday['holiday_date'], 0, 7)])) ?>"><?= h($nextHoliday['holiday_name']) ?>, <?= h(date('j M', strtotime($nextHoliday['holiday_date']))) ?></a><?php endif; ?></div>
                <?php endif; ?>
            </section>

            <?php if (is_staff()): ?>
            <section class="hc-card hc-form" id="holiday-form">
                <h3><?= $record ? 'Edit holiday' : 'Add holiday' ?></h3>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_holiday">
                    <label class="hc-field"><span>Holiday name</span><input id="holiday_name" name="holiday_name" value="<?= h($_POST['holiday_name'] ?? $record['holiday_name'] ?? '') ?>" maxlength="150" required placeholder="e.g. Diwali"></label>
                    <label class="hc-field"><span>Date</span><input type="date" id="holiday_date" name="holiday_date" value="<?= h($_POST['holiday_date'] ?? $record['holiday_date'] ?? today()) ?>" required></label>
                    <label class="hc-field"><span>Description <small>(optional)</small></span><textarea id="description" name="description" maxlength="3000"><?= h($_POST['description'] ?? $record['description'] ?? '') ?></textarea></label>
                    <div class="hc-form-actions"><button class="hc-gold-btn" type="submit">Save holiday</button><?php if ($record): ?><a class="hc-link" href="<?= h($query(['month' => $month])) ?>">Cancel</a><?php endif; ?></div>
                </form>
            </section>
            <?php else: ?>
            <section class="hc-card hc-plan">
                <h3>Planning time off?</h3>
                <p>Check holidays first, then apply — your manager gets a notification.</p>
                <a class="hc-gold-btn" href="apply-leaves.php">Apply for leave</a>
            </section>
            <?php endif; ?>
        </aside>
    </div>
</div>
<?php foreach ($holidays as $holiday) detail_dialog('holiday-' . $holiday['id'], $holiday['holiday_name'], ['Name' => $holiday['holiday_name'], 'Date' => date('l, j F Y', strtotime($holiday['holiday_date'])), 'Description' => $holiday['description'], 'Added by' => $holiday['added_by']]); page_end(); ?>
