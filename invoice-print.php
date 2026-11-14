<?php
// Printable invoice in the Bhavi Creations format. Managing Director sees any invoice; a client sees only their own.
// The browser's "Save as PDF" turns it into a file.
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/invoice.php';
$user = require_roles(['admin', 'client']);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) fail(404, 'Invoice not found.');
$invoice = one('SELECT i.*,c.client_name,c.contact_person,c.phone,c.email,c.address,c.city,c.gst_number,c.user_id AS client_user_id FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=? AND i.deleted_at IS NULL', [$id]);
if (!$invoice || ($user['role'] === 'client' && (int) $invoice['client_user_id'] !== (int) $user['id'])) fail(404, 'Invoice not found.');
$items = rows('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order,id', [$id]);
$co = invoice_company();
$companyName = trim((string) ($co['company_name'] ?? '')) ?: $co['account_name'];

/** 150000 → "1,50,000.00" (Indian grouping, no ₹ sign). */
$num = static function ($value): string {
    $cents = (int) round(abs((float) $value) * 100);
    $whole = (string) intdiv($cents, 100);
    if (strlen($whole) > 3) $whole = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3);
    return ((float) $value < 0 ? '-' : '').$whole.sprintf('.%02d', $cents % 100);
};
$plain = static fn($n): string => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.') ?: '0';
$received = $invoice['status'] === 'paid' ? (float) $invoice['total'] : min((float) $invoice['total'], (float) $invoice['advance']);
$balance = max(0, (float) $invoice['total'] - $received);
$addr = trim($invoice['address'].($invoice['city'] !== '' && stripos($invoice['address'], $invoice['city']) === false ? ($invoice['address'] !== '' ? ', ' : '').$invoice['city'] : ''));

