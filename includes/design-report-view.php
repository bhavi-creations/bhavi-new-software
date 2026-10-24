<?php
$labels = ['poster' => 'Posters', 'video_editing' => 'Reels / Videos', 'carousel' => 'Carousels'];
$pretty = static fn($date) => (new DateTimeImmutable($date))->format('d-m-Y');
?>
<style>
    .design-report {
        --ink: #153666;
        color: var(--ink);
        background: #eff7ff;
        padding: 18px;
        border-radius: 20px;
        margin: 22px 0;
        font-family: Arial, sans-serif;
        box-shadow: 0 8px 30px #195b9610
    }

    .design-report * {
        box-sizing: border-box
    }

    .design-report .report-hero {
        background: radial-gradient(ellipse at top right, #48c6ed, transparent 55%), linear-gradient(120deg, #112d59, #244f9a);
        color: white;
        padding: 34px;
        border-radius: 15px;
        position: relative;
        overflow: hidden
    }

    .report-hero small {
        letter-spacing: 2px
    }

    .design-report h1 {
        font-size: 36px;
        color: white;
        margin: 10px 0;
        line-height: 1.2
    }

    .design-report h2 {
        font-size: 26px;
        color: #153666;
        margin: 0 0 18px
    }

    .report-hero p {
        margin: 0;
        color: white
    }

    .design-report .report-box {
        background: #ffffffdb;
        border: 1px solid #d2e5ff;
        border-radius: 18px;
        padding: 22px;
        margin-top: 16px
    }

    .report-meta,
    .report-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px
    }

    .report-meta div,
    .report-stat {
        padding: 18px;
        border-radius: 14px;
        background: #eaf4ff
    }

    .report-meta div:nth-child(3n+2),
    .report-stat:nth-child(2) {
        background: #f4edff
    }

    .report-meta div:nth-child(3n),
    .report-stat:nth-child(3) {
        background: #e6f8f2
    }

    .report-meta small {
        display: block;
        text-transform: uppercase;
        font-size: 11px;
        margin-bottom: 7px;
        letter-spacing: .7px
    }

    .report-meta strong {
        font-size: 17px
    }

    .report-stat strong {
        display: block;
        font-size: 34px;
        margin-bottom: 6px
    }

    .report-summary-heading {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 12px
    }

    .report-summary-heading h2 {
        margin: 0
    }

    .report-total {
        background: #d8eaff;
        border-radius: 12px;
        padding: 10px 16px;
        font-weight: bold
    }

    .design-report table {
        border-collapse: collapse;
        width: 100%;
        font-size: 12px;
        table-layout: fixed
    }

    .design-report th {
        background: #1c416e;
        color: white;
        padding: 10px 7px;
        text-align: left
    }

    .design-report td {
        border: 1px solid #dbe8f6;
        padding: 8px 7px;
        vertical-align: top;
        white-space: normal;
        overflow-wrap: anywhere;
        color: #233c60
    }

    .design-report tbody tr:nth-child(even) {
        background: #f2f8ff
    }

    .report-daily {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px
    }

    .design-report .report-missing {
        color: #73839b
    }

    .report-entry+.report-entry {
        margin-top: 7px;
        padding-top: 7px;
        border-top: 1px solid #e0eaf4
    }

    .report-note {
        font-size: 12px;
        color: #536a88;
        line-height: 1.5
    }

    .report-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin: 16px 0
    }

    .report-print-button {
        padding: 12px 20px;
        cursor: pointer
    }

    .report-category td:nth-child(2) {
        font-size: 17px;
        font-weight: bold;
        color: #0873db
    }

    .report-empty {
        text-align: center;
        padding: 30px
    }

    @media(max-width:900px) {
        .report-daily {
            grid-template-columns: 1fr
        }

        .report-meta {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }

        .design-report h1 {
            font-size: 28px
        }
    }

    @media(max-width:500px) {

        .report-meta,
        .report-stats {
            grid-template-columns: 1fr
        }

        .design-report {
            padding: 8px
        }

        .design-report .report-box,
        .design-report .report-hero {
            padding: 16px
        }
    }

    @media print {
        @page {
            size: A4 portrait;
            margin: 10mm
        }

        body {
            margin: 0;
            background: white
        }

        .report-controls,
        .report-print-button {
            display: none !important
        }

        .design-report {
            margin: 0;
            padding: 0;
            box-shadow: none;
            break-after: page;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact
        }

        .design-report:last-child {
            break-after: auto
        }

        .design-report .report-hero {
            padding: 22px
        }

        .design-report h1 {
            font-size: 28px
        }

        .design-report .report-box {
            padding: 14px;
            margin-top: 10px
        }

        .report-meta {
            grid-template-columns: repeat(3, 1fr)
        }

        .report-daily {
            display: block;
            columns: 2;
            column-gap: 16px
        }

        .report-daily table {
            margin-bottom: 12px
        }

        .design-report tr,
        .report-meta,
        .report-stats,
        .report-hero {
            break-inside: avoid
        }

        .design-report thead {
            display: table-header-group
        }

        .design-report table {
            font-size: 10px
        }

        .design-report h2 {
            font-size: 22px
        }
    }
