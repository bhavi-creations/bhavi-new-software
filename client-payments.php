<?php
require_once __DIR__.'/includes/client-portal.php';
$client=own_client();
$payments=rows('SELECT payment_date,amount FROM client_payments WHERE client_id=? ORDER BY payment_date DESC,id DESC',[$client['id']]);
page_start('Payments','client-payments.php');
?><p class="cp-intro">A clear record of payments for <strong><?= h($client['client_name']) ?></strong>.</p>
<?php client_payment_summary($client); ?>
<section class="panel"><div class="cp-section-heading"><h2>Payment history</h2><span><?= count($payments) ?> receipts</span></div><p class="help">Payments recorded by your team, with the newest first.</p>
<?php if (!$payments): ?><div class="cp-empty"><?= icon('document') ?><h3>No payments recorded yet</h3><p>Your payment dates and amounts will appear here once recorded.</p></div><?php else: ?><ol class="cp-payment-list"><?php foreach ($payments as $payment): ?><li><div><span class="cp-receipt-icon"><?= icon('document') ?></span><div><strong><?= h((new DateTimeImmutable($payment['payment_date']))->format('d M Y')) ?></strong><span>Payment received</span></div></div><strong><?= h(money_label($payment['amount'])) ?></strong></li><?php endforeach; ?></ol><?php endif; ?></section>
<?php page_end(); ?>
