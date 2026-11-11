<?php
require_once __DIR__.'/includes/client-portal.php';
$client=own_client();
$payments=rows('SELECT id,payment_date,amount FROM client_payments WHERE client_id=? ORDER BY payment_date DESC,id DESC',[$client['id']]);
// Payment proofs the client sent that the team has not closed yet.
$proofs=rows("SELECT id,created_at,brief,status FROM client_requirements WHERE client_id=? AND status IN ('assigned','in_progress') AND (title LIKE 'Payment proof · %' OR title LIKE 'URGENT · Payment proof · %') ORDER BY created_at DESC",[$client['id']]);
// Proofs the team marked as not received in the last 30 days (with their note).
$rejectedProofs=rows("SELECT id,created_at,approved_text FROM client_requirements WHERE client_id=? AND status='cancelled' AND (title LIKE 'Payment proof · %' OR title LIKE 'URGENT · Payment proof · %') AND created_at>=NOW()-INTERVAL 30 DAY ORDER BY created_at DESC",[$client['id']]);
$payInfo=array_map(static fn($v)=>trim((string)$v),(array)require __DIR__.'/includes/payment-details.php');

$total=(float)$client['payment_total']; $paid=(float)$client['paid_amount']; $balance=(float)$client['remaining_amount'];
$percent=$total>0?(int)floor(min(100,max(0,$paid/$total*100))):($paid>0?100:0);
$ring=2*M_PI*52;
$howRows=array_filter([
    'UPI ID'=>$payInfo['upi_id']??'',
    'Bank'=>$payInfo['bank_name']??'',
    'A/c · IFSC'=>implode(' · ',array_filter([$payInfo['account_no']??'',$payInfo['ifsc']??''])),
]);
page_start('Payments','client-payments.php');
?>
<div class="pp-page">
    <header class="pp-head">
        <div>
            <p class="pp-crumb">Dashboard / Payments</p>
            <h1 class="pp-title">Payments</h1>
            <p class="pp-sub">A clear record of every payment to Bhavi Creations.</p>
        </div>
        <a class="pp-outline" href="client-payment-statement.php" target="_blank" rel="noopener"><?= icon('download') ?> Download statement</a>
    </header>

    <section class="pp-hero" aria-label="Payment summary">
        <div class="pp-ring" role="img" aria-label="<?= $percent ?>% paid">
            <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="pp-ring-track" cx="60" cy="60" r="52"/><circle class="pp-ring-fill" cx="60" cy="60" r="52" stroke-dasharray="<?= round($ring,2) ?>" stroke-dashoffset="<?= round($ring*(1-$percent/100),2) ?>"/></svg>
            <span><strong><?= $percent ?>%</strong><small>paid</small></span>
        </div>
        <div class="pp-figure"><span>Total agreed</span><strong><?= h(rupees_label($total)) ?></strong></div>
        <div class="pp-figure"><span>Paid</span><strong><?= h(rupees_label($paid)) ?></strong></div>
        <div class="pp-figure pp-balance<?= $balance<=0?' is-clear':'' ?>"><span><?= $balance>0?'Balance':'All paid' ?></span><strong><?= h(rupees_label(max(0,$balance))) ?></strong></div>
    </section>

    <div class="pp-grid">
        <section class="pp-card">
            <div class="pp-card-head">
                <h2>Payment history</h2>
                <span><?= count($payments) ?> <?= count($payments)===1?'payment':'payments' ?> · newest first</span>
            </div>
            <?php foreach ($rejectedProofs as $proof): $d=new DateTimeImmutable($proof['created_at']); ?>
                <div class="pp-item is-proof is-rejected">
                    <span class="pp-date"><small><?= h(strtoupper($d->format('M'))) ?></small><b><?= h($d->format('d')) ?></b><small><?= h($d->format('Y')) ?></small></span>
                    <span class="pp-item-text"><strong>Payment proof not confirmed</strong><small><?= h($proof['approved_text'] ?: 'Please check and send the proof again.') ?></small></span>
                </div>
            <?php endforeach; ?>
            <?php foreach ($proofs as $proof): ?>
                <div class="pp-item is-proof">
                    <span class="pp-date"><?php $d=new DateTimeImmutable($proof['created_at']); ?><small><?= h(strtoupper($d->format('M'))) ?></small><b><?= h($d->format('d')) ?></b><small><?= h($d->format('Y')) ?></small></span>
                    <span class="pp-item-text"><strong>Payment proof sent</strong><small><?= icon('clock') ?> Waiting for Bhavi to confirm</small></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$payments && !$proofs && !$rejectedProofs): ?>
                <div class="pp-empty"><strong>No payments recorded yet</strong><small>Your payment dates and amounts will appear here once our team records them.</small></div>
            <?php endif; ?>
            <?php foreach ($payments as $payment): $d=new DateTimeImmutable($payment['payment_date']); ?>
                <div class="pp-item">
                    <time class="pp-date" datetime="<?= h($d->format('Y-m-d')) ?>" title="<?= h($d->format('d M Y')) ?>"><small><?= h(strtoupper($d->format('M'))) ?></small><b><?= h($d->format('d')) ?></b><small><?= h($d->format('Y')) ?></small></time>
                    <span class="pp-item-text"><strong>Payment received</strong><small><?= icon('check') ?> Confirmed by Bhavi</small></span>
                    <strong class="pp-amount"><?= h(rupees_label($payment['amount'])) ?></strong>
                    <a class="pp-receipt" href="client-payment-statement.php?payment=<?= (int)$payment['id'] ?>" target="_blank" rel="noopener"><?= icon('download') ?> Receipt</a>
                </div>
            <?php endforeach; ?>
            <?php if ($balance>0): ?>
                <div class="pp-due"><span class="pp-due-icon"><?= icon('clock') ?></span><span><strong><?= h(rupees_label($balance)) ?> balance</strong><small>Next payment will appear here once recorded by our team</small></span></div>
            <?php elseif ($total>0): ?>
                <div class="pp-due is-clear"><span class="pp-due-icon"><?= icon('check') ?></span><span><strong>Fully paid — thank you!</strong><small>There is no balance on your package.</small></span></div>
            <?php endif; ?>
        </section>

        <aside class="pp-side">
            <section class="pp-card pp-how">
                <h3>How to pay</h3>
                <?php if ($howRows): ?>
                    <?php foreach ($howRows as $label=>$value): ?><div class="pp-how-row"><span><?= h($label) ?></span><strong><?= h($value) ?></strong><button type="button" data-pp-copy="<?= h($value) ?>" aria-label="Copy <?= h($label) ?>">Copy</button></div><?php endforeach; ?>
                    <?php if (($payInfo['account_name']??'')!==''): ?><p class="pp-small">Account name: <?= h($payInfo['account_name']) ?></p><?php endif; ?>
                <?php else: ?>
                    <p class="pp-small">Please contact your Bhavi account manager for UPI or bank details.</p>
                <?php endif; ?>
            </section>

            <section class="pp-proof" id="pay-proof">
                <h3>Just made a payment?</h3>
                <p>Send us the screenshot or transaction ID and we’ll confirm it here.</p>
                <button type="button" class="pp-gold" data-pp-open>Share payment proof</button>
            </section>
        </aside>
    </div>
