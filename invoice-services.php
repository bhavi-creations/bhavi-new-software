<?php
// Services offered on invoices and quotations. The Managing Director adds them here;
// the invoice form lists only these (no built-in services) and fills the price and GST % from them.
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/invoice.php';
$user = require_roles(['admin']);
$error = null;
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId ? one('SELECT * FROM invoice_services WHERE id=? AND deleted_at IS NULL', [$editId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = text_input('name', 150);
            $description = optional_text('description', 500);
            $rateText = trim((string) ($_POST['rate'] ?? ''));
            if ($rateText !== '' && !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $rateText)) throw new InvalidArgumentException('Enter a valid price.');
            $gstText = trim((string) ($_POST['gst_rate'] ?? '')) ?: '0';
            if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $gstText) || (float) $gstText > 100) throw new InvalidArgumentException('Enter a GST % from 0 to 100.');
            $rate = $rateText === '' ? null : $rateText;
            $dup = one('SELECT id FROM invoice_services WHERE deleted_at IS NULL AND name=? AND id<>?', [$name, $id]);
            if ($dup) throw new InvalidArgumentException('A service with this name already exists.');
            if ($id) {
                if (!one('SELECT id FROM invoice_services WHERE id=? AND deleted_at IS NULL', [$id])) throw new InvalidArgumentException('Service not found.');
                query('UPDATE invoice_services SET name=?,description=?,rate=?,gst_rate=? WHERE id=?', [$name, $description, $rate, $gstText, $id]);
            } else {
                query('INSERT INTO invoice_services (name,description,rate,gst_rate) VALUES (?,?,?,?)', [$name, $description, $rate, $gstText]);
            }
            flash('Service saved. It now shows on new invoices and quotations.');
            redirect('invoice-services.php');
        }
        if ($action === 'delete') {
            query('UPDATE invoice_services SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL', [positive_id($_POST['id'] ?? null)]);
            flash('Service removed. Invoices already made keep their items.');
            redirect('invoice-services.php');
        }
        throw new InvalidArgumentException('Invalid request.');
    } catch (Throwable $e) {
        $error = mutation_error($e);
    }
}

$services = invoice_services();
$f = static fn(string $k, string $fallback = ''): string => (string) ($_POST[$k] ?? ($editing[$k] ?? $fallback));
$trim0 = static fn($n): string => $n === null || $n === '' ? '' : (rtrim(rtrim((string) $n, '0'), '.') ?: '0');
page_start('Invoice services', 'invoices.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head vd-head">
        <div><p class="ot-crumb">Dashboard / <a href="invoices.php">Invoices</a> / Services</p><h1 class="ot-title">Services</h1><p class="ot-sub">Add the services you bill. They appear in the “Service” list on invoices and quotations, and fill the price and GST % for you.</p></div>
        <a class="cm-back" href="invoices.php">← Invoices</a>
    </header>
    <section class="ot-card">
        <div class="ot-card-head"><h2>Saved services</h2><span><?= count($services) ?></span></div>
        <?php if (!$services): ?><p class="ot-help">No services yet. Add one below.</p><?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Service</th><th>Description</th><th>Price/Unit</th><th>GST %</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($services as $s): ?>
                <tr>
                    <td><b><?= h($s['name']) ?></b></td>
                    <td><?= h($s['description']) ?></td>
                    <td><?= $s['rate'] === null ? '—' : h(rupees_label($s['rate'])) ?></td>
                    <td><?= h($trim0($s['gst_rate'])) ?>%</td>
                    <td class="actions"><a class="button secondary" href="invoice-services.php?edit=<?= (int) $s['id'] ?>#sv-form">Edit</a><?php delete_button((int) $s['id'], 'delete', 'Remove this service?'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <section class="ot-card" id="sv-form">
        <h2><?= $editing ? 'Edit service' : 'Add service' ?></h2>
        <form method="post" class="ot-form vd-grid" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <label><span>Service name *</span><input name="name" maxlength="150" required value="<?= h($f('name')) ?>" placeholder="e.g. Social Media Management"></label>
            <label><span>Description <small>(optional)</small></span><input name="description" maxlength="500" value="<?= h($f('description')) ?>" placeholder="e.g. 12 posts + 4 reels per month"></label>
            <label><span>Price/Unit (₹) <small>(optional)</small></span><input type="number" name="rate" min="0" max="9999999999.99" step="0.01" value="<?= h($trim0($f('rate'))) ?>"></label>
            <label><span>GST %</span><input type="number" name="gst_rate" min="0" max="100" step="0.01" value="<?= h($trim0($f('gst_rate', '18'))) ?>" list="sv-gst-rates"></label>
            <datalist id="sv-gst-rates"><?php foreach (INVOICE_GST_RATES as $g): ?><option value="<?= $g ?>"><?php endforeach; ?></datalist>
            <div class="ot-actions"><button class="ot-gold" type="submit"><?= $editing ? 'Save changes' : 'Add service' ?></button><?php if ($editing): ?><a href="invoice-services.php">Cancel</a><?php endif; ?></div>
        </form>
    </section>
</div>
<?php page_end(); ?>
