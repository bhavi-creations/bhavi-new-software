<?php
require_once __DIR__.'/includes/client-portal.php';
$client=own_client(); $month=client_month(); $reports=client_month_reports((int)$client['id'],$month); $byDate=[];
foreach ($reports as $report) $byDate[$report['report_date']][]=$report;
$monthEnd=$month->modify('last day of this month');
$holidays=[];
foreach (rows('SELECT holiday_date,holiday_name FROM holidays WHERE is_published=1 AND holiday_date BETWEEN ? AND ?',[$month->format('Y-m-d'),$monthEnd->format('Y-m-d')]) as $holiday) $holidays[$holiday['holiday_date']]=$holiday['holiday_name'];

/** Delivered = uploaded; anything else saved is still in progress. */
$wrStatus=static fn(array $r): array => $r['uploaded_at']?['Delivered','is-done']:($r['approved_at']?['Approved','is-progress']:($r['sent_at']?['Sent for approval','is-progress']:($r['started_at']?['In progress','is-progress']:['Received','is-progress'])));
$wrTypeLabel=['reel'=>'Reel','poster'=>'Poster','carousel'=>'Carousel','other'=>'Other work'];

// Monthly targets vs delivered (uploaded) items.
$delivered=[]; foreach ($reports as $report) if ($report['uploaded_at']) $delivered[$report['work_type']]=($delivered[$report['work_type']]??0)+1;
$targets=['reel'=>['monthly_reels','Reels'],'poster'=>['monthly_posters','Posters'],'carousel'=>['monthly_carousels','Carousels']];

