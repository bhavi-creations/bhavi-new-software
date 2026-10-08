<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__.'/finance.php';
$user = require_roles();
$error = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_roles(['admin','manager']); check_csrf();
    try {
        if (($_POST['action'] ?? '')!=='delete_client') { throw new InvalidArgumentException('Invalid action.'); }
        $id=positive_id($_POST['id'] ?? null);
        query('UPDATE clients SET deleted_at=NOW(),is_active=0 WHERE id=? AND deleted_at IS NULL',[$id]);
        flash('Client deleted.'); redirect('add-client.php');
    } catch (Throwable $e) { $error=mutation_error($e); }
}
$clients=clients(); page_start('Clients','add-client.php'); error_message($error);
?><div class="toolbar"><p class="muted">Client details are shared with your team.</p><?php if (is_staff()): ?><a class="button" href="admin-add-client.php">+ Add client</a><?php endif; ?></div><section class="panel"><div class="table-scroll"><table><thead><tr><th>Logo</th><th>Client name</th><th>Website</th><?php if (is_staff()): ?><th>Remaining</th><th>Package</th><th>Total payment</th><th>Paid</th><?php endif; ?><th>Actions</th></tr></thead><tbody><?php if (!$clients) empty_row(is_staff()?8:4); foreach ($clients as $client): ?><tr><td><?php if ($client['logo_path']): ?><img class="client-logo" src="client-logo.php?id=<?= (int)$client['id'] ?>" alt="<?= h($client['client_name']) ?> logo"><?php else: ?>—<?php endif; ?></td><td><?= h($client['client_name']) ?></td><td><?php if ($client['website_url']): ?><a href="<?= h($client['website_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($client['website_url']) ?></a><?php else: ?>—<?php endif; ?></td><?php if (is_staff()): ?><td><?= h(money_label($client['remaining_amount'])) ?></td><td><?= h($client['package']) ?></td><td><?= h(money_label($client['payment_total'])) ?></td><td><?= h(money_label($client['paid_amount'])) ?></td><?php endif; ?><td><div class="actions"><?php detail_button('client-'.$client['id']); if (is_staff()): ?><a class="icon-button" href="admin-add-client.php?id=<?= $client['id'] ?>" title="Edit client" aria-label="Edit client"><?= icon('edit') ?></a><?php delete_button((int)$client['id'],'delete_client','Delete this client?'); endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php foreach ($clients as $client) {
    $fields=['Client name'=>$client['client_name'],'Phone'=>$client['phone'],'Starting date'=>$client['starting_date'],'Ending date'=>$client['ending_date'],'Website'=>$client['website_url'],'GMB'=>$client['gmb_url'],'Monthly reels'=>(string)$client['monthly_reels'],'Monthly posters'=>(string)$client['monthly_posters'],'Monthly carousels'=>(string)$client['monthly_carousels']];
    $socialLinks=rows('SELECT platform_name,url FROM client_social_links WHERE client_id=? ORDER BY id',[$client['id']]);
    foreach ($socialLinks as $index=>$link) $fields['Social · '.$link['platform_name'].' #'.($index+1)]=$link['url'];
    if (!$socialLinks && $client['social_media_url']) $fields['Social media']=$client['social_media_url'];
    if (is_staff()) {
        $fields['Remaining']=money_label($client['remaining_amount']);
        $fields['Package']=$client['package']; $fields['Total payment']=money_label($client['payment_total']); $fields['Paid']=money_label($client['paid_amount']);
        foreach (rows('SELECT * FROM client_payments WHERE client_id=? ORDER BY payment_date,id',[$client['id']]) as $payment) $fields['Payment #'.$payment['id'].' · '.$payment['payment_date']]=money_label($payment['amount']);
    }
    detail_dialog('client-'.$client['id'],$client['client_name'],$fields);
} page_end(); ?>
