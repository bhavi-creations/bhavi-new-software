<?php
// Invoices: the Managing Director raises GST / non-GST invoices to clients, prints them and marks them paid.
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/invoice.php';
$user = require_roles(['admin']);
$error = null;

$mode = isset($_GET['add']) || isset($_GET['id']) ? 'form' : 'list';
$id = (int) ($_GET['id'] ?? 0);
$record = $id ? one('SELECT * FROM invoices WHERE id=? AND deleted_at IS NULL', [$id]) : null;
if ($id && !$record) fail(404, 'Invoice not found.');
$clientList = rows('SELECT id,client_name,package,payment_total,gst_number FROM clients WHERE deleted_at IS NULL ORDER BY client_name');

/** Decimal text with up to 2 places → hundredths (quantity / GST %). */
function invoice_hundredths(string $value, string $label, int $max): int
{
    $value = trim($value);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) throw new InvalidArgumentException('Enter a valid '.$label.'.');
    [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
    $n = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    if ($n > $max) throw new InvalidArgumentException(ucfirst($label).' is too large.');
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete_invoice') {
            query('UPDATE invoices SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL', [positive_id($_POST['id'] ?? null)]);
            flash('Invoice deleted.');
            redirect('invoices.php');
        }
        if ($action === 'set_status') {
            $status = (string) ($_POST['status'] ?? '');
            if (!isset(INVOICE_STATUSES[$status])) throw new InvalidArgumentException('Choose a valid status.');
            // Paid means the full grand total is received, so the balance becomes 0.
            query("UPDATE invoices SET status=?,advance=IF(?='paid',total,advance) WHERE id=? AND deleted_at IS NULL", [$status, $status, positive_id($_POST['id'] ?? null)]);
            flash('Invoice marked '.strtolower(INVOICE_STATUSES[$status]).'.');
            redirect('invoices.php');
        }
        if ($action !== 'save_invoice') throw new InvalidArgumentException('Invalid request.');

        $clientId = positive_id($_POST['client_id'] ?? null);
        if (!one('SELECT id FROM clients WHERE id=? AND deleted_at IS NULL', [$clientId])) throw new InvalidArgumentException('Choose a client.');
        $invoiceDate = date_input('invoice_date');
        $dueDate = optional_date('due_date');
        if ($dueDate && $dueDate < $invoiceDate) throw new InvalidArgumentException('Due date cannot be before the invoice date.');
        $gstType = (string) ($_POST['gst_type'] ?? 'none');
        if (!isset(INVOICE_GST_TYPES[$gstType])) $gstType = 'none';
        $gstRate = $gstType === 'none' ? 0 : invoice_hundredths((string) ($_POST['gst_rate'] ?? '18'), 'GST %', 10000);
        $status = (string) ($_POST['status'] ?? 'unpaid');
        if (!isset(INVOICE_STATUSES[$status])) $status = 'unpaid';

        // Each item: service, description, qty × price, minus its discount % = item total.
        $items = []; $subtotal = 0; $discount = 0;
        $posted = $_POST['items'] ?? [];
        if (!is_array($posted) || count($posted) > 50) throw new InvalidArgumentException('Add up to 50 items.');
        foreach ($posted as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Invalid item.');
            $service = trim((string) ($row['service_name'] ?? ''));
            $desc = trim((string) ($row['description'] ?? ''));
            $rateText = trim((string) ($row['rate'] ?? ''));
            if ($service === '' && $desc === '' && $rateText === '') continue;
            $n = count($items) + 1;
            if ($service === '' && $desc === '') throw new InvalidArgumentException('Enter the service for item '.$n.'.');
            if (mb_strlen($service) > 150 || mb_strlen($desc) > 500) throw new InvalidArgumentException('Service or description for item '.$n.' is too long.');
            $qty = invoice_hundredths((string) ($row['quantity'] ?? '1') ?: '1', 'quantity for item '.$n, 9999999999);
            if (!$qty) throw new InvalidArgumentException('Quantity for item '.$n.' must be more than zero.');
            $rate = money_cents($rateText === '' ? '0' : $rateText);
            $disc = invoice_hundredths((string) ($row['discount_percent'] ?? '0') ?: '0', 'discount % for item '.$n, 10000);
            $gross = (int) round($qty * $rate / 100);
            $amount = (int) round($gross * (10000 - $disc) / 10000);
            $subtotal += $amount; $discount += $gross - $amount;
            $items[] = ['service_name' => $service, 'description' => $desc, 'quantity' => decimal_money($qty), 'rate' => decimal_money($rate), 'discount_percent' => decimal_money($disc), 'amount' => decimal_money($amount)];
        }
        if (!$items) throw new InvalidArgumentException('Add at least one item.');
        $tax = (int) round($subtotal * $gstRate / 10000);
        $total = $subtotal + $tax;
        if ($total > 999999999999) throw new InvalidArgumentException('Invoice total is too large.');
        $advanceText = trim((string) ($_POST['advance'] ?? ''));
        $advance = money_cents($advanceText === '' ? '0' : $advanceText);
        if ($advance > $total) throw new InvalidArgumentException('Advance cannot be more than the grand total.');
        if ($status === 'paid') $advance = $total; // Paid = full amount received, balance 0.

        $invoiceNo = trim((string) ($_POST['invoice_no'] ?? ''));
        if ($invoiceNo === '') $invoiceNo = invoice_next_no($invoiceDate);
        if (mb_strlen($invoiceNo) > 40) throw new InvalidArgumentException('Invoice number is too long.');
        if (one('SELECT id FROM invoices WHERE invoice_no=? AND id<>?', [$invoiceNo, $id])) throw new InvalidArgumentException('Invoice number '.$invoiceNo.' is already used. Choose another.');

        $values = [$invoiceNo, $clientId, $invoiceDate, $dueDate, $gstType, decimal_money($gstRate), decimal_money($subtotal), decimal_money($discount), decimal_money($tax), decimal_money($total), decimal_money($advance), $status, optional_text('notes', 2000)];
        db()->beginTransaction();
        if ($record) {
            query('UPDATE invoices SET invoice_no=?,client_id=?,invoice_date=?,due_date=?,gst_type=?,gst_rate=?,subtotal=?,discount=?,tax_amount=?,total=?,advance=?,status=?,notes=? WHERE id=?', array_merge($values, [$id]));
            query('DELETE FROM invoice_items WHERE invoice_id=?', [$id]);
            $invoiceId = $id;
        } else {
            query('INSERT INTO invoices (invoice_no,client_id,invoice_date,due_date,gst_type,gst_rate,subtotal,discount,tax_amount,total,advance,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($values, [$user['id']]));
            $invoiceId = (int) db()->lastInsertId();
        }
        foreach ($items as $i => $item) {
            query('INSERT INTO invoice_items (invoice_id,sort_order,service_name,description,quantity,rate,discount_percent,amount) VALUES (?,?,?,?,?,?,?,?)', [$invoiceId, $i, $item['service_name'], $item['description'], $item['quantity'], $item['rate'], $item['discount_percent'], $item['amount']]);
        }
        db()->commit();
        flash('Invoice '.$invoiceNo.' saved.');
        redirect('invoices.php?saved='.$invoiceId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
        $mode = isset($_GET['add']) || $id ? 'form' : 'list';
    }
}