</div>

<dialog class="pp-dialog" data-pp-dialog>
    <form method="post" action="client-reuirement.php" enctype="multipart/form-data" data-pp-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_requirement">
        <input type="hidden" name="return" value="payments">
        <input type="hidden" name="tasks[0][type]" value="payment">
        <input type="hidden" name="tasks[0][due_date]" value="<?= h(today()) ?>">
        <h3>Share payment proof</h3>
        <div class="pp-two">
            <label><span>Amount paid</span><input type="number" min="1" step="0.01" inputmode="decimal" data-pp-amount placeholder="<?= $balance>0?h((string)(int)$balance):'50000' ?>"></label>
            <label><span>Paid on</span><input type="date" max="<?= h(today()) ?>" value="<?= h(today()) ?>" data-pp-date></label>
        </div>
        <label><span>Transaction ID / UTR *</span><input type="text" maxlength="120" required data-pp-txn placeholder="e.g. 4321 8765 0987"></label>
        <label><span>Note (optional)</span><textarea rows="2" maxlength="1000" data-pp-note placeholder="Paid via GPay from Dr. Rao’s account"></textarea></label>
        <label class="pp-file"><span>Screenshot (optional)</span><input type="file" name="task_files_0[]" accept="image/*,application/pdf"></label>
        <textarea name="tasks[0][remark]" hidden data-pp-remark></textarea>
        <div class="pp-dialog-actions"><button type="button" data-pp-close>Cancel</button><button type="submit" class="is-primary">Send proof</button></div>
    </form>
</dialog>
<script>
(() => {
    const dialog = document.querySelector('[data-pp-dialog]');
    document.querySelector('[data-pp-open]').addEventListener('click', () => dialog.showModal());
    dialog.querySelector('[data-pp-close]').addEventListener('click', () => dialog.close());
    const form = dialog.querySelector('[data-pp-form]');
    form.addEventListener('submit', () => {
        const v = s => form.querySelector(s).value.trim();
        const lines = ['Payment proof'];
        if (v('[data-pp-amount]')) lines.push('Amount: ₹' + v('[data-pp-amount]'));
        if (v('[data-pp-date]')) lines.push('Paid on: ' + v('[data-pp-date]'));
        lines.push('Transaction ID / UTR: ' + v('[data-pp-txn]'));
        if (v('[data-pp-note]')) lines.push('Note: ' + v('[data-pp-note]'));
        form.querySelector('[data-pp-remark]').value = lines.join('\n');
        const b = form.querySelector('.is-primary'); b.disabled = true; b.textContent = 'Sending…';
    });
    document.querySelectorAll('[data-pp-copy]').forEach(b => b.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(b.dataset.ppCopy); b.textContent = 'Copied'; setTimeout(() => { b.textContent = 'Copy'; }, 1500); } catch (e) {}
    }));
})();
</script>
<?php page_end(); ?>