</style>
<?php if (!$standalone): ?>
    <section class="panel report-controls">
        <h2>Daily Work Report · Filters &amp; Downloads</h2>
        <form method="get" class="filters">
            <div class="field"><label for="from_date">From date</label><input type="date" id="from_date" name="from_date" value="<?= h($report['from']) ?>" required></div>
            <div class="field"><label for="to_date">To date</label><input type="date" id="to_date" name="to_date" value="<?= h($report['to']) ?>" required></div>
            <div class="field"><label for="department">Department</label><select id="department" name="department" data-filter-department>
                    <option value="">All design departments</option><?= options($report['departments'], 'name', $report['department'] ?: null) ?>
                </select></div>
            <div class="field"><label for="employee">Employee</label><select id="employee" name="employee">
                    <option value="">All employees</option><?= options($report['employees'], 'full_name', $report['employee'] ?: null) ?>
                </select></div>
            <div class="report-actions"><button class="primary" type="submit">Apply filters</button><button class="button secondary" name="format" value="html">Download designed report</button><button class="button secondary" name="format" value="print" formtarget="_blank">Print / Save as PDF</button><button class="button secondary" name="format" value="xlsx">Download Excel</button></div>
        </form>
        <p class="help">Choose an employee and an inclusive date range. The designed HTML download opens offline with this same layout. Use Print / Save as PDF for a PDF copy. Totals include completed submitted work only.</p>
    </section>
<?php else: ?><button class="report-print-button" onclick="window.print()">Print / Save as PDF</button><?php endif; ?>
<?php if (!$report['people']): ?><p class="report-empty">No design employees found for these filters.</p><?php endif; ?>
<?php foreach ($report['people'] as $person):
    $days = [];
    foreach ($person['entries'] as $entry) $days[$entry['work_date']][] = $entry;
    $calendar = [];
    for ($day = new DateTimeImmutable($report['from']); $day->format('Y-m-d') <= $report['to']; $day = $day->modify('+1 day')) $calendar[] = $day->format('Y-m-d');
    $total = $person['poster'] + $person['video_editing'] + $person['carousel'];
    $halves = array_chunk($calendar, (int)ceil(count($calendar) / 2));
    $serial = 0;
?>
    <article class="design-report" id="employee-<?= (int)$person['id'] ?>-work">
        <header class="report-hero"><small><?= h(strtoupper($person['department_name'])) ?></small>
            <h1><?= h($person['full_name']) ?> — WORK REPORT</h1>
            <p><?= h((new DateTimeImmutable($report['from']))->format('d F Y')) ?> to <?= h((new DateTimeImmutable($report['to']))->format('d F Y')) ?></p>
        </header>
        <section class="report-box report-meta">
            <div><small>Employee</small><strong><?= h($person['full_name']) ?></strong></div>
            <div><small>Role</small><strong><?= h($person['designation'] ?? $person['department_name']) ?></strong></div>
            <div><small>Joining date</small><strong><?= !empty($person['joining_date']) ? h($pretty($person['joining_date'])) : 'Not available' ?></strong></div>
            <div><small>From date</small><strong><?= h($pretty($report['from'])) ?></strong></div>
            <div><small>To date</small><strong><?= h($pretty($report['to'])) ?></strong></div>
            <div><small>Days with submitted work</small><strong><?= count($days) ?></strong></div>
        </section>
        <section class="report-box">
            <div class="report-summary-heading">
                <h2>▥ Work Summary</h2><span class="report-total">Total quantified deliverables: <?= $total ?></span>
            </div>
            <div class="report-stats"><?php foreach ($labels as $key => $label): ?><div class="report-stat"><strong><?= (int)$person[$key] ?></strong><?= h($label) ?></div><?php endforeach; ?></div>
            <p class="report-note"><?= (int)$person['pending'] ?> pending task(s) excluded from totals. <?= (int)$person['unclassified'] ?> completed task(s) without a saved category / quantity. Categories reflect the quantities saved in the portal.</p>
        </section>
        <section class="report-box">
            <h2>▤ Daily Work Report</h2>
            <div class="report-daily">
                <?php foreach ($halves as $half): ?><table>
                        <colgroup>
                            <col style="width:9%">
                            <col style="width:23%">
                            <col style="width:20%">
                            <col style="width:48%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Date</th>
                                <th>Day</th>
                                <th>Work completed / status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($half as $date): ?><tr>
                                    <td><?= ++$serial ?></td>
                                    <td><?= h($pretty($date)) ?></td>
                                    <td><?= h((new DateTimeImmutable($date))->format('l')) ?></td>
                                    <td><?php if (empty($days[$date])): ?><span class="report-missing">No submitted work</span><?php else: foreach ($days[$date] as $entry): ?><div class="report-entry"><strong><?= h(design_quantity_label($entry['quantities'])) ?></strong> · <?= h($entry['task_status']) ?><?php if ($entry['task_title']): ?><br><?= h($entry['task_title']) ?><?php endif; ?><?php if ($entry['client_name']): ?><br><?= h($entry['client_name']) ?><?php endif; ?><?php if ($entry['remark']): ?><br><?= nl2br(h($entry['remark'])) ?><?php endif; ?></div><?php endforeach;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            endif; ?></td>
                                </tr><?php endforeach; ?>
                        </tbody>
                    </table><?php endforeach; ?></div>
            <p class="report-note">Dates without submissions are not assumed to be holidays or weekly offs.</p>
        </section>
        <section class="report-box">
            <h2>◕ Category Totals</h2>
            <table class="report-category">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Total</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody><?php foreach ($labels as $key => $label): ?><tr>
                            <td><?= h($label) ?></td>
                            <td><?= (int)$person[$key] ?></td>
                            <td>Completed work within selected dates</td>
                        </tr><?php endforeach; ?><tr>
                        <td><strong>Total</strong></td>
                        <td><?= $total ?></td>
                        <td>Pending tasks excluded</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </article>
<?php endforeach; ?>