$today=today();
$selected=($month->format('Y-m')===date('Y-m'))?$today:($byDate?array_key_last($byDate):$month->format('Y-m-d'));
$thisWeekStart=(new DateTimeImmutable($today))->modify('-'.((int)date('N')-1).' days');
$prevMonth=$month->modify('-1 month');
$ranges=[
    'Today'=>[$today,$today],
    'This week'=>[$thisWeekStart->format('Y-m-d'),$thisWeekStart->modify('+6 days')->format('Y-m-d')],
    $month->format('F')=>[$month->format('Y-m-d'),$monthEnd->format('Y-m-d')],
    $prevMonth->format('F Y')=>[$prevMonth->format('Y-m-d'),$prevMonth->modify('last day of this month')->format('Y-m-d')],
];
page_start('Work reports','client-reports.php');
?>
<div class="wr-page">
    <header class="wr-head">
        <div>
            <p class="wr-crumb">Dashboard / Work reports</p>
            <h1 class="wr-title">Work reports</h1>
            <p class="wr-sub">Everything our team delivers for you, day by day.</p>
        </div>
        <nav class="wr-month" aria-label="Choose month">
            <a href="?month=<?= $prevMonth->format('Y-m') ?>" aria-label="Previous month">‹</a>
            <strong><?= h($month->format('F Y')) ?></strong>
            <a href="?month=<?= $month->modify('+1 month')->format('Y-m') ?>" aria-label="Next month">›</a>
        </nav>
    </header>

    <div class="wr-targets">
        <?php foreach ($targets as $type=>[$column,$label]): $target=(int)$client[$column]; $done=$delivered[$type]??0; $pct=$target>0?min(100,round($done/$target*100)):($done>0?100:0); ?>
            <div class="wr-target">
                <p class="wr-target-count"><strong><?= $done ?></strong><small>/<?= $target ?></small></p>
                <div class="wr-target-bar"><span><?= h($label) ?></span><i><b style="width:<?= $pct ?>%"></b></i><small><?= $done ?> delivered · <?= max(0,$target-$done) ?> to go</small></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="wr-grid">
        <section class="wr-card wr-cal-card">
            <div class="wr-card-head"><h2>Delivery calendar</h2><span>Tap a day to see what was delivered</span></div>
            <div class="wr-cal" role="grid" aria-label="Delivery calendar for <?= h($month->format('F Y')) ?>">
                <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i=>$day): ?><div class="wr-weekday<?= $i===6?' is-sun':'' ?>"><?= $day ?></div><?php endforeach; ?>
                <?php $offset=(int)$month->format('N')-1; for ($i=0;$i<$offset;$i++): ?><div class="wr-blank" aria-hidden="true"></div><?php endfor; ?>
                <?php for ($n=1;$n<=(int)$month->format('t');$n++): $date=$month->format('Y-m-').sprintf('%02d',$n); $items=$byDate[$date]??[]; $dow=(int)(new DateTimeImmutable($date))->format('N');
                    $done=count(array_filter($items,static fn($r)=>(bool)$r['uploaded_at'])); $open=count($items)-$done;
                    $classes=['wr-day']; if ($dow===7) $classes[]='is-sun'; if ($date===$today) $classes[]='is-today'; if ($date>$today) $classes[]='is-upcoming'; if ($items) $classes[]='has-items'; if ($date===$selected) $classes[]='is-selected'; ?>
                    <button type="button" class="<?= implode(' ',$classes) ?>" data-wr-pick="<?= $date ?>" aria-pressed="<?= $date===$selected?'true':'false' ?>" aria-label="<?= h((new DateTimeImmutable($date))->format('l j F')) ?><?= $items?': '.count($items).' updates':'' ?>">
                        <span class="wr-day-top"><b><?= $n ?></b><?php if ($date===$today): ?><em>Today</em><?php endif; ?></span>
                        <?php if (isset($holidays[$date])): ?><span class="wr-holiday" title="<?= h($holidays[$date]) ?>"><?= h($holidays[$date]) ?></span><?php endif; ?>
                        <?php if ($items): ?><span class="wr-dots"><?php if ($done): ?><i class="is-done"></i><?= $done ?><?php endif; ?><?php if ($open): ?><i class="is-progress"></i><?= $open ?><?php endif; ?></span><?php endif; ?>
                    </button>
                <?php endfor; ?>
            </div>
            <div class="wr-legend"><span><i class="is-done"></i>Delivered</span><span><i class="is-progress"></i>In progress</span><span><i class="is-sun"></i>Sunday</span><span><i class="is-upcoming"></i>Upcoming</span></div>
        </section>

        <aside class="wr-side">
            <section class="wr-card wr-panel">
                <?php for ($day=$month;$day<=$monthEnd;$day=$day->modify('+1 day')): $date=$day->format('Y-m-d'); $items=$byDate[$date]??[]; ?>
                    <div class="wr-day-panel" id="reports-<?= $date ?>" data-wr-panel="<?= $date ?>"<?= $date===$selected?'':' hidden' ?>>
                        <p class="wr-eyebrow"><?= h(strtoupper($day->format('D, j M Y'))) ?></p>
                        <h3><?= $date===$today?'Today’s deliveries':'Deliveries' ?></h3>
                        <?php if (!$items): ?>
                            <div class="wr-empty">
                                <span><?= icon('calendar') ?></span>
                                <strong><?= $reports?'Nothing delivered on this day':($date<=$today?'Nothing delivered yet this month':'No work updates this month') ?></strong>
                                <small>No report added for this date. Each reel, poster or carousel appears here with its details and a PDF download as soon as our team updates it.</small>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($items as $report): [$statusLabel,$statusClass]=$wrStatus($report); ?>
                            <article class="wr-item">
                                <div class="wr-item-head">
                                    <span class="wr-type"><?= h($wrTypeLabel[$report['work_type']]??'Work') ?></span>
                                    <em class="wr-status <?= $statusClass ?>"><?= h($statusLabel) ?></em>
                                </div>
                                <strong class="wr-item-title"><?= h($report['title']?:'Untitled work') ?></strong>
                                <small class="wr-item-meta"><?= $report['uploaded_at']?'Posted '.h(date('j M, h:i A',strtotime($report['uploaded_at']))):'Updated '.h(date('j M',strtotime($report['updated_at']))) ?><?= $report['platform']!==''?' · '.h($report['platform']):'' ?></small>
                                <div class="wr-item-actions">
                                    <button type="button" data-dialog="wr-report-<?= (int)$report['id'] ?>"><?= icon('eye') ?> Details</button>
                                    <a href="download-client-report.php?id=<?= (int)$report['id'] ?>"><?= icon('download') ?> PDF</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endfor; ?>
            </section>

            <section class="wr-download">
                <h2>Download a report</h2>
                <form method="get" action="download-client-report.php" data-wr-download>
                    <input type="hidden" name="period" value="custom">
                    <input type="hidden" name="skip_empty" value="1">
                    <div class="wr-chips">
                        <?php foreach ($ranges as $label=>[$from,$to]): ?><button type="button" class="wr-chip<?= $label===$month->format('F')?' active':'' ?>" data-from="<?= $from ?>" data-to="<?= $to ?>"><?= h($label) ?></button><?php endforeach; ?>
                        <button type="button" class="wr-chip" data-custom>Custom dates</button>
                    </div>
                    <div class="wr-range">
                        <label><span>From</span><input type="date" name="from" value="<?= $month->format('Y-m-d') ?>" required data-wr-from></label>
                        <label><span>To</span><input type="date" name="to" value="<?= $monthEnd->format('Y-m-d') ?>" required data-wr-to></label>
                    </div>
                    <button type="submit" class="wr-gold"><?= icon('download') ?> Download PDF</button>
                    <p>Only days with deliveries are included in the report.</p>
                </form>
            </section>
        </aside>
    </div>