if ($mode === 'form') {
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $today = date('Y-m-d');
    $defaults = ['invoice_no' => invoice_next_no($today), 'invoice_date' => $today, 'due_date' => date('Y-m-d', strtotime('+7 days')), 'gst_type' => 'cgst_sgst', 'gst_rate' => '18', 'advance' => '', 'status' => 'unpaid', 'notes' => '', 'client_id' => (string) (int) ($_GET['client'] ?? 0)];
    $v = static function (string $k) use ($isPost, $record, $defaults): string {
        if ($isPost) return (string) ($_POST[$k] ?? '');
        if ($record) return (string) ($record[$k] ?? '');
        return (string) ($defaults[$k] ?? '');
    };
    if ($isPost && is_array($_POST['items'] ?? null)) $items = array_values(array_filter($_POST['items'], 'is_array'));
    elseif ($record) $items = rows('SELECT service_name,description,quantity,rate,discount_percent FROM invoice_items WHERE invoice_id=? ORDER BY sort_order,id', [$id]);
    else $items = [];
    if (!$items) $items = [['service_name' => '', 'description' => '', 'quantity' => '1', 'rate' => '', 'discount_percent' => '0']];
    $trim0 = static fn($n, string $fallback = '0'): string => rtrim(rtrim((string) $n, '0'), '.') ?: $fallback;
    if ($record && !$isPost) $defaults['gst_rate'] = $record['gst_rate'];
    page_start($record ? 'Edit invoice' : 'New invoice', 'invoices.php');
    error_message($error);
    ?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / Invoices / <?= $record ? 'Edit' : 'New' ?></p>
        <h1 class="ot-title"><?= $record ? 'Invoice '.h($record['invoice_no']) : 'New invoice' ?></h1>
        <p class="ot-sub">Choose the client, add the services and the GST. Totals are worked out for you.</p>
    </header>
    <form method="post" class="vd-form" autocomplete="off" data-invoice-form><?= csrf_field() ?><input type="hidden" name="action" value="save_invoice">
        <section class="ot-card"><h2>1 · Invoice details</h2>
            <div class="ot-form vd-grid">
                <label><span>Client *</span><select name="client_id" required data-client><option value="">Select client</option><?php foreach ($clientList as $c): ?><option value="<?= (int) $c['id'] ?>" data-package="<?= h($c['package']) ?>" data-amount="<?= h($c['payment_total']) ?>" data-gst="<?= h($c['gst_number']) ?>" <?= $v('client_id') === (string) $c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select><small data-client-gst></small></label>
                <label><span>Invoice number <small>(auto)</small></span><input name="invoice_no" maxlength="40" value="<?= h($v('invoice_no')) ?>" placeholder="Leave empty for next number"></label>
                <label><span>Status</span><select name="status" data-status><?php foreach (INVOICE_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $v('status') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label><span>Invoice date *</span><input type="date" name="invoice_date" required value="<?= h($v('invoice_date')) ?>"></label>
                <label><span>Due date</span><input type="date" name="due_date" value="<?= h($v('due_date')) ?>"></label>
            </div>
        </section>
        <section class="ot-card"><h2>2 · Services / items</h2>
            <div class="inv-items" data-items>
                <?php foreach ($items as $i => $it): ?>
                    <div class="ot-form inv-row">
                        <label><span>Service *</span><input name="items[<?= $i ?>][service_name]" maxlength="150" value="<?= h($it['service_name'] ?? '') ?>" placeholder="e.g. SEO" list="inv-services"></label>
                        <label class="inv-desc"><span>Description</span><input name="items[<?= $i ?>][description]" maxlength="500" value="<?= h($it['description'] ?? '') ?>" placeholder="e.g. Google Ads and YouTube"></label>
                        <label><span>Qty</span><input type="number" name="items[<?= $i ?>][quantity]" min="0.01" step="0.01" value="<?= h($trim0($it['quantity'] ?? '1', '1')) ?>" data-qty></label>
                        <label><span>Price/Unit (₹)</span><input type="number" name="items[<?= $i ?>][rate]" min="0" max="9999999999.99" step="0.01" value="<?= h($it['rate'] ?? '') ?>" data-rate></label>
                        <label><span>Disc %</span><input type="number" name="items[<?= $i ?>][discount_percent]" min="0" max="100" step="0.01" value="<?= h($trim0($it['discount_percent'] ?? '0')) ?>" data-disc></label>
                        <label><span>Total</span><output data-amount>₹0</output></label>
                        <button type="button" class="icon-button danger inv-remove" data-remove title="Remove item" aria-label="Remove item"><?= icon('delete') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <datalist id="inv-services"><?php foreach (['Social Media Management', 'SEO', 'Paid AD', 'Website Development', 'Graphic Design', 'Video Editing', 'Branding', 'Content Writing', 'Google My Business'] as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?></datalist>
            <button type="button" class="vd-add" data-add-item>+ Add another item</button>
        </section>
        <section class="ot-card"><h2>3 · GST &amp; total</h2>
            <div class="ot-form vd-grid">
                <label><span>GST</span><select name="gst_type" data-gst-type><?php foreach (INVOICE_GST_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $v('gst_type') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label data-gst-rate-wrap><span>GST %</span><input type="number" name="gst_rate" min="0" max="100" step="0.01" value="<?= h(rtrim(rtrim($isPost ? (string) ($_POST['gst_rate'] ?? '18') : (string) $defaults['gst_rate'], '0'), '.') ?: '0') ?>" data-gst-rate></label>
                <label><span>Advance received (₹)</span><input type="number" name="advance" min="0" step="0.01" value="<?= h($v('advance') === '0.00' ? '' : $v('advance')) ?>" data-advance placeholder="0"></label>
            </div>
            <dl class="inv-totals">
                <dt>Subtotal</dt><dd data-t-sub>₹0</dd>
                <dt data-t-tax-label>GST</dt><dd data-t-tax>₹0</dd>
                <dt class="inv-grand">Grand Total</dt><dd class="inv-grand" data-t-total>₹0</dd>
                <dt>Advance</dt><dd data-t-adv>₹0</dd>
                <dt class="inv-grand">Balance</dt><dd class="inv-grand" data-t-bal>₹0</dd>
            </dl>
        </section>
        <section class="ot-card"><h2>4 · Notes <small class="ot-help">(printed on the invoice)</small></h2><div class="ot-form"><label><span>Notes / terms</span><textarea name="notes" maxlength="2000" rows="3" placeholder="e.g. Payment due within 7 days. Thank you for your business."><?= h($v('notes')) ?></textarea></label></div></section>
        <div class="ot-actions"><button class="ot-gold" type="submit"><?= $record ? 'Save invoice' : 'Create invoice' ?></button><a href="invoices.php">Cancel</a></div>
    </form>
</div>
<script>
(() => {
    const form = document.querySelector('[data-invoice-form]'), list = form.querySelector('[data-items]');
    const inr = n => '₹' + n.toLocaleString('en-IN', {minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2});
    const num = el => Math.max(0, parseFloat(el && el.value) || 0);
    const gstType = form.querySelector('[data-gst-type]'), rateWrap = form.querySelector('[data-gst-rate-wrap]');
    function calc() {
        let sub = 0;
        list.querySelectorAll('.inv-row').forEach(row => {
            const gross = Math.round(num(row.querySelector('[data-qty]')) * num(row.querySelector('[data-rate]')) * 100);
            const amt = Math.round(gross * (100 - Math.min(100, num(row.querySelector('[data-disc]')))) / 100) / 100;
            row.querySelector('[data-amount]').textContent = inr(amt); sub += amt;
        });
        sub = Math.round(sub * 100) / 100;
        const type = gstType.value, rate = type === 'none' ? 0 : num(form.querySelector('[data-gst-rate]'));
        const tax = Math.round(sub * rate) / 100, total = Math.round((sub + tax) * 100) / 100;
        const paid = form.querySelector('[data-status]').value === 'paid';
        const adv = paid ? total : Math.min(total, num(form.querySelector('[data-advance]')));
        rateWrap.hidden = type === 'none';
        form.querySelector('[data-t-sub]').textContent = inr(sub);
        form.querySelector('[data-t-tax-label]').textContent = 'GST ' + rate + '%';
        form.querySelector('[data-t-tax]').textContent = inr(tax);
        form.querySelector('[data-t-total]').textContent = inr(total);
        form.querySelector('[data-t-adv]').textContent = inr(adv);
        form.querySelector('[data-t-bal]').textContent = inr(Math.round((total - adv) * 100) / 100);
    }
    function renumber() { list.querySelectorAll('.inv-row').forEach((row, i) => row.querySelectorAll('input').forEach(inp => inp.name = inp.name.replace(/items\[\d+\]/, 'items[' + i + ']'))); }
    form.querySelector('[data-add-item]').addEventListener('click', () => {
        const row = list.lastElementChild.cloneNode(true);
        row.querySelectorAll('input').forEach(inp => { inp.value = inp.matches('[data-qty]') ? '1' : (inp.matches('[data-disc]') ? '0' : ''); });
        list.appendChild(row); renumber(); calc(); row.querySelector('input').focus();
    });
    list.addEventListener('click', e => {
        const btn = e.target.closest('[data-remove]'); if (!btn) return;
        const rows = list.querySelectorAll('.inv-row');
        if (rows.length === 1) rows[0].querySelectorAll('input').forEach(inp => { inp.value = inp.matches('[data-qty]') ? '1' : (inp.matches('[data-disc]') ? '0' : ''); });
        else btn.closest('.inv-row').remove();
        renumber(); calc();
    });
    const client = form.querySelector('[data-client]'), gstNote = form.querySelector('[data-client-gst]');
    function showGst() { const o = client.selectedOptions[0]; gstNote.textContent = o && o.dataset.gst ? 'Client GSTIN: ' + o.dataset.gst : ''; }
    client.addEventListener('change', () => {
        showGst();
        const o = client.selectedOptions[0], first = list.querySelector('.inv-row');
        // New invoice: fill the first empty item with the client's package.
        if (o && o.value && first && !first.querySelector('[name$="[service_name]"]').value && !first.querySelector('[data-rate]').value) {
            first.querySelector('[name$="[service_name]"]').value = o.dataset.package || '';
            if (parseFloat(o.dataset.amount) > 0) first.querySelector('[data-rate]').value = o.dataset.amount;
            calc();
        }
    });
    form.addEventListener('input', calc); form.addEventListener('change', calc);
    showGst(); calc();
})();
</script>
    <?php
    page_end();
    exit;
}

// List
$filterClient = (int) ($_GET['client'] ?? 0);
$filterStatus = (string) ($_GET['status'] ?? '');
$where = ['i.deleted_at IS NULL']; $params = [];
if ($filterClient) { $where[] = 'i.client_id=?'; $params[] = $filterClient; }
if (!isset(INVOICE_STATUSES[$filterStatus])) $filterStatus = '';
// Search: invoice number, client name, contact, phone, notes, services or the total amount.
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
if ($search !== '') {
    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    $amount = preg_replace('/[^0-9.]/', '', $search);
    $where[] = '(i.invoice_no LIKE ? OR c.client_name LIKE ? OR c.contact_person LIKE ? OR c.phone LIKE ? OR i.notes LIKE ? OR EXISTS (SELECT 1 FROM invoice_items it WHERE it.invoice_id=i.id AND (it.service_name LIKE ? OR it.description LIKE ?))'.($amount !== '' && is_numeric($amount) ? ' OR i.total=?' : '').')';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
    if ($amount !== '' && is_numeric($amount)) $params[] = $amount;
}
$allInvoices = rows('SELECT i.*,c.client_name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE '.implode(' AND ', $where).' ORDER BY i.invoice_date DESC,i.id DESC', $params);
// Amount received / still due per invoice (Paid = full total received).
$received = static fn(array $inv): float => $inv['status'] === 'paid' ? (float) $inv['total'] : min((float) $inv['total'], (float) $inv['advance']);
$sum = ['all' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0];
$counts = ['' => count($allInvoices), 'unpaid' => 0, 'paid' => 0, 'cancelled' => 0];
foreach ($allInvoices as $inv) {
    $counts[$inv['status']] = ($counts[$inv['status']] ?? 0) + 1;
    if ($inv['status'] === 'cancelled') continue;
    $got = $received($inv);
    $sum['all'] += (float) $inv['total']; $sum['paid'] += $got; $sum['unpaid'] += (float) $inv['total'] - $got;
}
$invoices = $filterStatus === '' ? $allInvoices : array_values(array_filter($allInvoices, static fn($inv) => $inv['status'] === $filterStatus));
$tabUrl = static function (string $status) use ($filterClient, $search): string {
    $q = array_filter(['client' => $filterClient ?: null, 'status' => $status ?: null, 'q' => $search !== '' ? $search : null]);
    return 'invoices.php'.($q ? '?'.http_build_query($q) : '');
};
$savedId = (int) ($_GET['saved'] ?? 0);
page_start('Invoices', 'invoices.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head vd-head">
        <div><p class="ot-crumb">Dashboard / Invoices</p><h1 class="ot-title">Invoices</h1><p class="ot-sub">Create invoices for clients, print or save them as PDF, and track which are paid.</p></div>
        <a class="ot-gold vd-new" href="invoices.php?add=1">+ New invoice</a>
    </header>
    <?php if ($savedId): ?><div class="alert success" role="status"><a href="invoice-print.php?id=<?= $savedId ?>" target="_blank" rel="noopener"><strong>Open / print invoice →</strong></a></div><?php endif; ?>
    <div class="vd-money">
        <a class="inv-card<?= $filterStatus === '' ? ' is-on' : '' ?>" href="<?= h($tabUrl('')) ?>"><span>Total invoiced</span><strong><?= h(rupees_label($sum['all'])) ?></strong></a>
        <a class="inv-card<?= $filterStatus === 'paid' ? ' is-on' : '' ?>" href="<?= h($tabUrl('paid')) ?>"><span>Paid (received)</span><strong><?= h(rupees_label($sum['paid'])) ?></strong></a>
        <a class="inv-card is-due<?= $filterStatus === 'unpaid' ? ' is-on' : '' ?>" href="<?= h($tabUrl('unpaid')) ?>"><span>Unpaid (balance)</span><strong><?= h(rupees_label($sum['unpaid'])) ?></strong></a>
    </div>
    <section class="ot-card">
        <div class="inv-filters">
            <nav class="inv-tabs" aria-label="Filter by status">
                <?php foreach (['' => 'All', 'unpaid' => 'Unpaid', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $k => $l): ?>
                    <a href="<?= h($tabUrl($k)) ?>" class="inv-tab is-<?= $k ?: 'all' ?><?= $filterStatus === $k ? ' is-on' : '' ?>" <?= $filterStatus === $k ? 'aria-current="page"' : '' ?>><?= h($l) ?> <em><?= (int) ($counts[$k] ?? 0) ?></em></a>
                <?php endforeach; ?>
            </nav>
            <form method="get" class="inv-client-filter">
                <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?= h($filterStatus) ?>"><?php endif; ?>
                <select name="client" aria-label="Filter by client" onchange="this.form.submit()"><option value="">All clients</option><?php foreach ($clientList as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $filterClient === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select>
                <span class="inv-search"><input type="search" name="q" value="<?= h($search) ?>" maxlength="100" placeholder="Search invoice no., client, service, amount…" aria-label="Search invoices"><button type="submit" class="button secondary">Search</button></span>
                <?php if ($filterClient || $filterStatus || $search !== ''): ?><a href="invoices.php">Clear</a><?php endif; ?>
            </form>
        </div>
        <div class="table-scroll"><table><thead><tr><th>Invoice no.</th><th>Date</th><th>Client</th><th>Total</th><th>Balance</th><th>Due date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php if (!$invoices) empty_row(8, $search !== '' ? 'No invoices match “'.$search.'”.' : ($filterStatus !== '' ? 'No '.strtolower(INVOICE_STATUSES[$filterStatus]).' invoices.' : 'No invoices yet. Use “New invoice”.')); foreach ($invoices as $inv): $overdue = $inv['status'] === 'unpaid' && $inv['due_date'] && $inv['due_date'] < date('Y-m-d'); $due = $inv['status'] === 'cancelled' ? 0 : (float) $inv['total'] - $received($inv); ?>
                <tr>
                    <td><strong><?= h($inv['invoice_no']) ?></strong></td>
                    <td><?= h(date('d M Y', strtotime($inv['invoice_date']))) ?></td>
                    <td><?= h($inv['client_name']) ?></td>
                    <td><?= h(rupees_label($inv['total'])) ?></td>
                    <td><?= $inv['status'] === 'cancelled' ? '—' : h(rupees_label($due)) ?></td>
                    <td><?= $inv['due_date'] ? h(date('d M Y', strtotime($inv['due_date']))) : '—' ?><?php if ($overdue): ?> <small class="inv-overdue">Overdue</small><?php endif; ?></td>
                    <td><?= invoice_status_badge($inv['status']) ?></td>
                    <td><div class="actions">
                        <a class="button secondary" href="invoice-print.php?id=<?= (int) $inv['id'] ?>" target="_blank" rel="noopener">View / Print</a>
                        <?php if ($inv['status'] === 'unpaid'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="paid"><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>"><button type="submit" class="icon-button" title="Mark as paid" aria-label="Mark as paid"><?= icon('check') ?></button></form><?php endif; ?>
                        <a class="icon-button" href="invoices.php?id=<?= (int) $inv['id'] ?>" title="Edit invoice" aria-label="Edit invoice"><?= icon('edit') ?></a>
                        <?php delete_button((int) $inv['id'], 'delete_invoice', 'Delete invoice '.$inv['invoice_no'].'?'); ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php page_end(); ?>
