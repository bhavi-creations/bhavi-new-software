<?php
require_once __DIR__ . '/layout.php';
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
?><div class="toolbar"><p class="muted">Client details are shared with your team.</p><?php if (is_staff()): ?><a class="button" href="admin-add-client.php">+ Add client</a><?php endif; ?></div><section class="panel"><div class="table-scroll"><table><thead><tr><th>Logo</th><th>Client name</th><th>Website</th><th>Actions</th></tr></thead><tbody><?php if (!$clients) empty_row(4); foreach ($clients as $client): ?><tr><td><?php if ($client['logo_path']): ?><img class="client-logo" src="<?= h($client['logo_path']) ?>" alt="<?= h($client['client_name']) ?> logo"><?php else: ?>—<?php endif; ?></td><td><?= h($client['client_name']) ?></td><td><?php if ($client['website_url']): ?><a href="<?= h($client['website_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($client['website_url']) ?></a><?php else: ?>—<?php endif; ?></td><td><div class="actions"><?php detail_button('client-'.$client['id']); if (is_staff()): ?><a class="icon-button" href="admin-add-client.php?id=<?= $client['id'] ?>" title="Edit client" aria-label="Edit client"><?= icon('edit') ?></a><?php delete_button((int)$client['id'],'delete_client','Delete this client?'); endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php foreach ($clients as $client) detail_dialog('client-'.$client['id'],$client['client_name'],['Client name'=>$client['client_name'],'Website'=>$client['website_url'],'Created'=>$client['created_at'],'Updated'=>$client['updated_at']]); page_end(); ?>