</div>
<?php foreach ($reports as $report): ?>
<dialog class="detail-dialog wr-dialog" id="wr-report-<?= (int)$report['id'] ?>" aria-labelledby="wr-report-title-<?= (int)$report['id'] ?>">
    <div class="dialog-heading"><h2 id="wr-report-title-<?= (int)$report['id'] ?>"><?= h($report['title']?:'Untitled work') ?></h2><button type="button" data-close-dialog aria-label="Close details">&times;</button></div>
    <dl class="details-list"><dt>Report date</dt><dd><?= h((new DateTimeImmutable($report['report_date']))->format('d M Y, l')) ?></dd><?php foreach (client_report_fields($report) as $label=>$value): ?><dt><?= h($label) ?></dt><dd><?= h($value) ?></dd><?php endforeach; ?></dl>
    <p><a class="button secondary" href="download-client-report.php?id=<?= (int)$report['id'] ?>"><?= icon('download') ?> Download PDF</a></p>
</dialog>
<?php endforeach; ?>
<script>
(() => {
    // Calendar: show the picked day's deliveries in the side panel.
    const days = document.querySelectorAll('[data-wr-pick]'), panels = document.querySelectorAll('[data-wr-panel]');
    days.forEach(day => day.addEventListener('click', () => {
        days.forEach(d => { const on = d === day; d.classList.toggle('is-selected', on); d.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        panels.forEach(p => { p.hidden = p.dataset.wrPanel !== day.dataset.wrPick; });
        if (window.innerWidth < 1000) document.querySelector('.wr-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    // Download: quick ranges fill From / To.
    const form = document.querySelector('[data-wr-download]'), from = form.querySelector('[data-wr-from]'), to = form.querySelector('[data-wr-to]'), chips = form.querySelectorAll('.wr-chip');
    const mark = chip => chips.forEach(c => c.classList.toggle('active', c === chip));
    chips.forEach(chip => chip.addEventListener('click', () => {
        mark(chip);
        if (chip.dataset.custom !== undefined) { from.focus(); if (from.showPicker) try { from.showPicker(); } catch (e) {} return; }
        from.value = chip.dataset.from; to.value = chip.dataset.to;
    }));
    [from, to].forEach(input => input.addEventListener('input', () => mark(Array.from(chips).find(c => c.dataset.from === from.value && c.dataset.to === to.value) || form.querySelector('[data-custom]'))));
    form.addEventListener('submit', e => { if (from.value > to.value) { e.preventDefault(); alert('From date must be on or before To date.'); } });
})();
</script>
<?php page_end(); ?>
