<?php
// Client accepts or declines a quotation from Payments → Quotations. The Managing Director is notified
// and can then convert an accepted quotation into an invoice.
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/invoice.php';
$user = require_roles(['client']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('client-payments.php#quotations');
check_csrf();
$client = one('SELECT id,client_name FROM clients WHERE user_id=? AND deleted_at IS NULL AND is_active=1', [$user['id']]);
if (!$client) fail(403, 'Your client account is unavailable.');
try {
    $response = (string) ($_POST['response'] ?? '');
    if (!in_array($response, ['accepted', 'rejected'], true)) throw new InvalidArgumentException('Choose accept or decline.');
    $quote = one("SELECT id,invoice_no,total FROM invoices WHERE id=? AND client_id=? AND doc_type='quotation' AND deleted_at IS NULL AND status='pending'", [positive_id($_POST['id'] ?? null), $client['id']]);
    if (!$quote) throw new InvalidArgumentException('This quotation is no longer open.');
    db()->beginTransaction();
    query('UPDATE invoices SET status=?,client_response_at=NOW() WHERE id=?', [$response, $quote['id']]);
    query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) SELECT id,?,'general',?,? FROM users WHERE deleted_at IS NULL AND account_status='active' AND role='admin'", [$user['id'], 'Quotation '.$quote['invoice_no'].($response === 'accepted' ? ' accepted by ' : ' declined by ').$client['client_name'], ($response === 'accepted' ? 'Open Quotations and click “Convert to invoice”. ' : '').'Total '.rupees_label($quote['total']).'.']);
    db()->commit();
    flash($response === 'accepted' ? 'Thank you! We will send the invoice soon.' : 'Quotation declined. Our team has been told.');
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash(mutation_error($e), 'error');
}
redirect('client-payments.php#quotations');
