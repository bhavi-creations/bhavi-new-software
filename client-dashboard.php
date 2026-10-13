<?php
require_once __DIR__.'/includes/client-portal.php';
$client=own_client();
$month=new DateTimeImmutable('first day of this month');
$reports=client_month_reports((int)$client['id'],$month);
page_start('Dashboard','client-dashboard.php');
?>
<section class="cp-hero"><div><span class="cp-eyebrow">YOUR CLIENT WORKSPACE</span><h2>Welcome, <?= h($client['client_name']) ?></h2><p>Your project, payments and work updates, in one place.</p><span class="cp-pill"><?= h(ucfirst($client['client_type'])) ?></span> <span class="cp-pill"><?= h(project_age($client['starting_date'])) ?></span></div><?php if ($client['logo_path']): ?><img src="client-logo.php?id=<?= (int)$client['id'] ?>" alt="<?= h($client['client_name']) ?> logo"><?php endif; ?></section>
<div class="cp-section-heading"><h2>Work this month</h2><span><?= h($month->format('F Y')) ?></span></div>
<?php client_progress($client,$reports); ?>
<p class="help">Completed means uploaded. Work is grouped by the report date selected by your team.</p>
<div class="cp-section-heading"><h2>Client payments</h2><a href="client-payments.php">View payment history &rarr;</a></div>
<?php client_payment_summary($client); ?>
<div class="cp-shortcuts"><a class="panel" href="client-details.php"><?= icon('briefcase') ?><h3>Project details</h3><p>Contact details, package and social accounts.</p><span>View details &rarr;</span></a><a class="panel" href="client-payments.php"><?= icon('document') ?><h3>Payments</h3><p>Your agreed amount, receipts and remaining balance.</p><span>View payments &rarr;</span></a><a class="panel" href="client-reports.php"><?= icon('calendar') ?><h3>Work reports</h3><p>Follow each work item from content received to upload.</p><span>Open calendar &rarr;</span></a></div>
<?php page_end(); ?>
