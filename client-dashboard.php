<?php
require_once __DIR__.'/includes/client-portal.php';
$client=own_client();
$month=new DateTimeImmutable('first day of this month');
$reports=client_month_reports((int)$client['id'],$month);

// Content delivered this month (uploaded = delivered)
$done=[]; foreach ($reports as $report) if ($report['uploaded_at']) $done[$report['work_type']]=($done[$report['work_type']]??0)+1;
$contentTypes=['reel'=>['monthly_reels','Reels','video'],'poster'=>['monthly_posters','Posters','image'],'carousel'=>['monthly_carousels','Carousels','carousel']];
$totalTarget=0; $totalDone=0;
foreach ($contentTypes as $type=>[$column]) { $totalTarget+=(int)$client[$column]; $totalDone+=min((int)($done[$type]??0),(int)$client[$column]); }
$daysLeft=(int)date('t')-(int)date('j');

// Payments
$agreed=(float)$client['payment_total']; $paid=(float)$client['paid_amount']; $balance=(float)$client['remaining_amount'];
$paidPercent=$agreed>0?(int)round(min(100,max(0,$paid/$agreed*100))):0;

// Who to contact: the client's account manager, otherwise an administrator.
$contact=null;
if (!empty($client['account_manager_id'])) $contact=one("SELECT u.full_name,u.email,ep.phone FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.account_status='active' AND u.deleted_at IS NULL",[$client['account_manager_id']]);
if (!$contact) $contact=one("SELECT u.full_name,u.email,ep.phone FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.role='admin' AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1");
$contactPhone=trim((string)($contact['phone']??''));
$contactEmail=trim((string)($contact['email']??''));

$words=array_values(array_filter(preg_split('/\s+/',trim((string)$client['client_name']))?:[]));
$initials=strtoupper(implode('',array_map(static fn($w)=>mb_substr($w,0,1),array_slice($words,0,2))))?:'?';
$packageLabel=$client['client_type']==='premium'?'★ '.($client['package']?:'Premium').' package':($client['package']?:ucfirst((string)$client['client_type']).' package');
$partnerSince=$client['starting_date']?'Partner since '.date('j M Y',strtotime($client['starting_date'])).' · '.project_age($client['starting_date']):'';
page_start('Dashboard','client-dashboard.php');
?>
<div class="cd-page">
    <section class="cd-hero">
        <div class="cd-hero-main">
            <p class="cd-eyebrow">Your client workspace · <?= h(date('l, j F Y')) ?></p>
            <h1 class="cd-title">Welcome, <?= h($client['client_name']) ?></h1>
            <p class="cd-sub">Your project, payments and work updates — in one place.</p>
            <div class="cd-pills">
                <span class="cd-pill is-gold"><?= h($packageLabel) ?></span>
                <?php if ($partnerSince): ?><span class="cd-pill"><?= h($partnerSince) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="cd-hero-side">
            <span class="cd-logo"><?php if ($client['logo_path']): ?><img src="client-logo.php?id=<?= (int)$client['id'] ?>" alt="<?= h($client['client_name']) ?> logo" onerror="this.remove()"><?php endif; ?><b><?= h($initials) ?></b></span>
            <a class="cd-request-btn" href="client-reuirement.php">+ Request new work</a>
        </div>
    </section>

    <div class="cd-section-head">
        <h2>Your content · <?= h($month->format('F Y')) ?></h2>
        <span><?= $totalDone ?> of <?= $totalTarget ?> delivered · <?= $daysLeft ?> <?= $daysLeft===1?'day':'days' ?> left this month</span>
    </div>
    <div class="cd-content-grid">
        <?php foreach ($contentTypes as $type=>[$column,$label,$iconName]): $target=(int)$client[$column]; $count=(int)($done[$type]??0); $pct=$target>0?min(1,$count/$target):($count>0?1:0); $circ=2*M_PI*26; ?>
            <article class="cd-card cd-content">
                <span class="cd-ring" role="img" aria-label="<?= h($label) ?>: <?= $count ?> of <?= $target ?> delivered">
                    <svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="26" class="cd-ring-track"/><circle cx="32" cy="32" r="26" class="cd-ring-fill" stroke-dasharray="<?= round($circ,2) ?>" stroke-dashoffset="<?= round($circ*(1-$pct),2) ?>"/></svg>
                    <i><?= icon($iconName) ?></i>
                </span>
                <div>
                    <p class="cd-content-label"><?= h($label) ?></p>
                    <p class="cd-content-count"><strong><?= $count ?></strong><small> / <?= $target ?></small></p>
                    <p class="cd-content-note"><?= $target>$count ? ($target-$count).' still to come' : ($target>0?'All delivered ✓':'Not in your plan') ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="cd-help">Delivered = uploaded to your workspace. Work is grouped by the report date your team selects.</p>

    <div class="cd-grid">
        <section class="cd-card cd-payments">
            <div class="cd-card-head"><h2>Payments</h2><a href="client-payments.php">View payment history →</a></div>
            <div class="cd-pay-progress">
                <div class="cd-pay-labels"><span><?= $paidPercent ?>% paid</span><span>of <?= h(money_label($client['payment_total'])) ?> agreed</span></div>
                <div class="cd-bar" role="progressbar" aria-valuenow="<?= $paidPercent ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $paidPercent ?>%"></span></div>
            </div>
            <div class="cd-pay-boxes">
                <div><span>Total agreed</span><strong><?= h(money_label($client['payment_total'])) ?></strong></div>
                <div class="is-paid"><span>Paid</span><strong><?= h(money_label($client['paid_amount'])) ?></strong></div>
                <div class="<?= $balance>0?'is-due':'is-clear' ?>"><span>Balance</span><strong><?= h(money_label($client['remaining_amount'])) ?></strong></div>
            </div>
            <div class="cd-pay-actions">
                <a class="cd-btn-dark" href="client-payments.php">View receipts</a>
                <a class="cd-btn-outline" href="client-details.php">Payment details</a>
            </div>
        </section>

        <section class="cd-help-card">
            <p class="cd-eyebrow">Need help?</p>
            <h2>We’re here to help you shine.</h2>
            <?php if ($contact && $contact['full_name']): ?><p class="cd-help-name">Your contact: <?= h($contact['full_name']) ?></p><?php endif; ?>
            <?php if ($contactPhone !== ''): ?><a class="cd-help-line" href="tel:<?= h(preg_replace('/[^0-9+]/','',$contactPhone)) ?>">📞 <?= h($contactPhone) ?></a><?php endif; ?>
            <?php if ($contactEmail !== ''): ?><a class="cd-help-line" href="mailto:<?= h($contactEmail) ?>">✉ <?= h($contactEmail) ?></a><?php endif; ?>
            <a class="cd-help-line" href="client-reuirement.php">💬 Send us a request</a>
        </section>
    </div>

    <div class="cd-shortcuts">
        <a class="cd-card cd-shortcut" href="client-details.php"><span class="cd-short-icon"><?= icon('briefcase') ?></span><h3>Project details</h3><p>Contact details, package and social accounts.</p><b>View details →</b></a>
        <a class="cd-card cd-shortcut" href="client-reports.php"><span class="cd-short-icon"><?= icon('calendar') ?></span><h3>Work reports</h3><p>Follow each item from content received to upload.</p><b>Open calendar →</b></a>
        <a class="cd-card cd-shortcut" href="client-reuirement.php"><span class="cd-short-icon"><?= icon('plus') ?></span><h3>Request work</h3><p>Need an extra post, reel or a change? Tell us here.</p><b>New request →</b></a>
    </div>
</div>
<?php page_end(); ?>
