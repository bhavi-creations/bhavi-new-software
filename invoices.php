<?php
// Invoices & quotations: the Managing Director raises quotations and invoices to clients, prints them and marks them paid.
// A quotation has no bank details; when the client accepts it, "Convert to invoice" makes an editable invoice from it.
// GST is chosen per service line (0% when the service has no GST). Each invoice prints the bank account chosen on it.
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/invoice.php';
$user = require_roles(['admin']);
$error = null;

$mode = isset($_GET['add']) || isset($_GET['id']) ? 'form' : 'list';
$id = (int) ($_GET['id'] ?? 0);
$record = $id ? one('SELECT * FROM invoices WHERE id=? AND deleted_at IS NULL', [$id]) : null;
if ($id && !$record) fail(404, 'Invoice not found.');
// Which kind of document: from the record, else ?type=quotation.
$docType = $record ? (string) $record['doc_type'] : ((($_GET['type'] ?? '') === 'quotation') ? 'quotation' : 'invoice');
if (!in_array($docType, ['invoice', 'quotation'], true)) $docType = 'invoice';
$isQuote = $docType === 'quotation';
$docWord = $isQuote ? 'Quotation' : 'Invoice';
$clientList = rows('SELECT id,client_name,package,payment_total,paid_amount,gst_number FROM clients WHERE deleted_at IS NULL ORDER BY client_name');
// New invoices are filled with what the client still has to pay: package amount − amount already paid.
foreach ($clientList as &$c) {
    $c['pkg_amount'] = client_package_amount($c);
    $c['remaining'] = max(0, $c['pkg_amount'] - (float) $c['paid_amount']);
}
unset($c);
$bankAccounts = invoice_bank_accounts();
$serviceList = invoice_services();

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
            $doc = one('SELECT doc_type FROM invoices WHERE id=? AND deleted_at IS NULL', [positive_id($_POST['id'] ?? null)]);
            query('UPDATE invoices SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL', [positive_id($_POST['id'] ?? null)]);
            flash(($doc && $doc['doc_type'] === 'quotation' ? 'Quotation' : 'Invoice').' deleted.');
            redirect('invoices.php'.($doc && $doc['doc_type'] === 'quotation' ? '?type=quotation' : ''));
        }
        if ($action === 'set_status') {
            $status = (string) ($_POST['status'] ?? '');
            if (!isset(INVOICE_STATUSES[$status])) throw new InvalidArgumentException('Choose a valid status.');
            // Paid means the full grand total is received, so the balance becomes 0.
            query("UPDATE invoices SET status=?,advance=IF(?='paid',total,advance) WHERE id=? AND deleted_at IS NULL AND doc_type='invoice'", [$status, $status, positive_id($_POST['id'] ?? null)]);
            flash('Invoice marked '.strtolower(INVOICE_STATUSES[$status]).'.');
            redirect('invoices.php');
        }
        if ($action === 'set_quote_status') {
            $status = (string) ($_POST['status'] ?? '');
            if (!in_array($status, ['pending', 'accepted', 'rejected'], true)) throw new InvalidArgumentException('Choose a valid status.');
            query("UPDATE invoices SET status=? WHERE id=? AND deleted_at IS NULL AND doc_type='quotation' AND status<>'converted'", [$status, positive_id($_POST['id'] ?? null)]);
            flash('Quotation marked '.strtolower(QUOTATION_STATUSES[$status]).'.');
            redirect('invoices.php?type=quotation');
        }
        if ($action === 'convert_quotation') {
            // Copy the quotation into a new invoice (today's date, next invoice number), then open it for editing.
            $quoteId = positive_id($_POST['id'] ?? null);
            db()->beginTransaction();
            $quote = one("SELECT * FROM invoices WHERE id=? AND deleted_at IS NULL AND doc_type='quotation' FOR UPDATE", [$quoteId]);
            if (!$quote) throw new InvalidArgumentException('Quotation not found.');
            if ($quote['converted_invoice_id'] && one('SELECT id FROM invoices WHERE id=? AND deleted_at IS NULL', [$quote['converted_invoice_id']])) {
                db()->rollBack();
                flash('This quotation is already converted. Opening its invoice.');
                redirect('invoices.php?id='.(int) $quote['converted_invoice_id']);
            }
            $today = date('Y-m-d');
            $defaultBank = $bankAccounts[0]['id'] ?? null;
            query("INSERT INTO invoices (invoice_no,doc_type,client_id,invoice_date,due_date,gst_type,gst_rate,subtotal,discount,tax_amount,total,advance,status,notes,from_quotation_id,bank_account_id,created_by) VALUES (?,'invoice',?,?,?,?,?,?,?,?,?,0,'unpaid',?,?,?,?)",
                [invoice_next_no($today), $quote['client_id'], $today, date('Y-m-d', strtotime('+7 days')), $quote['gst_type'], $quote['gst_rate'], $quote['subtotal'], $quote['discount'], $quote['tax_amount'], $quote['total'], $quote['notes'], $quote['id'], $defaultBank, $user['id']]);
            $invoiceId = (int) db()->lastInsertId();
            query('INSERT INTO invoice_items (invoice_id,sort_order,service_name,description,hsn_sac,quantity,rate,discount_percent,amount,gst_rate,gst_amount) SELECT ?,sort_order,service_name,description,hsn_sac,quantity,rate,discount_percent,amount,gst_rate,gst_amount FROM invoice_items WHERE invoice_id=?', [$invoiceId, $quote['id']]);
            query("UPDATE invoices SET status='converted',converted_invoice_id=? WHERE id=?", [$invoiceId, $quote['id']]);
            db()->commit();
            flash('Quotation '.$quote['invoice_no'].' converted. Check the invoice below, edit anything you need and save.');
            redirect('invoices.php?id='.$invoiceId);
        }
        if ($action !== 'save_invoice') throw new InvalidArgumentException('Invalid request.');

        $clientId = positive_id($_POST['client_id'] ?? null);
        if (!one('SELECT id FROM clients WHERE id=? AND deleted_at IS NULL', [$clientId])) throw new InvalidArgumentException('Choose a client.');
        $invoiceDate = date_input('invoice_date');
        $dueDate = optional_date('due_date');
        if ($dueDate && $dueDate < $invoiceDate) throw new InvalidArgumentException(($isQuote ? 'Valid until' : 'Due date').' cannot be before the '.strtolower($docWord).' date.');
        $gstType = (string) ($_POST['gst_type'] ?? 'cgst_sgst');
        if (!isset(INVOICE_GST_TYPES[$gstType])) $gstType = 'cgst_sgst';
        if ($isQuote) {
            $status = (string) ($_POST['status'] ?? 'pending');
            if (!isset(QUOTATION_STATUSES[$status]) || ($status === 'converted' && ($record['status'] ?? '') !== 'converted')) $status = 'pending';
        } else {
            $status = (string) ($_POST['status'] ?? 'unpaid');
            if (!isset(INVOICE_STATUSES[$status])) $status = 'unpaid';
        }
        $bankId = null;
        if (!$isQuote && ($_POST['bank_account_id'] ?? '') !== '') {
            $bankId = positive_id($_POST['bank_account_id']);
            if (!in_array($bankId, array_map('intval', array_column($bankAccounts, 'id')), true)) throw new InvalidArgumentException('Choose a valid bank account.');
        }

        // Each item: service, description, qty × price, minus its discount % = item total; plus that service's GST %.
        $items = []; $subtotal = 0; $discount = 0; $tax = 0; $rates = [];
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
            $gstRate = $gstType === 'none' ? 0 : invoice_hundredths((string) ($row['gst_rate'] ?? '0') ?: '0', 'GST % for item '.$n, 10000);
            $gross = (int) round($qty * $rate / 100);
            $amount = (int) round($gross * (10000 - $disc) / 10000);
            $gstAmount = (int) round($amount * $gstRate / 10000);
            $subtotal += $amount; $discount += $gross - $amount; $tax += $gstAmount; $rates[$gstRate] = true;
            $items[] = ['service_name' => $service, 'description' => $desc, 'quantity' => decimal_money($qty), 'rate' => decimal_money($rate), 'discount_percent' => decimal_money($disc), 'amount' => decimal_money($amount), 'gst_rate' => decimal_money($gstRate), 'gst_amount' => decimal_money($gstAmount)];
        }
        if (!$items) throw new InvalidArgumentException('Add at least one item.');
        if ($tax === 0) $gstType = 'none';
        // One GST % for the whole invoice when every line has the same rate (shown on older layouts).
        $invoiceRate = count($rates) === 1 ? (int) array_key_first($rates) : 0;
        $total = $subtotal + $tax;
        if ($total > 999999999999) throw new InvalidArgumentException($docWord.' total is too large.');
        $advance = 0;
        if (!$isQuote) {
            $advanceText = trim((string) ($_POST['advance'] ?? ''));
            $advance = money_cents($advanceText === '' ? '0' : $advanceText);
            if ($advance > $total) throw new InvalidArgumentException('Advance cannot be more than the grand total.');
            if ($status === 'paid') $advance = $total; // Paid = full amount received, balance 0.
        }

        $invoiceNo = trim((string) ($_POST['invoice_no'] ?? ''));
        if ($invoiceNo === '') $invoiceNo = invoice_next_no($invoiceDate, $docType);
        if (mb_strlen($invoiceNo) > 40) throw new InvalidArgumentException($docWord.' number is too long.');
        if (one('SELECT id FROM invoices WHERE invoice_no=? AND id<>?', [$invoiceNo, $id])) throw new InvalidArgumentException($docWord.' number '.$invoiceNo.' is already used. Choose another.');

        $values = [$invoiceNo, $clientId, $invoiceDate, $dueDate, $gstType, decimal_money($invoiceRate), decimal_money($subtotal), decimal_money($discount), decimal_money($tax), decimal_money($total), decimal_money($advance), $status, optional_text('notes', 2000), $bankId];
        db()->beginTransaction();
        if ($record) {
            query('UPDATE invoices SET invoice_no=?,client_id=?,invoice_date=?,due_date=?,gst_type=?,gst_rate=?,subtotal=?,discount=?,tax_amount=?,total=?,advance=?,status=?,notes=?,bank_account_id=? WHERE id=?', array_merge($values, [$id]));
            query('DELETE FROM invoice_items WHERE invoice_id=?', [$id]);
            $invoiceId = $id;
        } else {
            query('INSERT INTO invoices (invoice_no,client_id,invoice_date,due_date,gst_type,gst_rate,subtotal,discount,tax_amount,total,advance,status,notes,bank_account_id,doc_type,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($values, [$docType, $user['id']]));
            $invoiceId = (int) db()->lastInsertId();
        }
        foreach ($items as $i => $item) {
            query('INSERT INTO invoice_items (invoice_id,sort_order,service_name,description,quantity,rate,discount_percent,amount,gst_rate,gst_amount) VALUES (?,?,?,?,?,?,?,?,?,?)', [$invoiceId, $i, $item['service_name'], $item['description'], $item['quantity'], $item['rate'], $item['discount_percent'], $item['amount'], $item['gst_rate'], $item['gst_amount']]);
        }
        // A new quotation is shown to the client in their portal (Payments → Quotations).
        if ($isQuote && !$record && !empty($_POST['notify'])) {
            $clientUser = one('SELECT user_id FROM clients WHERE id=?', [$clientId]);
            if (!empty($clientUser['user_id'])) query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)", [$clientUser['user_id'], $user['id'], 'Quotation '.$invoiceNo.' from Bhavi', 'Total '.rupees_label($total / 100).'. Open Payments → Quotations to view and accept it.']);
        }
        db()->commit();
        flash($docWord.' '.$invoiceNo.' saved.');
        redirect('invoices.php?saved='.$invoiceId.($isQuote ? '&type=quotation' : ''));
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
        $mode = isset($_GET['add']) || $id ? 'form' : 'list';
    }
}

