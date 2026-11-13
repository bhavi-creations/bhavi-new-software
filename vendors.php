<?php
// Vendors: added the same way as clients (details, login, package amount) with the payments we make to them.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/finance.php';
$user = require_roles(['admin', 'manager']);
$error = null;
const VENDOR_SERVICES = ['Printing', 'Photography / Videography', 'Freelance designer', 'Freelance developer', 'Hosting / Domain', 'Ads / Media', 'Software / Subscription', 'Stationery / Supplies', 'Transport', 'Other'];
const VENDOR_STATUSES = ['active' => 'Active', 'on_hold' => 'On hold', 'inactive' => 'Inactive'];

$mode = isset($_GET['add']) ? 'form' : (isset($_GET['id']) ? 'form' : 'list');
$id = (int) ($_GET['id'] ?? 0);
$record = $id ? one('SELECT * FROM vendors WHERE id=? AND deleted_at IS NULL', [$id]) : null;
if ($id && !$record) fail(404, 'Vendor not found.');

function vendor_link_input(string $key): string
{
    $url = optional_text($key, 2048);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) throw new InvalidArgumentException('Enter valid website / login links.');
    return $url;
}
function vendor_sync_paid(int $vendorId): void
{
    $paid = (string) query('SELECT COALESCE(SUM(amount),0) FROM vendor_payments WHERE vendor_id=?', [$vendorId])->fetchColumn();
    query('UPDATE vendors SET paid_amount=? WHERE id=?', [$paid, $vendorId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete_vendor') {
            $vid = positive_id($_POST['id'] ?? null);
            query('UPDATE vendors SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL', [$vid]);
            flash('Vendor deleted.');
            redirect('vendors.php');
        }
        if ($action === 'delete_payment') {
            if (!$record) throw new InvalidArgumentException('Select a vendor.');
            query('DELETE FROM vendor_payments WHERE id=? AND vendor_id=?', [positive_id($_POST['payment_id'] ?? null), $id]);
            vendor_sync_paid($id);
            flash('Payment removed.');
            redirect('vendors.php?id=' . $id . '#vendor-payments');
        }
        if ($action !== 'save_vendor') throw new InvalidArgumentException('Invalid request.');
        $name = text_input('vendor_name', 150);
        $email = optional_text('email', 255);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid vendor email.');
        $status = (string) ($_POST['vendor_status'] ?? 'active');
        if (!isset(VENDOR_STATUSES[$status])) $status = 'active';
        $start = optional_date('starting_date'); $end = optional_date('ending_date');
        if ($start && $end && $end < $start) throw new InvalidArgumentException('Ending date cannot be before starting date.');
        $loginPassword = $_POST['login_password'] ?? '';
        if (!is_string($loginPassword) || strlen($loginPassword) > 1000) throw new InvalidArgumentException('Password must be 1,000 characters or fewer.');
        $cipher = $loginPassword !== '' ? credential_encrypt($loginPassword) : ($record['login_password_cipher'] ?? null);
        $total = money_cents(trim((string) ($_POST['payment_total'] ?? '')) === '' ? '0' : trim((string) $_POST['payment_total']));
        // New payments made to this vendor.
        $newPayments = [];
        $posted = $_POST['payments'] ?? [];
        if (!is_array($posted) || count($posted) > 50) throw new InvalidArgumentException('Add up to 50 payments at a time.');
        foreach ($posted as $p) {
            if (!is_array($p) || (trim((string) ($p['amount'] ?? '')) === '' && trim((string) ($p['date'] ?? '')) === '')) continue;
            $cents = money_cents(trim((string) ($p['amount'] ?? '')));
            if ($cents <= 0) throw new InvalidArgumentException('Payment must be greater than zero.');
            $note = is_string($p['note'] ?? null) ? mb_substr(trim($p['note']), 0, 255) : '';
            $newPayments[] = [date_input('date', $p), decimal_money($cents), $note];
        }
        $values = [
            $name, (string) ($_POST['service_type'] ?? '') !== '' ? mb_substr(trim((string) $_POST['service_type']), 0, 100) : '',
            optional_text('contact_person', 150), optional_text('phone', 30), $email, vendor_link_input('website_url'),
            optional_text('address', 500), optional_text('gst_number', 30), optional_text('bank_details', 500), $start, $end, $status,
            vendor_link_input('login_url'), optional_text('login_username', 255), $cipher, decimal_money($total), optional_text('notes', 2000),
        ];
        db()->beginTransaction();
        if ($record) {
            query('UPDATE vendors SET vendor_name=?,service_type=?,contact_person=?,phone=?,email=?,website_url=?,address=?,gst_number=?,bank_details=?,starting_date=?,ending_date=?,vendor_status=?,login_url=?,login_username=?,login_password_cipher=?,payment_total=?,notes=? WHERE id=?', array_merge($values, [$id]));
            $vid = $id;
        } else {
            query('INSERT INTO vendors (vendor_name,service_type,contact_person,phone,email,website_url,address,gst_number,bank_details,starting_date,ending_date,vendor_status,login_url,login_username,login_password_cipher,payment_total,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($values, [$user['id']]));
            $vid = (int) db()->lastInsertId();
        }
        foreach ($newPayments as [$date, $amount, $note]) query('INSERT INTO vendor_payments (vendor_id,payment_date,amount,note,created_by) VALUES (?,?,?,?,?)', [$vid, $date, $amount, $note, $user['id']]);
        vendor_sync_paid($vid);
        db()->commit();
        flash('Vendor ' . $name . ' saved.' . ($newPayments ? ' ' . count($newPayments) . ' payment' . (count($newPayments) === 1 ? '' : 's') . ' added.' : ''));
        redirect('vendors.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
        $mode = isset($_GET['add']) || $id ? 'form' : 'list';
    }
}

$secret = static function (?string $cipher): string { try { return credential_decrypt($cipher); } catch (Throwable $e) { return ''; } };

if ($mode === 'form') {
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $v = static fn(string $k, string $d = ''): string => (string) ($isPost ? ($_POST[$k] ?? $d) : ($record[$k] ?? $d));
    $payments = $record ? rows('SELECT p.*,u.full_name AS recorded_by FROM vendor_payments p LEFT JOIN users u ON u.id=p.created_by WHERE p.vendor_id=? ORDER BY p.payment_date,p.id', [$id]) : [];
    page_start($record ? 'Edit vendor' : 'Add vendor', 'vendors.php');
    error_message($error);
    ?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / Vendors / <?= $record ? 'Edit' : 'Add' ?></p>
        <h1 class="ot-title"><?= $record ? h($record['vendor_name']) : 'Add vendor' ?></h1>
        <p class="ot-sub">Vendor details, their login and the payments we make to them.</p>
    </header>
    <form method="post" class="vd-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_vendor">
        <section class="ot-card"><h2>1 · Vendor details</h2>
            <div class="ot-form vd-grid">
                <label><span>Vendor / business name *</span><input name="vendor_name" maxlength="150" required value="<?= h($v('vendor_name')) ?>" placeholder="e.g. Sri Sai Printers"></label>
                <label><span>Service</span><select name="service_type"><option value="">Select service</option><?php foreach (VENDOR_SERVICES as $s): ?><option <?= $v('service_type') === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select></label>
                <label><span>Status</span><select name="vendor_status"><?php foreach (VENDOR_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $v('vendor_status', 'active') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label><span>Contact person</span><input name="contact_person" maxlength="150" value="<?= h($v('contact_person')) ?>"></label>
                <label><span>Phone</span><input name="phone" maxlength="30" value="<?= h($v('phone')) ?>" inputmode="tel"></label>
                <label><span>Email</span><input type="email" name="email" maxlength="255" value="<?= h($v('email')) ?>"></label>
                <label><span>Website</span><input name="website_url" maxlength="2048" value="<?= h($v('website_url')) ?>" placeholder="https://"></label>
                <label><span>GST number</span><input name="gst_number" maxlength="30" value="<?= h($v('gst_number')) ?>"></label>
                <label><span>Starting date</span><input type="date" name="starting_date" value="<?= h($v('starting_date')) ?>"></label>
                <label><span>Ending date</span><input type="date" name="ending_date" value="<?= h($v('ending_date')) ?>"></label>
                <label class="vd-wide"><span>Address</span><input name="address" maxlength="500" value="<?= h($v('address')) ?>"></label>
                <label class="vd-wide"><span>Bank / UPI details</span><input name="bank_details" maxlength="500" value="<?= h($v('bank_details')) ?>" placeholder="A/c no, IFSC or UPI ID"></label>
            </div>
        </section>
        <section class="ot-card"><h2>2 · Vendor login <small class="ot-help">(their portal / account, optional)</small></h2>
            <div class="ot-form vd-grid">
                <label><span>Login link</span><input name="login_url" maxlength="2048" value="<?= h($v('login_url')) ?>" placeholder="https://"></label>
                <label><span>Username / email</span><input name="login_username" maxlength="255" value="<?= h($v('login_username')) ?>"></label>
                <label><span>Password<?= !empty($record['login_password_cipher']) ? ' <small>(leave empty to keep)</small>' : '' ?></span><span class="tl-pass"><input type="password" id="vendor-password" name="login_password" maxlength="1000" autocomplete="new-password" placeholder="<?= !empty($record['login_password_cipher']) ? '••••••••' : '' ?>"><button type="button" class="tl-eye" data-password-toggle="vendor-password" aria-label="Show password" title="Show password"><?= icon('eye') ?></button></span></label>
            </div>
        </section>
        <section class="ot-card"><h2>3 · Payments to vendor</h2>
            <div class="vd-money">
                <div><span>Total amount</span><strong><?= h(rupees_label($record['payment_total'] ?? 0)) ?></strong></div>
                <div><span>Paid so far</span><strong><?= h(rupees_label($record['paid_amount'] ?? 0)) ?></strong></div>
                <div class="is-due"><span>Still to pay</span><strong><?= h(rupees_label(max(0, (float) ($record['payment_total'] ?? 0) - (float) ($record['paid_amount'] ?? 0)))) ?></strong></div>
            </div>
            <div class="ot-form vd-grid">
                <label><span>Total / package amount (₹)</span><input type="number" name="payment_total" min="0" max="9999999999.99" step="0.01" value="<?= h($v('payment_total', '0.00')) ?>"></label>
            </div>
            <p class="ot-help">Add money paid to this vendor. Leave empty if nothing new was paid.</p>
            <div class="vd-pay-rows" data-pay-rows>
                <?php $draft = $isPost && is_array($_POST['payments'] ?? null) ? $_POST['payments'] : [['date' => '', 'amount' => '', 'note' => '']]; foreach (array_values($draft) as $i => $p): if (!is_array($p)) continue; ?>
                    <div class="ot-form vd-pay-row"><label><span>Date paid</span><input type="date" name="payments[<?= $i ?>][date]" value="<?= h($p['date'] ?? '') ?>"></label><label><span>Amount (₹)</span><input type="number" name="payments[<?= $i ?>][amount]" min="0.01" step="0.01" value="<?= h($p['amount'] ?? '') ?>"></label><label><span>Note</span><input name="payments[<?= $i ?>][note]" maxlength="255" value="<?= h($p['note'] ?? '') ?>" placeholder="Bill no / UPI ref"></label></div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="vd-add" data-add-pay>+ Add another payment</button>
        </section>
        <section class="ot-card"><h2>4 · Notes</h2><div class="ot-form"><label><span>Notes</span><input name="notes" maxlength="2000" value="<?= h($v('notes')) ?>" placeholder="Anything to remember about this vendor"></label></div></section>
        <div class="ot-actions"><button class="ot-gold" type="submit"><?= $record ? 'Save vendor' : 'Add vendor' ?></button><a href="vendors.php">Cancel</a></div>
    </form>
    <?php if ($record): ?>
        <section class="ot-card" id="vendor-payments">
            <div class="ot-card-head"><h2>Saved payments</h2><span><?= count($payments) ?></span></div>
            <div class="table-scroll"><table><thead><tr><th>Date</th><th>Amount</th><th>Note</th><th>Recorded by</th><th></th></tr></thead><tbody>
                <?php if (!$payments) empty_row(5, 'No payments made yet.'); foreach ($payments as $p): ?>
                    <tr><td><?= h(date('d M Y', strtotime($p['payment_date']))) ?></td><td><?= h(rupees_label($p['amount'])) ?></td><td><?= h($p['note'] ?: '—') ?></td><td><?= h($p['recorded_by'] ?? '—') ?></td><td><form method="post" data-confirm="Remove this payment?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button type="submit" class="icon-button danger" title="Remove" aria-label="Remove payment"><?= icon('delete') ?></button></form></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
</div>
<script>
(() => {
    const rows = document.querySelector('[data-pay-rows]');
    document.querySelector('[data-add-pay]')?.addEventListener('click', () => {
        const i = rows.children.length, row = rows.firstElementChild.cloneNode(true);
        row.querySelectorAll('input').forEach(input => { input.value = ''; input.name = input.name.replace(/\[\d+\]/, '[' + i + ']'); });
        rows.appendChild(row);
    });
})();
</script>
    <?php
    page_end();
    exit;
}

$vendors = rows('SELECT * FROM vendors WHERE deleted_at IS NULL ORDER BY vendor_name,id');
$totals = ['total' => 0.0, 'paid' => 0.0];
foreach ($vendors as $vendor) { $totals['total'] += (float) $vendor['payment_total']; $totals['paid'] += (float) $vendor['paid_amount']; }
page_start('Vendors', 'vendors.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head vd-head">
        <div><p class="ot-crumb">Dashboard / Vendors</p><h1 class="ot-title">Vendors</h1><p class="ot-sub">Printers, freelancers and suppliers we work with, and what we owe them.</p></div>
        <a class="ot-gold vd-new" href="vendors.php?add=1">+ Add vendor</a>
    </header>
    <div class="vd-money">
        <div><span>Vendors</span><strong><?= count($vendors) ?></strong></div>
        <div><span>Paid so far</span><strong><?= h(rupees_label($totals['paid'])) ?></strong></div>
        <div class="is-due"><span>Still to pay</span><strong><?= h(rupees_label(max(0, $totals['total'] - $totals['paid']))) ?></strong></div>
    </div>
    <section class="ot-card">
        <div class="table-scroll"><table><thead><tr><th>Vendor</th><th>Service</th><th>Contact</th><th>Status</th><th>Total</th><th>Paid</th><th>Balance</th><th>Actions</th></tr></thead><tbody>
            <?php if (!$vendors) empty_row(8, 'No vendors yet. Use “Add vendor”.'); foreach ($vendors as $vendor): ?>
                <tr>
                    <td><strong><?= h($vendor['vendor_name']) ?></strong><?php if ($vendor['gst_number'] !== ''): ?><br><small class="muted">GST <?= h($vendor['gst_number']) ?></small><?php endif; ?></td>
                    <td><?= h($vendor['service_type'] ?: '—') ?></td>
                    <td><?= h($vendor['contact_person'] ?: '—') ?><?php if ($vendor['phone'] !== ''): ?><br><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $vendor['phone'])) ?>"><?= h($vendor['phone']) ?></a><?php endif; ?></td>
                    <td><span class="badge client-status is-<?= h($vendor['vendor_status']) ?>"><?= h(VENDOR_STATUSES[$vendor['vendor_status']] ?? 'Active') ?></span></td>
                    <td><?= h(rupees_label($vendor['payment_total'])) ?></td>
                    <td><?= h(rupees_label($vendor['paid_amount'])) ?></td>
                    <td><?= h(rupees_label(max(0, (float) $vendor['payment_total'] - (float) $vendor['paid_amount']))) ?></td>
                    <td><div class="actions"><?php detail_button('vendor-' . $vendor['id']); ?><a class="icon-button" href="vendors.php?id=<?= (int) $vendor['id'] ?>" title="Edit vendor" aria-label="Edit vendor"><?= icon('edit') ?></a><?php delete_button((int) $vendor['id'], 'delete_vendor', 'Delete this vendor?'); ?></div></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php foreach ($vendors as $vendor) {
    $pass = $secret($vendor['login_password_cipher']);
    detail_dialog('vendor-' . $vendor['id'], $vendor['vendor_name'], [
        'Service' => $vendor['service_type'], 'Status' => VENDOR_STATUSES[$vendor['vendor_status']] ?? 'Active', 'Contact person' => $vendor['contact_person'],
        'Phone' => $vendor['phone'], 'Email' => $vendor['email'], 'Website' => $vendor['website_url'], 'Address' => $vendor['address'],
        'GST number' => $vendor['gst_number'], 'Bank / UPI' => $vendor['bank_details'], 'Starting date' => $vendor['starting_date'], 'Ending date' => $vendor['ending_date'],
        'Login link' => $vendor['login_url'], 'Login username' => $vendor['login_username'], 'Login password' => $pass ?: 'Not set',
        'Total amount' => rupees_label($vendor['payment_total']), 'Paid' => rupees_label($vendor['paid_amount']),
        'Balance' => rupees_label(max(0, (float) $vendor['payment_total'] - (float) $vendor['paid_amount'])), 'Notes' => $vendor['notes'],
    ]);
}
page_end(); ?>