// Scan to Pay: a saved QR image, or a UPI QR made from the UPI ID for the balance.
$imgPath = static function (string $key) use ($co): string {
    $file = basename(trim((string) ($co[$key] ?? '')));
    return $file !== '' && is_file(__DIR__.'/assets/img/'.$file) ? 'assets/img/'.rawurlencode($file) : '';
};
$qrImage = $imgPath('qr_image');
$signature = $imgPath('signature_image');
$logo = $imgPath('logo_image') ?: 'assets/img/blue%20logo%20old.webp';
$upiId = trim((string) ($co['upi_id'] ?? ''));
$upiLink = $upiId !== '' ? 'upi://pay?'.http_build_query(['pa' => $upiId, 'pn' => $co['account_name'], 'am' => number_format($balance > 0 ? $balance : (float) $invoice['total'], 2, '.', ''), 'cu' => 'INR', 'tn' => 'Invoice '.$invoice['invoice_no']], '', '&', PHP_QUERY_RFC3986) : '';
$bank = array_filter(['Bank Name' => $co['bank_name'] ?? '', 'Account Name' => $co['account_name'], 'Account No.' => $co['account_no'] ?? '', 'IFSC' => $co['ifsc'] ?? '', 'UPI ID' => $upiId], static fn($v) => trim((string) $v) !== '');
$back = $user['role'] === 'client' ? 'client-payments.php' : 'invoices.php';
header('Cache-Control: private, no-store');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h('Invoice '.$invoice['invoice_no'].' · '.$invoice['client_name']) ?></title><link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<style>
:root{--ink:#1f2633;--muted:#4b5563;--line:#d6dbe3;--head:#e9edf2}
*{box-sizing:border-box}
body{margin:0;padding:28px 16px;background:#eef1f5;color:var(--ink);font:12.5px/1.6 "Plus Jakarta Sans",system-ui,sans-serif}
.sheet{position:relative;max-width:820px;margin:0 auto;padding:30px 30px 34px;background:#fff;box-shadow:0 8px 28px #0d2f661a}
.logo{display:block;height:68px;width:auto;margin:0 auto 26px}
.dateline{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 16px 30px;font-size:13.5px}
.dateline b{font-weight:700}
.parties{display:flex;justify-content:space-between;gap:24px;margin:0 16px 30px}
.parties h2{margin:0 0 6px;font-size:16px;font-weight:700}
.parties .name{font-weight:700;font-size:13.5px;margin:0 0 2px}
.parties p{margin:0;font-size:12px;line-height:1.75}
.parties .to{text-align:right}
.addr{white-space:pre-line}
table{width:100%;border-collapse:collapse}
.inv th{padding:8px 6px;background:var(--head);border:1px solid var(--line);font-weight:700;font-size:12px;text-align:center}
.inv td{padding:7px 6px;border:1px solid var(--line);text-align:center;font-size:12px}
.inv td.r{text-align:right}
.inv .words{text-align:left;vertical-align:middle;padding:10px 6px}
.inv .words p{margin:0}
.inv .words b{display:block;margin-top:6px}
.inv .lbl{font-weight:600;text-align:right;padding-right:10px}
.inv .strong{font-weight:800}
.inv .shade{background:#f4f6f9}
.note{margin:26px 0 0;font-size:12px}
.note b{font-weight:700}
.note span{white-space:pre-line}
hr{margin:34px -16px 34px;border:0;border-top:1px solid #c9ced6}
.pay{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.pay h3{margin:0 0 10px;font-size:13.5px;font-weight:700}
.pay p{margin:0;font-size:11.5px;line-height:1.8}
.pay p b{font-weight:700}
.qr{width:90px;height:90px}
.qr img,.qr canvas{width:90px!important;height:90px!important}
.sign{margin-top:24px;margin-left:60%;text-align:center;font-size:11.5px;font-weight:700}
.sign img{display:block;height:46px;width:auto;margin:0 auto 4px}
.sign .blank{height:46px}
.stamp{position:absolute;top:150px;left:50%;transform:translateX(-50%) rotate(-12deg);padding:4px 18px;border:3px solid;border-radius:8px;font-weight:800;font-size:22px;letter-spacing:.14em;opacity:.55}
.stamp.paid{color:#07803f}.stamp.cancelled{color:#b42318}
.bar{display:flex;justify-content:center;gap:10px;margin-bottom:16px}
.bar button,.bar a{padding:10px 18px;border:0;border-radius:10px;background:#d6ad48;color:#172b49;font:800 13px "Plus Jakarta Sans",sans-serif;text-decoration:none;cursor:pointer}
.bar a{background:#fff;border:1px solid var(--line)}
/* Margin 0 stops the browser printing its own header/footer (page title, date, URL) on the PDF. */
@page{size:A4;margin:0}
@media print{body{padding:0;background:#fff}.sheet{box-shadow:none;padding:12mm 12mm 10mm;max-width:none}.bar{display:none}.inv th,.inv .shade{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
@media(max-width:640px){.sheet{padding:18px}.parties{flex-direction:column}.parties .to{text-align:left}.pay{grid-template-columns:1fr}.sign{margin-left:0}.inv-wrap{overflow-x:auto}.inv{min-width:620px}}
</style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Print / Save as PDF</button><a href="<?= h($back) ?>">Back</a></div>
<main class="sheet">
    <?php if ($invoice['status'] !== 'unpaid'): ?><div class="stamp <?= h($invoice['status']) ?>"><?= h(strtoupper(INVOICE_STATUSES[$invoice['status']] ?? '')) ?></div><?php endif; ?>
    <img class="logo" src="<?= h($logo) ?>" alt="<?= h($companyName) ?>" onerror="this.remove()">

    <div class="dateline">
        <div><b>Date:</b> <?= h(date('d-m-Y', strtotime($invoice['invoice_date']))) ?><?php if ($invoice['due_date']): ?> &nbsp;·&nbsp; <b>Due:</b> <?= h(date('d-m-Y', strtotime($invoice['due_date']))) ?><?php endif; ?></div>
        <div><b>Invoice Number:</b> <?= h($invoice['invoice_no']) ?></div>
    </div>

    <div class="parties">
        <div class="from">
            <h2>From:</h2>
            <p class="name"><?= h($companyName) ?></p>
            <?php if (trim((string) ($co['company_address'] ?? '')) !== ''): ?><p class="addr"><?= h(trim($co['company_address'])) ?></p><?php endif; ?>
            <p><b>Phone:</b> <?= h($co['company_phone'] ?? '') ?></p>
            <p><b>Email:</b> <?= h($co['company_email'] ?? '') ?></p>
            <p><b>GSTIN:</b> <?= h($co['company_gstin'] ?? '') ?></p>
        </div>
        <div class="to">
            <h2>To (Bill To):</h2>
            <p class="name"><?= h($invoice['client_name']) ?></p>
            <?php if ($invoice['contact_person'] !== ''): ?><p><?= h($invoice['contact_person']) ?></p><?php endif; ?>
            <?php if ($addr !== ''): ?><p><?= h($addr) ?></p><?php endif; ?>
            <p><b>Phone:</b> <?= h($invoice['phone'] ?? '') ?></p>
            <p><b>Email:</b> <?= h($invoice['email']) ?></p>
            <p><b>GSTIN:</b> <?= h($invoice['gst_number']) ?></p>
        </div>
    </div>

    <div class="inv-wrap">
    <table class="inv">
        <thead><tr><th>S.no</th><th>Services</th><th>Description</th><th>Qty</th><th>Price/Unit</th><th>Sub Total</th><th>Disc %</th><th>Total</th></tr></thead>
        <tbody>
            <?php foreach ($items as $i => $it): ?>
                <tr>
                    <td><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
                    <td><?= h($it['service_name']) ?></td>
                    <td><?= nl2br(h($it['description'])) ?></td>
                    <td><?= h($plain($it['quantity'])) ?></td>
                    <td><?= h($num($it['rate'])) ?></td>
                    <td><?= h($num((float) $it['quantity'] * (float) $it['rate'])) ?></td>
                    <td><?= h($plain($it['discount_percent'])) ?></td>
                    <td><?= h($num($it['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="6" rowspan="6" class="words">
                    <p><b style="margin-top:0">Total in words:</b><?= h(invoice_amount_words($invoice['total'])) ?></p>
                    <p><b>Balance in words:</b><?= h(invoice_amount_words($balance)) ?></p>
                </td>
                <td class="lbl">Subtotal</td><td><?= h($num($invoice['subtotal'])) ?></td>
            </tr>
            <tr><td class="lbl">GST %</td><td><?= h($plain($invoice['gst_rate'])) ?></td></tr>
            <tr><td class="lbl">GST Total</td><td><?= h($num($invoice['tax_amount'])) ?></td></tr>
            <tr><td class="lbl strong">Grand Total</td><td class="strong"><?= h($num($invoice['total'])) ?></td></tr>
            <tr><td class="lbl"><?= $invoice['status'] === 'paid' ? 'Paid' : 'Advance' ?></td><td><?= h($num($received)) ?></td></tr>
            <tr class="shade"><td class="lbl strong">Balance</td><td class="strong"><?= h($num($balance)) ?></td></tr>
        </tbody>
    </table>
    </div>

    <p class="note"><b>Note:</b> <span><?= h(trim($invoice['notes'])) ?></span></p>

    <hr>

    <div class="pay">
        <div>
            <?php if ($qrImage || $upiLink): ?>
                <h3>Scan to Pay:</h3>
                <?php if ($qrImage): ?><img class="qr" src="<?= h($qrImage) ?>" alt="Payment QR code"><?php else: ?><div class="qr" id="upi-qr" data-upi="<?= h($upiLink) ?>"></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <div>
            <h3>Payment details</h3>
            <?php foreach ($bank as $k => $val): ?><p><b><?= h($k) ?>:</b> <?= h($val) ?></p><?php endforeach; ?>
        </div>
    </div>

    <div class="sign"><?php if ($signature): ?><img src="<?= h($signature) ?>" alt="Signature"><?php else: ?><div class="blank"></div><?php endif; ?>Authorized Signature:</div>
</main>
<?php if (!$qrImage && $upiLink): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>(function(){var el=document.getElementById('upi-qr');if(el&&window.QRCode)new QRCode(el,{text:el.dataset.upi,width:180,height:180,correctLevel:QRCode.CorrectLevel.M});})();</script>
<?php endif; ?>
</body>
</html>
