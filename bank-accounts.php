<?php
// Bank accounts printed on invoices. The default one is chosen on new invoices; each invoice can pick another.
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/invoice.php';
$user = require_roles(['admin']);
$error = null;
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId ? one('SELECT * FROM bank_accounts WHERE id=? AND deleted_at IS NULL', [$editId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $fields = [
                'label' => optional_text('label', 100),
                'bank_name' => text_input('bank_name', 150),
                'account_name' => text_input('account_name', 150),
                'account_no' => preg_replace('/\s+/', '', text_input('account_no', 40)),
                'ifsc' => strtoupper(optional_text('ifsc', 20)),
                'branch' => optional_text('branch', 150),
                'upi_id' => optional_text('upi_id', 100),
            ];
            if (!preg_match('/^[0-9A-Za-z-]{4,40}$/D', $fields['account_no'])) throw new InvalidArgumentException('Enter a valid account number.');
            if ($fields['ifsc'] !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/D', $fields['ifsc'])) throw new InvalidArgumentException('Enter a valid IFSC code, e.g. HDFC0000426.');
            db()->beginTransaction();
            $makeDefault = !empty($_POST['is_default']) || !count_value('SELECT COUNT(*) FROM bank_accounts WHERE deleted_at IS NULL AND id<>?', [$id]);
            if ($makeDefault) query('UPDATE bank_accounts SET is_default=0');
            if ($id) {
                if (!one('SELECT id FROM bank_accounts WHERE id=? AND deleted_at IS NULL', [$id])) throw new InvalidArgumentException('Bank account not found.');
                query('UPDATE bank_accounts SET label=?,bank_name=?,account_name=?,account_no=?,ifsc=?,branch=?,upi_id=?'.($makeDefault ? ',is_default=1' : '').' WHERE id=?', array_merge(array_values($fields), [$id]));
            } else {
                query('INSERT INTO bank_accounts (label,bank_name,account_name,account_no,ifsc,branch,upi_id,is_default) VALUES (?,?,?,?,?,?,?,?)', array_merge(array_values($fields), [$makeDefault ? 1 : 0]));
            }
            db()->commit();
            flash('Bank account saved.');
            redirect('bank-accounts.php');
        }
        if ($action === 'make_default') {
            $id = positive_id($_POST['id'] ?? null);
            db()->beginTransaction();
            query('UPDATE bank_accounts SET is_default=0');
            query('UPDATE bank_accounts SET is_default=1 WHERE id=? AND deleted_at IS NULL', [$id]);
            db()->commit();
            flash('Default bank account changed. New invoices will use it.');
            redirect('bank-accounts.php');
        }
        if ($action === 'delete') {
            $id = positive_id($_POST['id'] ?? null);
            $row = one('SELECT is_default FROM bank_accounts WHERE id=? AND deleted_at IS NULL', [$id]);
            if (!$row) throw new InvalidArgumentException('Bank account not found.');
            db()->beginTransaction();
            query('UPDATE bank_accounts SET deleted_at=NOW(),is_default=0 WHERE id=?', [$id]);
            // Keep one default.
            if ((int) $row['is_default'] === 1) query('UPDATE bank_accounts SET is_default=1 WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
            db()->commit();
            flash('Bank account removed. Invoices that used it now print the default account.');
            redirect('bank-accounts.php');
        }
        throw new InvalidArgumentException('Invalid request.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
    }
}

$accounts = invoice_bank_accounts();
$usage = [];
foreach (rows("SELECT bank_account_id,COUNT(*) AS n FROM invoices WHERE deleted_at IS NULL AND doc_type='invoice' AND bank_account_id IS NOT NULL GROUP BY bank_account_id") as $u) $usage[(int) $u['bank_account_id']] = (int) $u['n'];
$f = static fn(string $k): string => (string) ($_POST[$k] ?? ($editing[$k] ?? ''));
page_start('Bank accounts', 'invoices.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head vd-head">
        <div><p class="ot-crumb">Dashboard / <a href="invoices.php">Invoices</a> / Bank accounts</p><h1 class="ot-title">Bank accounts</h1><p class="ot-sub">The account printed under “Payment details” on invoices. Pick a different one on any invoice. Quotations never show bank details.</p></div>
        <a class="cm-back" href="invoices.php">← Invoices</a>
    </header>
    <section class="ot-card">
        <div class="ot-card-head"><h2>Saved accounts</h2><span><?= count($accounts) ?></span></div>
        <?php if (!$accounts): ?><p class="ot-help">No bank accounts yet. Add one below.</p><?php endif; ?>
        <div class="ba-grid">
            <?php foreach ($accounts as $a): ?>
            <article class="ba-card<?= $a['is_default'] ? ' is-default' : '' ?>">
                <h3><span><?= h($a['label'] !== '' ? $a['label'] : $a['bank_name']) ?></span><?php if ($a['is_default']): ?><span class="ba-default">Default</span><?php endif; ?></h3>
                <dl>
                    <dt>Bank</dt><dd><?= h($a['bank_name']) ?></dd>
                    <dt>Account name</dt><dd><?= h($a['account_name']) ?></dd>
                    <dt>Account no.</dt><dd><?= h($a['account_no']) ?></dd>
                    <?php if ($a['ifsc'] !== ''): ?><dt>IFSC</dt><dd><?= h($a['ifsc']) ?></dd><?php endif; ?>
                    <?php if ($a['branch'] !== ''): ?><dt>Branch</dt><dd><?= h($a['branch']) ?></dd><?php endif; ?>
                    <?php if ($a['upi_id'] !== ''): ?><dt>UPI ID</dt><dd><?= h($a['upi_id']) ?></dd><?php endif; ?>
                </dl>
                <small class="ot-help">Used on <?= (int) ($usage[(int) $a['id']] ?? 0) ?> invoice<?= ($usage[(int) $a['id']] ?? 0) === 1 ? '' : 's' ?></small>
                <div class="actions">
                    <a class="button secondary" href="bank-accounts.php?edit=<?= (int) $a['id'] ?>#ba-form">Edit</a>
                    <?php if (!$a['is_default']): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="make_default"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button type="submit" class="button secondary">Make default</button></form><?php endif; ?>
                    <?php delete_button((int) $a['id'], 'delete', 'Remove this bank account?'); ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="ot-card" id="ba-form">
        <h2><?= $editing ? 'Edit bank account' : 'Add bank account' ?></h2>
        <form method="post" class="ot-form vd-grid" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <label><span>Name for this account <small>(optional)</small></span><input name="label" maxlength="100" value="<?= h($f('label')) ?>" placeholder="e.g. HDFC current account"></label>
            <label><span>Bank name *</span><input name="bank_name" maxlength="150" required value="<?= h($f('bank_name')) ?>" placeholder="e.g. HDFC Bank, Kakinada"></label>
            <label><span>Account holder name *</span><input name="account_name" maxlength="150" required value="<?= h($f('account_name')) ?>" placeholder="e.g. Bhavi Creations Private Limited"></label>
            <label><span>Account number *</span><input name="account_no" maxlength="40" required value="<?= h($f('account_no')) ?>" inputmode="numeric"></label>
            <label><span>IFSC</span><input name="ifsc" maxlength="20" value="<?= h($f('ifsc')) ?>" placeholder="HDFC0000426"></label>
            <label><span>Branch</span><input name="branch" maxlength="150" value="<?= h($f('branch')) ?>"></label>
            <label><span>UPI ID <small>(makes the “Scan to pay” QR)</small></span><input name="upi_id" maxlength="100" value="<?= h($f('upi_id')) ?>" placeholder="name@okhdfc"></label>
            <label class="zm-check"><input type="checkbox" name="is_default" value="1" <?= !empty($editing['is_default']) || !empty($_POST['is_default']) || !$accounts ? 'checked' : '' ?>> Use as default for new invoices</label>
            <div class="ot-actions"><button class="ot-gold" type="submit"><?= $editing ? 'Save changes' : 'Add account' ?></button><?php if ($editing): ?><a href="bank-accounts.php">Cancel</a><?php endif; ?></div>
        </form>
    </section>
</div>
<?php page_end(); ?>
