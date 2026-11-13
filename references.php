<?php
// References: save new clients that came through a referral — from one of our clients, or from someone else.
require_once __DIR__ . '/includes/layout.php';
$user = require_roles(['admin']);
$error = null;
$clientList = rows('SELECT id,client_name FROM clients WHERE deleted_at IS NULL ORDER BY client_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete_reference') {
            query('DELETE FROM client_references WHERE id=?', [positive_id($_POST['id'] ?? null)]);
            flash('Reference deleted.');
            redirect('references.php');
        }
        if ($action !== 'save_reference') throw new InvalidArgumentException('Invalid request.');
        $referredBy = (string) ($_POST['referred_by'] ?? '');
        $referrerClient = null; $referrerName = '';
        if ($referredBy === 'other') {
            $referrerName = trim((string) ($_POST['referrer_name'] ?? ''));
            if ($referrerName === '' || mb_strlen($referrerName) > 150) throw new InvalidArgumentException('Enter the name of the person who referred.');
        } else {
            $referrerClient = positive_id($referredBy ?: null);
            $row = one('SELECT client_name FROM clients WHERE id=? AND deleted_at IS NULL', [$referrerClient]);
            if (!$row) throw new InvalidArgumentException('Choose who referred (a client or Other).');
            $referrerName = $row['client_name'];
        }
        $clientName = text_input('client_name', 150);
        $phone = trim((string) ($_POST['client_phone'] ?? ''));
        if (!preg_match('/^\+?[0-9 ()-]{6,20}$/', $phone)) throw new InvalidArgumentException('Enter a valid client number (digits only, e.g. 9876543210).');
        query('INSERT INTO client_references (referrer_client_id,referrer_name,client_name,client_phone,note,created_by) VALUES (?,?,?,?,?,?)', [$referrerClient, $referrerName, $clientName, $phone, optional_text('note', 500), $user['id']]);
        flash('Reference saved: ' . $clientName . ' (referred by ' . $referrerName . ').');
        redirect('references.php');
    } catch (Throwable $e) {
        $error = mutation_error($e);
    }
}

$references = rows('SELECT r.*,c.client_name AS referrer_client FROM client_references r LEFT JOIN clients c ON c.id=r.referrer_client_id ORDER BY r.created_at DESC,r.id DESC');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$picked = $isPost ? (string) ($_POST['referred_by'] ?? '') : '';
$old = static fn(string $k): string => $isPost ? (string) ($_POST[$k] ?? '') : '';

page_start('References', 'references.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / References</p>
        <h1 class="ot-title">References</h1>
        <p class="ot-sub">Save new clients who came through a referral — choose the client who referred them, or “Other” for anyone else.</p>
    </header>

    <div class="ot-grid rf-grid">
        <section class="ot-card">
            <h2>Add reference</h2>
            <form method="post" class="ot-form" data-ref-form><?= csrf_field() ?><input type="hidden" name="action" value="save_reference">
                <label><span>Referred by *</span><select name="referred_by" required data-ref-by>
                    <option value="">Select client or Other</option>
                    <?php if ($clientList): ?><optgroup label="Our clients"><?php foreach ($clientList as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $picked === (string) $c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                    <option value="other" <?= $picked === 'other' ? 'selected' : '' ?>>Other (not a client)</option>
                </select></label>
                <label data-ref-other<?= $picked === 'other' ? '' : ' hidden' ?>><span>Name of the person who referred *</span><input name="referrer_name" maxlength="150" value="<?= h($old('referrer_name')) ?>" placeholder="e.g. Suresh (friend)"></label>
                <p class="rf-divider">New client details</p>
                <label><span>Client name *</span><input name="client_name" maxlength="150" required value="<?= h($old('client_name')) ?>" placeholder="e.g. Sri Lakshmi Jewellers"></label>
                <label><span>Client number *</span><input name="client_phone" maxlength="20" required inputmode="tel" value="<?= h($old('client_phone')) ?>" placeholder="e.g. 9876543210"></label>
                <label><span>Note <small>(optional)</small></span><input name="note" maxlength="500" value="<?= h($old('note')) ?>" placeholder="e.g. Needs website + social media"></label>
                <div class="ot-actions"><button class="ot-gold" type="submit">Save reference</button></div>
            </form>
        </section>

        <section class="ot-card">
            <div class="ot-card-head"><h2>Saved references</h2><span><?= count($references) ?></span></div>
            <?php if (!$references): ?><p class="ot-help">No references yet.</p><?php else: ?>
            <div class="table-scroll"><table class="rf-table"><thead><tr><th>Client</th><th>Number</th><th>Referred by</th><th>Date</th><th></th></tr></thead><tbody>
                <?php foreach ($references as $r): $isClient = $r['referrer_client_id'] !== null; ?>
                    <tr>
                        <td><strong><?= h($r['client_name']) ?></strong><?php if ($r['note'] !== ''): ?><br><small class="muted"><?= h($r['note']) ?></small><?php endif; ?></td>
                        <td><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $r['client_phone'])) ?>"><?= h($r['client_phone']) ?></a></td>
                        <td><?= h($r['referrer_client'] ?? $r['referrer_name']) ?><br><span class="rf-tag<?= $isClient ? ' is-client' : '' ?>"><?= $isClient ? 'Client' : 'Other' ?></span></td>
                        <td><?= h(date('d M Y', strtotime($r['created_at']))) ?></td>
                        <td><form method="post" data-confirm="Delete this reference?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_reference"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="icon-button danger" title="Delete" aria-label="Delete"><?= icon('delete') ?></button></form></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </section>
    </div>
</div>
<script>
(() => {
    const form = document.querySelector('[data-ref-form]'), by = form.querySelector('[data-ref-by]'), other = form.querySelector('[data-ref-other]'), input = other.querySelector('input');
    const sync = () => { const on = by.value === 'other'; other.hidden = !on; input.required = on; if (on && document.activeElement === by) input.focus(); };
    by.addEventListener('change', sync); sync();
})();
</script>
<?php page_end(); ?>