if ($mode === 'form') {
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $today = date('Y-m-d');
    $defaults = ['invoice_no' => invoice_next_no($today, $docType), 'invoice_date' => $today, 'due_date' => date('Y-m-d', strtotime($isQuote ? '+15 days' : '+7 days')), 'gst_type' => 'cgst_sgst', 'advance' => '', 'status' => $isQuote ? 'pending' : 'unpaid', 'notes' => '', 'client_id' => (string) (int) ($_GET['client'] ?? 0), 'bank_account_id' => (string) ($bankAccounts[0]['id'] ?? '')];
    $v = static function (string $k) use ($isPost, $record, $defaults): string {
        if ($isPost) return (string) ($_POST[$k] ?? '');
        if ($record) return (string) ($record[$k] ?? '');
        return (string) ($defaults[$k] ?? '');
    };
    if ($isPost && is_array($_POST['items'] ?? null)) $items = array_values(array_filter($_POST['items'], 'is_array'));
    elseif ($record) $items = rows('SELECT service_name,description,quantity,rate,discount_percent,gst_rate FROM invoice_items WHERE invoice_id=? ORDER BY sort_order,id', [$id]);
    else $items = [];
    if (!$items) $items = [['service_name' => '', 'description' => '', 'quantity' => '1', 'rate' => '', 'discount_percent' => '0', 'gst_rate' => '18']];
    $trim0 = static fn($n, string $fallback = '0'): string => rtrim(rtrim((string) $n, '0'), '.') ?: $fallback;
    $gstTypeValue = $v('gst_type') === 'none' && !$isPost && $record ? 'cgst_sgst' : $v('gst_type');
    $recordBank = $record ? (string) ($record['bank_account_id'] ?? '') : '';
    if ($record && $recordBank === '' && !$isPost) $recordBank = (string) ($bankAccounts[0]['id'] ?? '');
    $fromQuote = $record && $record['from_quotation_id'] ? one('SELECT invoice_no FROM invoices WHERE id=?', [$record['from_quotation_id']]) : null;
    $convertedTo = $record && $isQuote && $record['converted_invoice_id'] ? one('SELECT id,invoice_no FROM invoices WHERE id=? AND deleted_at IS NULL', [$record['converted_invoice_id']]) : null;
    page_start($record ? 'Edit '.strtolower($docWord) : 'New '.strtolower($docWord), 'invoices.php');
    error_message($error);
    ?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / <a href="invoices.php<?= $isQuote ? '?type=quotation' : '' ?>"><?= $isQuote ? 'Quotations' : 'Invoices' ?></a> / <?= $record ? 'Edit' : 'New' ?></p>
        <h1 class="ot-title"><?= $record ? h($docWord.' '.$record['invoice_no']) : 'New '.strtolower($docWord) ?></h1>
        <p class="ot-sub"><?= $isQuote ? 'Prices for the client to approve. No bank details are printed on a quotation. When the client agrees, convert it into an invoice.' : 'Choose the client, add the services with their GST, and the bank account to print. Totals are worked out for you.' ?></p>
    </header>
    <?php if ($fromQuote): ?><p class="cm-banner is-confirmed">Made from quotation <b><?= h($fromQuote['invoice_no']) ?></b>. Edit anything below and save.</p><?php endif; ?>
    <?php if ($convertedTo): ?><p class="cm-banner is-confirmed">This quotation is converted to invoice <a href="invoices.php?id=<?= (int) $convertedTo['id'] ?>"><?= h($convertedTo['invoice_no']) ?></a>.</p><?php endif; ?>
    <form method="post" class="vd-form" autocomplete="off" data-invoice-form><?= csrf_field() ?><input type="hidden" name="action" value="save_invoice">
        <section class="ot-card"><h2>1 · <?= $docWord ?> details</h2>
            <div class="ot-form vd-grid">
                <label><span>Client *</span><select name="client_id" required data-client><option value="">Select client</option><?php foreach ($clientList as $c): ?><option value="<?= (int) $c['id'] ?>" data-package="<?= h(client_package_name($c)) ?>" data-amount="<?= h(number_format($c['remaining'], 2, '.', '')) ?>" data-money="<?= h('Package '.rupees_label($c['pkg_amount']).' · Paid '.rupees_label($c['paid_amount']).' · Remaining '.rupees_label($c['remaining'])) ?>" data-gst="<?= h($c['gst_number']) ?>" <?= $v('client_id') === (string) $c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select><small data-client-gst></small></label>
                <label><span><?= $docWord ?> number <small>(auto)</small></span><input name="invoice_no" maxlength="40" value="<?= h($v('invoice_no')) ?>" placeholder="Leave empty for next number"></label>
                <label><span>Status</span><select name="status" data-status><?php foreach ($isQuote ? QUOTATION_STATUSES : INVOICE_STATUSES as $k => $l): if ($isQuote && $k === 'converted' && $v('status') !== 'converted') continue; ?><option value="<?= $k ?>" <?= $v('status') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label><span><?= $docWord ?> date *</span><input type="date" name="invoice_date" required value="<?= h($v('invoice_date')) ?>"></label>
                <label><span><?= $isQuote ? 'Valid until' : 'Due date' ?></span><input type="date" name="due_date" value="<?= h($v('due_date')) ?>"></label>
                <label><span>GST type</span><select name="gst_type" data-gst-type><?php foreach (INVOICE_GST_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $gstTypeValue === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select><small>Type the GST % for each service below (any value, 0 = no GST).</small></label>
            </div>
        </section>
        <section class="ot-card"><h2>2 · Services / items</h2>
            <div class="inv-items inv-items-gst" data-items>
                <?php foreach ($items as $i => $it): $rowGst = $trim0($it['gst_rate'] ?? '0'); ?>
                    <div class="ot-form inv-row">
                        <label><span>Service *</span><input name="items[<?= $i ?>][service_name]" maxlength="150" value="<?= h($it['service_name'] ?? '') ?>" placeholder="e.g. SEO" list="inv-services"></label>
                        <label class="inv-desc"><span>Description</span><input name="items[<?= $i ?>][description]" maxlength="500" value="<?= h($it['description'] ?? '') ?>" placeholder="e.g. Google Ads and YouTube"></label>
                        <label><span>Qty</span><input type="number" name="items[<?= $i ?>][quantity]" min="0.01" step="0.01" value="<?= h($trim0($it['quantity'] ?? '1', '1')) ?>" data-qty></label>
                        <label><span>Price/Unit (₹)</span><input type="number" name="items[<?= $i ?>][rate]" min="0" max="9999999999.99" step="0.01" value="<?= h($it['rate'] ?? '') ?>" data-rate></label>
                        <label><span>Disc %</span><input type="number" name="items[<?= $i ?>][discount_percent]" min="0" max="100" step="0.01" value="<?= h($trim0($it['discount_percent'] ?? '0')) ?>" data-disc></label>
                        <label><span>GST %</span><input type="number" name="items[<?= $i ?>][gst_rate]" min="0" max="100" step="0.01" value="<?= h($rowGst) ?>" list="inv-gst-rates" placeholder="0" title="Type any GST %, or pick 0, 5, 12, 18, 28" data-gst></label>
                        <label><span>Total</span><output data-amount>₹0</output><small class="inv-row-gst" data-gst-note></small></label>
                        <button type="button" class="icon-button danger inv-remove" data-remove title="Remove item" aria-label="Remove item"><?= icon('delete') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <datalist id="inv-services"><?php foreach ($serviceList as $s): ?><option value="<?= h($s['name']) ?>"><?= h($s['description']) ?></option><?php endforeach; ?></datalist>
            <script type="application/json" id="inv-service-data"><?= json_encode(array_map(static fn($s) => ['name' => $s['name'], 'description' => $s['description'], 'rate' => $s['rate'] === null ? '' : (string) $s['rate'], 'gst' => $trim0($s['gst_rate'])], $serviceList), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
            <p class="ot-help"><?= $serviceList ? 'Pick a saved service to fill its price and GST %, or type any other service.' : 'No saved services yet.' ?> <a href="invoice-services.php">Add or change services →</a></p>
            <datalist id="inv-gst-rates"><?php foreach (INVOICE_GST_RATES as $g): ?><option value="<?= $g ?>"><?php endforeach; ?></datalist>
            <button type="button" class="vd-add" data-add-item>+ Add another item</button>
        </section>
        <section class="ot-card"><h2>3 · <?= $isQuote ? 'Total' : 'Payment &amp; total' ?></h2>
            <?php if (!$isQuote): ?>
            <div class="ot-form vd-grid">
                <label><span>Bank account to print</span><select name="bank_account_id"><?php if (!$bankAccounts): ?><option value="">From payment details file</option><?php endif; foreach ($bankAccounts as $b): ?><option value="<?= (int) $b['id'] ?>" <?= ($isPost ? (string) ($_POST['bank_account_id'] ?? '') : ($record ? $recordBank : $defaults['bank_account_id'])) === (string) $b['id'] ? 'selected' : '' ?>><?= h(invoice_bank_label($b)) ?><?= $b['is_default'] ? ' (default)' : '' ?></option><?php endforeach; ?></select><small><a href="bank-accounts.php">Add or change bank accounts →</a></small></label>
                <label><span>Advance received (₹)</span><input type="number" name="advance" min="0" step="0.01" value="<?= h($v('advance') === '0.00' ? '' : $v('advance')) ?>" data-advance placeholder="0"></label>
            </div>
            <?php endif; ?>
            <dl class="inv-totals">
                <dt>Subtotal</dt><dd data-t-sub>₹0</dd>
                <dt data-t-tax-label>GST</dt><dd data-t-tax>₹0</dd>
                <dt class="inv-grand">Grand Total</dt><dd class="inv-grand" data-t-total>₹0</dd>
                <?php if (!$isQuote): ?>
                <dt>Advance</dt><dd data-t-adv>₹0</dd>
                <dt class="inv-grand">Balance</dt><dd class="inv-grand" data-t-bal>₹0</dd>
                <?php endif; ?>
            </dl>
        </section>
        <section class="ot-card"><h2>4 · Notes <small class="ot-help">(printed on the <?= strtolower($docWord) ?>)</small></h2><div class="ot-form"><label><span>Notes / terms</span><textarea name="notes" maxlength="2000" rows="3" placeholder="<?= $isQuote ? 'e.g. Prices valid for 15 days. 50% advance to start the work.' : 'e.g. Payment due within 7 days. Thank you for your business.' ?>"><?= h($v('notes')) ?></textarea></label>
            <?php if ($isQuote && !$record): ?><label class="zm-check"><input type="checkbox" name="notify" value="1" checked> Show it to the client in their portal so they can accept it</label><?php endif; ?>
        </div></section>
        <div class="ot-actions"><button class="ot-gold" type="submit"><?= $record ? 'Save '.strtolower($docWord) : 'Create '.strtolower($docWord) ?></button><a href="invoices.php<?= $isQuote ? '?type=quotation' : '' ?>">Cancel</a></div>
    </form>
</div>
<script>
(() => {
    const form = document.querySelector('[data-invoice-form]'), list = form.querySelector('[data-items]');
    const inr = n => '₹' + n.toLocaleString('en-IN', {minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2});
    const num = el => Math.max(0, parseFloat(el && el.value) || 0);
    const gstType = form.querySelector('[data-gst-type]'), status = form.querySelector('[data-status]'), advance = form.querySelector('[data-advance]');
    function calc() {
        let sub = 0, tax = 0; const noGst = gstType.value === 'none', rates = new Set();
        list.querySelectorAll('.inv-row').forEach(row => {
            const gross = Math.round(num(row.querySelector('[data-qty]')) * num(row.querySelector('[data-rate]')) * 100);
            const amt = Math.round(gross * (100 - Math.min(100, num(row.querySelector('[data-disc]')))) / 100) / 100;
            const sel = row.querySelector('[data-gst]'); sel.disabled = noGst;
            const rate = noGst ? 0 : num(sel), g = Math.round(amt * rate) / 100;
            row.querySelector('[data-amount]').textContent = inr(amt);
            row.querySelector('[data-gst-note]').textContent = rate ? '+ GST ' + rate + '% ' + inr(g) : 'GST 0%';
            sub += amt; tax += g; if (amt) rates.add(rate);
        });
        sub = Math.round(sub * 100) / 100; tax = Math.round(tax * 100) / 100;
        const total = Math.round((sub + tax) * 100) / 100;
        const r = [...rates].filter(x => x > 0);
        form.querySelector('[data-t-sub]').textContent = inr(sub);
        form.querySelector('[data-t-tax-label]').textContent = noGst || !r.length ? 'GST 0%' : (r.length === 1 ? 'GST ' + r[0] + '%' : 'GST (' + r.map(x => x + '%').join(', ') + ')');
        form.querySelector('[data-t-tax]').textContent = inr(tax);
        form.querySelector('[data-t-total]').textContent = inr(total);
        if (advance) {
            const adv = status.value === 'paid' ? total : Math.min(total, num(advance));
            form.querySelector('[data-t-adv]').textContent = inr(adv);
            form.querySelector('[data-t-bal]').textContent = inr(Math.round((total - adv) * 100) / 100);
        }
    }
    const blank = row => { row.querySelectorAll('input').forEach(inp => { inp.value = inp.matches('[data-qty]') ? '1' : (inp.matches('[data-disc]') ? '0' : ''); }); const g = row.querySelector('[data-gst]'); g.value = '18'; };
    function renumber() { list.querySelectorAll('.inv-row').forEach((row, i) => row.querySelectorAll('input,select').forEach(inp => inp.name = inp.name.replace(/items\[\d+\]/, 'items[' + i + ']'))); }
    form.querySelector('[data-add-item]').addEventListener('click', () => {
        const row = list.lastElementChild.cloneNode(true); delete row.dataset.auto;
        blank(row); list.appendChild(row); renumber(); calc(); row.querySelector('input').focus();
    });
    list.addEventListener('click', e => {
        const btn = e.target.closest('[data-remove]'); if (!btn) return;
        const rows = list.querySelectorAll('.inv-row');
        if (rows.length === 1) blank(rows[0]); else btn.closest('.inv-row').remove();
        renumber(); calc();
    });
    list.addEventListener('input', e => { const row = e.target.closest('.inv-row'); if (row) row.dataset.auto = '0'; }); // typed by hand: keep it
    // A saved service (invoice-services.php) fills its description, price and GST % — only into empty fields.
    const services = JSON.parse(document.getElementById('inv-service-data').textContent || '[]');
    list.addEventListener('change', e => {
        if (!e.target.matches('[name$="[service_name]"]')) return;
        const s = services.find(x => x.name.toLowerCase() === e.target.value.trim().toLowerCase()); if (!s) return;
        const row = e.target.closest('.inv-row'), desc = row.querySelector('[name$="[description]"]'), rate = row.querySelector('[data-rate]'), gst = row.querySelector('[data-gst]');
        e.target.value = s.name;
        if (!desc.value && s.description) desc.value = s.description;
        if (!rate.value && s.rate !== '') rate.value = s.rate;
        if (s.gst !== '') gst.value = s.gst;
        calc();
    });
    const client = form.querySelector('[data-client]'), gstNote = form.querySelector('[data-client-gst]');
    function showGst() { const o = client.selectedOptions[0]; gstNote.textContent = o && o.value ? [o.dataset.money, o.dataset.gst ? 'GSTIN: ' + o.dataset.gst : ''].filter(Boolean).join(' · ') : ''; }
    client.addEventListener('change', () => {
        showGst();
        const o = client.selectedOptions[0], first = list.querySelector('.inv-row');
        // Fill the first item with the client's remaining amount (package − paid) when it is empty
        // or was filled automatically for another client; never overwrite what was typed by hand.
        if (!o || !o.value || !first) return;
        const svc = first.querySelector('[name$="[service_name]"]'), desc = first.querySelector('[name$="[description]"]'), rate = first.querySelector('[data-rate]');
        if (first.dataset.auto !== '1' && (svc.value || rate.value)) return;
        svc.value = o.dataset.package || 'Package balance';
        desc.value = parseFloat(o.dataset.amount) > 0 ? 'Remaining amount (' + o.dataset.money.replace(/ · Remaining.*/, '') + ')' : '';
        rate.value = parseFloat(o.dataset.amount) > 0 ? o.dataset.amount : '';
        first.dataset.auto = '1';
        calc();
    });
    form.addEventListener('input', calc); form.addEventListener('change', calc);
    showGst(); calc();
})();
</script>
    <?php
    page_end();
    exit;
}

// List: invoices or quotations
$filterClient = (int) ($_GET['client'] ?? 0);
$filterStatus = (string) ($_GET['status'] ?? '');
$statusSet = $isQuote ? QUOTATION_STATUSES : INVOICE_STATUSES;
$where = ['i.deleted_at IS NULL', 'i.doc_type=?']; $params = [$docType];
if ($filterClient) { $where[] = 'i.client_id=?'; $params[] = $filterClient; }
if (!isset($statusSet[$filterStatus])) $filterStatus = '';
// Search: number, client name, contact, phone, notes, services or the total amount.
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
if ($search !== '') {
    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    $amount = preg_replace('/[^0-9.]/', '', $search);
    $where[] = '(i.invoice_no LIKE ? OR c.client_name LIKE ? OR c.contact_person LIKE ? OR c.phone LIKE ? OR i.notes LIKE ? OR EXISTS (SELECT 1 FROM invoice_items it WHERE it.invoice_id=i.id AND (it.service_name LIKE ? OR it.description LIKE ?))'.($amount !== '' && is_numeric($amount) ? ' OR i.total=?' : '').')';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
    if ($amount !== '' && is_numeric($amount)) $params[] = $amount;
}
$allInvoices = rows('SELECT i.*,c.client_name,inv.invoice_no AS converted_no FROM invoices i JOIN clients c ON c.id=i.client_id LEFT JOIN invoices inv ON inv.id=i.converted_invoice_id AND inv.deleted_at IS NULL WHERE '.implode(' AND ', $where).' ORDER BY i.invoice_date DESC,i.id DESC', $params);
// Amount received / still due per invoice (Paid = full total received).
$received = static fn(array $inv): float => $inv['status'] === 'paid' ? (float) $inv['total'] : min((float) $inv['total'], (float) $inv['advance']);
$sum = ['all' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'pending' => 0.0, 'accepted' => 0.0, 'converted' => 0.0];
$counts = ['' => count($allInvoices)] + array_fill_keys(array_keys($statusSet), 0);
foreach ($allInvoices as $inv) {
    $counts[$inv['status']] = ($counts[$inv['status']] ?? 0) + 1;
    if ($isQuote) { if (isset($sum[$inv['status']])) $sum[$inv['status']] += (float) $inv['total']; $sum['all'] += (float) $inv['total']; continue; }
    if ($inv['status'] === 'cancelled') continue;
    $got = $received($inv);
    $sum['all'] += (float) $inv['total']; $sum['paid'] += $got; $sum['unpaid'] += (float) $inv['total'] - $got;
}
$invoices = $filterStatus === '' ? $allInvoices : array_values(array_filter($allInvoices, static fn($inv) => $inv['status'] === $filterStatus));
$tabUrl = static function (string $status) use ($filterClient, $search, $isQuote): string {
    $q = array_filter(['type' => $isQuote ? 'quotation' : null, 'client' => $filterClient ?: null, 'status' => $status ?: null, 'q' => $search !== '' ? $search : null]);
    return 'invoices.php'.($q ? '?'.http_build_query($q) : '');
};
$savedId = (int) ($_GET['saved'] ?? 0);
$quoteCount = count_value("SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL AND doc_type='quotation' AND status IN ('pending','accepted')");
page_start($isQuote ? 'Quotations' : 'Invoices', 'invoices.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head vd-head">
        <div><p class="ot-crumb">Dashboard / <?= $isQuote ? 'Quotations' : 'Invoices' ?></p><h1 class="ot-title"><?= $isQuote ? 'Quotations' : 'Invoices' ?></h1><p class="ot-sub"><?= $isQuote ? 'Send prices to a client first. When they accept, convert the quotation into an invoice.' : 'Create invoices for clients, print or save them as PDF, and track which are paid.' ?></p></div>
        <div class="inv-head-actions">
            <a class="mm-btn" href="invoice-services.php"><?= icon('list') ?> Services</a>
            <a class="mm-btn" href="bank-accounts.php"><?= icon('card') ?> Bank accounts</a>
            <a class="ot-gold vd-new" href="invoices.php?add=1<?= $isQuote ? '&amp;type=quotation' : '' ?>">+ New <?= $isQuote ? 'quotation' : 'invoice' ?></a>
        </div>
    </header>
    <nav class="inv-doc-tabs" aria-label="Invoices or quotations">
        <a href="invoices.php" class="<?= !$isQuote ? 'is-on' : '' ?>" <?= !$isQuote ? 'aria-current="page"' : '' ?>><?= icon('document') ?> Invoices</a>
        <a href="invoices.php?type=quotation" class="<?= $isQuote ? 'is-on' : '' ?>" <?= $isQuote ? 'aria-current="page"' : '' ?>><?= icon('list') ?> Quotations<?php if ($quoteCount): ?> <em><?= $quoteCount ?></em><?php endif; ?></a>
    </nav>
    <?php if ($savedId): ?><div class="alert success" role="status"><a href="invoice-print.php?id=<?= $savedId ?>" target="_blank" rel="noopener"><strong>Open / print <?= $isQuote ? 'quotation' : 'invoice' ?> →</strong></a></div><?php endif; ?>
    <div class="vd-money">
        <?php if ($isQuote): ?>
        <a class="inv-card<?= $filterStatus === '' ? ' is-on' : '' ?>" href="<?= h($tabUrl('')) ?>"><span>All quotations</span><strong><?= h(rupees_label($sum['all'])) ?></strong></a>
        <a class="inv-card<?= $filterStatus === 'accepted' ? ' is-on' : '' ?>" href="<?= h($tabUrl('accepted')) ?>"><span>Accepted (to convert)</span><strong><?= h(rupees_label($sum['accepted'])) ?></strong></a>
        <a class="inv-card is-due<?= $filterStatus === 'pending' ? ' is-on' : '' ?>" href="<?= h($tabUrl('pending')) ?>"><span>Waiting for client</span><strong><?= h(rupees_label($sum['pending'])) ?></strong></a>
        <?php else: ?>
        <a class="inv-card<?= $filterStatus === '' ? ' is-on' : '' ?>" href="<?= h($tabUrl('')) ?>"><span>Total invoiced</span><strong><?= h(rupees_label($sum['all'])) ?></strong></a>
        <a class="inv-card<?= $filterStatus === 'paid' ? ' is-on' : '' ?>" href="<?= h($tabUrl('paid')) ?>"><span>Paid (received)</span><strong><?= h(rupees_label($sum['paid'])) ?></strong></a>
        <a class="inv-card is-due<?= $filterStatus === 'unpaid' ? ' is-on' : '' ?>" href="<?= h($tabUrl('unpaid')) ?>"><span>Unpaid (balance)</span><strong><?= h(rupees_label($sum['unpaid'])) ?></strong></a>
        <?php endif; ?>
    </div>
    <section class="ot-card">
        <div class="inv-filters">
            <nav class="inv-tabs" aria-label="Filter by status">
                <?php foreach (['' => 'All'] + $statusSet as $k => $l): ?>
                    <a href="<?= h($tabUrl($k)) ?>" class="inv-tab is-<?= $k ?: 'all' ?><?= $filterStatus === $k ? ' is-on' : '' ?>" <?= $filterStatus === $k ? 'aria-current="page"' : '' ?>><?= h($l) ?> <em><?= (int) ($counts[$k] ?? 0) ?></em></a>
                <?php endforeach; ?>
            </nav>
            <form method="get" class="inv-client-filter">
                <?php if ($isQuote): ?><input type="hidden" name="type" value="quotation"><?php endif; ?>
                <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?= h($filterStatus) ?>"><?php endif; ?>
                <select name="client" aria-label="Filter by client" onchange="this.form.submit()"><option value="">All clients</option><?php foreach ($clientList as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $filterClient === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select>
                <span class="inv-search"><input type="search" name="q" value="<?= h($search) ?>" maxlength="100" placeholder="Search number, client, service, amount…" aria-label="Search"><button type="submit" class="button secondary">Search</button></span>
                <?php if ($filterClient || $filterStatus || $search !== ''): ?><a href="invoices.php<?= $isQuote ? '?type=quotation' : '' ?>">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if ($isQuote): ?>
        <div class="table-scroll"><table><thead><tr><th>Quotation no.</th><th>Date</th><th>Client</th><th>Total</th><th>Valid until</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php if (!$invoices) empty_row(7, $search !== '' ? 'No quotations match “'.$search.'”.' : 'No quotations yet. Use “New quotation”.'); foreach ($invoices as $inv): $expired = $inv['status'] === 'pending' && $inv['due_date'] && $inv['due_date'] < date('Y-m-d'); ?>
                <tr>
                    <td><strong><?= h($inv['invoice_no']) ?></strong></td>
                    <td><?= h(date('d M Y', strtotime($inv['invoice_date']))) ?></td>
                    <td><?= h($inv['client_name']) ?></td>
                    <td><?= h(rupees_label($inv['total'])) ?></td>
                    <td><?= $inv['due_date'] ? h(date('d M Y', strtotime($inv['due_date']))) : '—' ?><?php if ($expired): ?> <small class="inv-overdue">Expired</small><?php endif; ?></td>
                    <td><?= quotation_status_badge($inv['status']) ?><?php if ($inv['status'] === 'converted' && $inv['converted_no']): ?><br><small><a href="invoices.php?id=<?= (int) $inv['converted_invoice_id'] ?>">→ <?= h($inv['converted_no']) ?></a></small><?php endif; ?></td>
                    <td><div class="actions">
                        <a class="button secondary" href="invoice-print.php?id=<?= (int) $inv['id'] ?>" target="_blank" rel="noopener">View / Print</a>
                        <?php if ($inv['status'] !== 'converted'): ?>
                        <form method="post" class="inline-form" data-confirm="Convert quotation <?= h($inv['invoice_no']) ?> into an invoice? You can edit the invoice after this."><?= csrf_field() ?><input type="hidden" name="action" value="convert_quotation"><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>"><button type="submit" class="button inv-convert" title="Convert to invoice">Convert to invoice</button></form>
                        <?php if ($inv['status'] === 'pending'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="set_quote_status"><input type="hidden" name="status" value="accepted"><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>"><button type="submit" class="icon-button" title="Mark accepted by client" aria-label="Mark accepted"><?= icon('check') ?></button></form><?php endif; ?>
                        <?php endif; ?>
                        <a class="icon-button" href="invoices.php?id=<?= (int) $inv['id'] ?>" title="Edit quotation" aria-label="Edit quotation"><?= icon('edit') ?></a>
                        <?php delete_button((int) $inv['id'], 'delete_invoice', 'Delete quotation '.$inv['invoice_no'].'?'); ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?>
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
        <?php endif; ?>
    </section>
</div>
<?php page_end(); ?>
