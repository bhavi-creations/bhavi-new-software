<?php
// Printable payment statement (all payments) or a single receipt (?payment=ID) for the signed-in client.
// The browser's "Save as PDF" turns it into a file.
require_once __DIR__.'/includes/client-portal.php';
$client=own_client();
$paymentId=filter_var($_GET['payment']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (isset($_GET['payment']) && !$paymentId) fail(404,'Receipt not found.');
if ($paymentId) {
    $payments=rows('SELECT id,payment_date,amount FROM client_payments WHERE id=? AND client_id=?',[$paymentId,$client['id']]);
    if (!$payments) fail(404,'Receipt not found.');
} else {
    $payments=rows('SELECT id,payment_date,amount FROM client_payments WHERE client_id=? ORDER BY payment_date,id',[$client['id']]);
}
$payInfo=(array)require __DIR__.'/includes/payment-details.php';
$from=trim((string)($payInfo['account_name']??''))?:'Bhavi Creations';
$isReceipt=(bool)$paymentId;
$title=$isReceipt?'Payment receipt':'Payment statement';
$number=$isReceipt?'R-'.str_pad((string)$paymentId,5,'0',STR_PAD_LEFT):'S-'.date('Ymd');
$running=0.0;
header('Cache-Control: private, no-store');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title.' · '.$client['client_name']) ?></title><link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg"><link rel="apple-touch-icon" href="assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap">
<style>
:root{--ink:#172b49;--muted:#6a7b94;--line:#e3e8f1;--brand:#103d83;--deep:#0d2f66;--gold:#d6ad48}
*{box-sizing:border-box}
body{margin:0;padding:32px 16px;background:#f2f5fa;color:var(--ink);font:13px/1.5 "Plus Jakarta Sans",system-ui,sans-serif}
.sheet{max-width:780px;margin:0 auto;padding:40px;border-radius:16px;background:#fff;box-shadow:0 10px 30px #0d2f6614}
.top{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;padding-bottom:22px;border-bottom:2px solid var(--deep)}
h1{margin:0;color:var(--brand);font:600 34px/1.1 "Cormorant Garamond",Georgia,serif}
.brand{color:var(--deep);font-weight:800;font-size:16px}
.muted{color:var(--muted)}
.meta{text-align:right}
.meta p,.to p{margin:2px 0}
.to{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;margin:22px 0}
.label{color:var(--muted);font-size:10.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
table{width:100%;border-collapse:collapse}
th{padding:10px;border-bottom:1px solid var(--line);color:var(--muted);font-size:10.5px;letter-spacing:.08em;text-align:left;text-transform:uppercase}
td{padding:12px 10px;border-bottom:1px solid var(--line)}
.num{text-align:right;white-space:nowrap}
.totals{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:22px}
.totals div{padding:14px;border-radius:12px;background:#f6f8fc}
.totals strong{display:block;font-size:18px}
.totals .due{background:#fff8e6}
.big{margin:22px 0;padding:22px;border-radius:14px;background:var(--deep);color:#fff}
.big strong{display:block;color:#e2bf62;font-size:30px}
.foot{margin-top:28px;color:var(--muted);font-size:11.5px}
.bar{display:flex;justify-content:center;gap:10px;margin-bottom:16px}
.bar button,.bar a{padding:10px 18px;border:0;border-radius:10px;background:var(--gold);color:var(--ink);font:800 13px "Plus Jakarta Sans",sans-serif;text-decoration:none;cursor:pointer}
.bar a{background:#fff;border:1px solid var(--line)}
@media print{body{padding:0;background:#fff}.sheet{box-shadow:none;padding:0}.bar{display:none}}
@media(max-width:560px){.sheet{padding:22px}.totals{grid-template-columns:1fr}.meta{text-align:left}}
</style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Print / Save as PDF</button><a href="client-payments.php">Back to payments</a></div>
<main class="sheet">
    <div class="top">
        <div><div class="brand"><?= h($from) ?></div><h1><?= h($title) ?></h1></div>
        <div class="meta"><p class="label">No.</p><p><strong><?= h($number) ?></strong></p><p class="muted">Issued <?= h(date('d M Y')) ?></p></div>
    </div>
    <div class="to">
        <div><p class="label">Billed to</p><p><strong><?= h($client['client_name']) ?></strong></p><?php if ($client['phone']): ?><p class="muted"><?= h($client['phone']) ?></p><?php endif; ?></div>
        <?php if ($client['package']): ?><div><p class="label">Package</p><p><strong><?= h($client['package']) ?></strong></p></div><?php endif; ?>
    </div>

    <?php if ($isReceipt): $p=$payments[0]; ?>
        <div class="big"><span class="label" style="color:#ffffffb3">Amount received</span><strong><?= h(rupees_label($p['amount'])) ?></strong><span>on <?= h(date('d M Y',strtotime($p['payment_date']))) ?></span></div>
        <p>Received with thanks from <strong><?= h($client['client_name']) ?></strong> towards the Bhavi Creations package.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Date</th><th>Details</th><th class="num">Amount</th><th class="num">Paid so far</th></tr></thead>
            <tbody>
                <?php if (!$payments): ?><tr><td colspan="4" class="muted">No payments recorded yet.</td></tr><?php endif; ?>
                <?php foreach ($payments as $p): $running+=(float)$p['amount']; ?>
                    <tr><td><?= h(date('d M Y',strtotime($p['payment_date']))) ?></td><td>Payment received · R-<?= h(str_pad((string)$p['id'],5,'0',STR_PAD_LEFT)) ?></td><td class="num"><?= h(rupees_label($p['amount'])) ?></td><td class="num"><?= h(rupees_label($running)) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="totals">
        <div><span class="label">Total agreed</span><strong><?= h(rupees_label($client['payment_total'])) ?></strong></div>
        <div><span class="label">Paid</span><strong><?= h(rupees_label($client['paid_amount'])) ?></strong></div>
        <div class="due"><span class="label">Balance</span><strong><?= h(rupees_label(max(0,(float)$client['remaining_amount']))) ?></strong></div>
    </div>
    <p class="foot">This is a computer-generated <?= $isReceipt?'receipt':'statement' ?> from the Bhavi Creations client portal and does not need a signature.</p>
</main>
</body>
</html>
