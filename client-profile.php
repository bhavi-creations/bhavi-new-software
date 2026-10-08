<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/finance.php';

require_roles();
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) fail(404,'Client not found.');
$staff=is_staff();
$client=one('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL'.($staff?'':' AND is_active=1'),[$id]);
if (!$client) fail(404,'Client not found.');
$socialLinks=rows('SELECT platform_name,url FROM client_social_links WHERE client_id=? ORDER BY id',[$id]);
if (!$socialLinks && $client['social_media_url']) $socialLinks[]=['platform_name'=>'Social media','url'=>$client['social_media_url']];

function client_profile_link(string $url): void
{
    if ($url==='') { echo '—'; return; }
    if (!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)) { echo h($url); return; }
    ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($url) ?></a><?php
}

page_start('Client profile','add-client.php');
?>
<div class="toolbar"><a class="button secondary" href="add-client.php">Back to clients</a></div>
<div class="client-profile-layout">
    <section class="panel client-profile-card">
        <h2><?= h($client['client_name']) ?></h2>
        <?php if ($client['logo_path']): ?><img class="client-profile-photo" src="client-logo.php?id=<?= $id ?>" alt="<?= h($client['client_name']) ?> logo"><?php endif; ?>
        <dl class="details-list">
            <?php foreach (['phone'=>'Phone number','starting_date'=>'Starting date','ending_date'=>'Ending date','created_at'=>'Added on'] as $key=>$label): ?>
                <dt><?= h($label) ?></dt><dd><?= h($client[$key]?:'—') ?></dd>
            <?php endforeach; ?>
            <dt>Status</dt><dd><?= status_badge($client['is_active']?'active':'inactive') ?></dd>
            <?php if ($staff): ?><dt>Package</dt><dd><?= h($client['package']?:'—') ?></dd><?php endif; ?>
        </dl>
        <?php if ($staff): ?><a class="button" href="admin-add-client.php?id=<?= $id ?>">Edit client</a><?php endif; ?>
    </section>
    <div class="client-profile-main">
        <section class="panel">
            <h2>Monthly work</h2>
            <div class="money-summary">
                <?php foreach (['monthly_reels'=>'Reels','monthly_posters'=>'Posters','monthly_carousels'=>'Carousels'] as $key=>$label): ?>
                    <div class="money-card"><span><?= h($label) ?></span><strong><?= h($client[$key]) ?></strong></div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="panel">
            <h2>Website &amp; social links</h2>
            <dl class="details-list">
                <dt>Website</dt><dd><?php client_profile_link($client['website_url']); ?></dd>
                <dt>Google Business Profile</dt><dd><?php client_profile_link($client['gmb_url']); ?></dd>
                <?php foreach ($socialLinks as $link): ?><dt><?= h($link['platform_name']) ?></dt><dd><?php client_profile_link($link['url']); ?></dd><?php endforeach; ?>
            </dl>
        </section>
        <?php if ($staff): ?>
            <section class="panel">
                <h2>Client payments</h2>
                <div class="money-summary">
                    <?php foreach (['payment_total'=>'Total payment','paid_amount'=>'Paid','remaining_amount'=>'Remaining'] as $key=>$label): ?>
                        <div class="money-card <?= $key==='remaining_amount'?'highlight':'' ?>"><span><?= h($label) ?></span><strong><?= h(money_label($client[$key])) ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <a class="button secondary" href="admin-add-client.php?id=<?= $id ?>#saved-payments">Manage payments</a>
            </section>
            <section class="panel">
                <h2>Payment history</h2>
                <?php $payments=rows('SELECT p.*,u.full_name AS recorded_by FROM client_payments p JOIN users u ON u.id=p.created_by WHERE p.client_id=? ORDER BY p.payment_date,p.id',[$id]); ?>
                <div class="table-scroll"><table>
                    <thead><tr><th>Date</th><th>Amount</th><th>Recorded by</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (!$payments) empty_row(4,'No payments saved yet.'); foreach ($payments as $payment): ?>
                            <tr><td><?= h($payment['payment_date']) ?></td><td><?= h(money_label($payment['amount'])) ?></td><td><?= h($payment['recorded_by']) ?></td><td><a class="button secondary" href="admin-add-client.php?id=<?= $id ?>&amp;edit_payment=<?= (int)$payment['id'] ?>#saved-payments">Edit payment</a></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php page_end(); ?>
