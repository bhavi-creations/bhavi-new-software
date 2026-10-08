<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__.'/finance.php';
$user=require_roles(['admin','manager']);
$id=(int)($_GET['id'] ?? 0);
$record=$id?one('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL',[$id]):null;
if ($id && !$record) fail(404,'Client not found.');
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf(); $savedFile=null;
    try {
        $action=$_POST['action']??'save_client';
        if ($action==='update_payment') {
            if (!$id) throw new InvalidArgumentException('Select a client before editing a payment.');
            $paymentId=positive_id($_POST['payment_id']??null);
            $paymentDate=date_input('payment_date');
            $amount=decimal_money(money_cents($_POST['amount']??''));
            if (money_cents($amount)===0) throw new InvalidArgumentException('Payment must be greater than zero.');
            db()->beginTransaction();
            $lockedClient=one('SELECT payment_total FROM clients WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);
            $payment=one('SELECT id FROM client_payments WHERE id=? AND client_id=? FOR UPDATE',[$paymentId,$id]);
            if (!$lockedClient || !$payment) throw new InvalidArgumentException('Client payment is no longer available.');
            $otherPayments=(string)query('SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE client_id=? AND id<>?',[$id,$paymentId])->fetchColumn();
            if (money_cents($otherPayments)+money_cents($amount)>money_cents((string)$lockedClient['payment_total'])) {
                throw new InvalidArgumentException('Payments exceed the total payment amount.');
            }
            query('UPDATE client_payments SET payment_date=?,amount=? WHERE id=? AND client_id=?',[$paymentDate,$amount,$paymentId,$id]);
            $paid=(string)query('SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE client_id=?',[$id])->fetchColumn();
            query('UPDATE clients SET paid_amount=? WHERE id=?',[$paid,$id]);
            db()->commit();
            flash('Saved payment updated.');
            redirect('admin-add-client.php?id='.$id.'#saved-payments');
        }
        if ($action!=='save_client') throw new InvalidArgumentException('Invalid action.');
        $name=text_input('client_name',150);
        $website=text_input('website_url',2048,false);
        if ($website!=='' && (!filter_var($website,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($website,PHP_URL_SCHEME)??''),['http','https'],true))) { throw new InvalidArgumentException('Enter an http:// or https:// website URL.'); }
        $socialLinks=$_POST['social_links']??[];
        if (!is_array($socialLinks) || count($socialLinks)>50) throw new InvalidArgumentException('Add up to 50 social media links.');
        $validSocialLinks=[];
        foreach ($socialLinks as $link) {
            if (!is_array($link)) throw new InvalidArgumentException('Invalid social media link.');
            $platformValue=$link['platform']??'';
            $urlValue=$link['url']??'';
            if (!is_string($platformValue) || !is_string($urlValue)) throw new InvalidArgumentException('Invalid social media link.');
            $platform=trim($platformValue);
            $url=trim($urlValue);
            if ($platform==='' && $url==='') continue;
            if ($platform==='' || mb_strlen($platform)>100) throw new InvalidArgumentException('Enter a platform name up to 100 characters for each social media link.');
            if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true) || strlen($url)>2048) throw new InvalidArgumentException('Enter a valid http:// or https:// URL for each social media link.');
            $validSocialLinks[]=['platform'=>$platform,'url'=>$url];
        }
        $legacySocialUrl=$validSocialLinks[0]['url']??'';
        $extra=['phone'=>optional_text('phone',30),'starting_date'=>optional_date('starting_date'),'ending_date'=>optional_date('ending_date'),'package'=>optional_text('package'),'social_media_url'=>$legacySocialUrl,'gmb_url'=>optional_text('gmb_url',2048)];
        if ($extra['starting_date'] && $extra['ending_date'] && $extra['ending_date']<$extra['starting_date']) throw new InvalidArgumentException('Ending date cannot be before starting date.');
        foreach (['social_media_url','gmb_url'] as $key) if ($extra[$key]!=='' && (!filter_var($extra[$key],FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($extra[$key],PHP_URL_SCHEME)??''),['http','https'],true))) throw new InvalidArgumentException('Enter valid http:// or https:// social media and GMB URLs.');
        foreach (['monthly_reels','monthly_posters','monthly_carousels'] as $key) {
            $number=filter_var($_POST[$key]??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]);
            if ($number===false) throw new InvalidArgumentException('Monthly work quantities must be whole numbers from 0 to 1,000,000.');
            $extra[$key]=$number;
        }
        $total=money_cents($_POST['payment_total']??($record['payment_total']??'0'));
        $extra['payment_total']=decimal_money($total);
        $payments=$_POST['payments']??[]; $newPayments=[];
        if (!is_array($payments) || count($payments)>100) throw new InvalidArgumentException('Add up to 100 payments at a time.');
        $requestKey=$_POST['payment_request_key']??'';
        foreach ($payments as $payment) {
            if (!is_array($payment)) throw new InvalidArgumentException('Invalid payment entry.');
            if (($payment['amount']??'')==='' && ($payment['date']??'')==='') continue;
            if (!is_string($requestKey) || !preg_match('/^[a-f0-9]{32}$/D',$requestKey)) throw new InvalidArgumentException('Refresh the payment form and try again.');
            $amount=money_cents($payment['amount']??'');
            if (!$amount) throw new InvalidArgumentException('Payment must be greater than zero.');
            $newPayments[]=['date'=>date_input('date',$payment),'amount'=>decimal_money($amount),'key'=>$requestKey.'-'.count($newPayments)];
        }
        db()->beginTransaction();
        if ($id && !one('SELECT id FROM clients WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id])) throw new InvalidArgumentException('Client is no longer available.');
        $logo=$record['logo_path'] ?? '';
        $file=$_FILES['client_logo'] ?? null;
        if ($file && $file['error']!==UPLOAD_ERR_NO_FILE) {
            if ($file['error']!==UPLOAD_ERR_OK || $file['size']>50*1024*1024) { throw new InvalidArgumentException('Choose a PNG, JPG or WebP logo under 50 MB.'); }
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extension=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime] ?? null;
            if (!$extension || !getimagesize($file['tmp_name'])) { throw new InvalidArgumentException('Choose a valid PNG, JPG or WebP image.'); }
            $directory=dirname(__DIR__).'/uploads/photos';
            if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) { throw new InvalidArgumentException('Unable to create uploads/photos. Check the folder permissions.'); }
            if (!is_writable($directory)) { throw new InvalidArgumentException('uploads/photos is not writable by PHP. Check the folder permissions.'); }
            $logo='uploads/photos/'.bin2hex(random_bytes(16)).'.'.$extension;
            $savedFile=dirname(__DIR__).'/'.$logo;
            if (!move_uploaded_file($file['tmp_name'],$savedFile)) { throw new InvalidArgumentException('The logo could not be stored. Check uploads/photos permissions and try a PNG, JPG or WebP under 50 MB.'); }
        }
        if ($id) query('UPDATE clients SET client_name=?,website_url=?,logo_path=? WHERE id=?',[$name,$website,$logo,$id]);
        else query('INSERT INTO clients (client_name,website_url,logo_path,created_by) VALUES (?,?,?,?)',[$name,$website,$logo,$user['id']]);
        if (!$id) $id=(int)db()->lastInsertId();
        foreach ($newPayments as $payment) {
            $existing=one('SELECT * FROM client_payments WHERE request_key=?',[$payment['key']]);
            if ($existing) {
                if ((int)$existing['client_id']!==$id || $existing['payment_date']!==$payment['date'] || $existing['amount']!==$payment['amount']) throw new InvalidArgumentException('This payment form was already submitted. Refresh before adding another payment.');
                continue;
            }
            query('INSERT INTO client_payments (client_id,payment_date,amount,request_key,created_by) VALUES (?,?,?,?,?)',[$id,$payment['date'],$payment['amount'],$payment['key'],$user['id']]);
        }
        $paid=(string)query('SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE client_id=?',[$id])->fetchColumn();
        if (money_cents($paid)>$total) throw new InvalidArgumentException('Payments exceed the total payment amount. Increase the total or correct the new payment.');
        $extra['paid_amount']=$paid;
        $assignments=implode(',',array_map(static fn($key)=>$key.'=?',array_keys($extra)));
        query('UPDATE clients SET '.$assignments.' WHERE id=?',array_merge(array_values($extra),[$id]));
        query('DELETE FROM client_social_links WHERE client_id=?',[$id]);
        foreach ($validSocialLinks as $link) {
            query('INSERT INTO client_social_links (client_id,platform_name,url) VALUES (?,?,?)',[$id,$link['platform'],$link['url']]);
        }
        db()->commit();
        flash('Client saved.'); redirect('add-client.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); if (!$record) $id=0; if ($savedFile && is_file($savedFile)) unlink($savedFile); $error=mutation_error($e); }
}
$value=static fn($key,$fallback='')=>$_POST[$key]??$record[$key]??$fallback;
page_start($record?'Edit client':'Add client','add-client.php'); error_message($error);
?><p class="muted">Add the client, monthly work and payment details below.</p>
<form method="post" enctype="multipart/form-data" class="record-form"><?= csrf_field() ?>
<section class="panel form-section"><h2><span class="section-number">1</span> Client details</h2><div class="fields">
<div class="field"><label for="client_name">Client name *</label><input id="client_name" name="client_name" value="<?= h($value('client_name')) ?>" maxlength="150" required></div>
<?php foreach (['phone'=>['Phone number','tel',30],'package'=>['Package name','text',255],'starting_date'=>['Starting date','date',10],'ending_date'=>['Ending date','date',10]] as $key=>$spec): ?><div class="field"><label for="<?= $key ?>"><?= $spec[0] ?></label><input id="<?= $key ?>" name="<?= $key ?>" type="<?= $spec[1] ?>" maxlength="<?= $spec[2] ?>" value="<?= h($value($key)) ?>"></div><?php endforeach; ?>
</div><details class="simple-details"><summary>Website, social media & logo (optional)</summary><div class="fields">
<div class="field"><label for="website_url">Website URL</label><input type="url" id="website_url" name="website_url" value="<?= h($value('website_url')) ?>" placeholder="https://" maxlength="2048"></div>
<div class="field"><label for="gmb_url">Google Business Profile URL</label><input type="url" id="gmb_url" name="gmb_url" value="<?= h($value('gmb_url')) ?>" placeholder="https://" maxlength="2048"></div>
<div class="field social-links-field"><label>Social media links</label><p class="help">Enter a platform name and its URL. Add as many as needed.</p><div id="social-link-rows"><?php
$savedSocialLinks=$id?rows('SELECT platform_name AS platform,url FROM client_social_links WHERE client_id=? ORDER BY id',[$id]):[];
$socialDraft=$_POST['social_links']??$savedSocialLinks;
if (!is_array($socialDraft)) $socialDraft=[];
if (!$socialDraft) $socialDraft=[['platform'=>'','url'=>'']];
foreach ($socialDraft as $index=>$link): if (!is_array($link)) continue; ?><div class="fields repeat-row social-link-row"><div class="field"><label>Platform name<input name="social_links[<?= (int)$index ?>][platform]" maxlength="100" placeholder="e.g. Instagram" value="<?= h($link['platform']??'') ?>"></label></div><div class="field"><label>Social media URL<input type="url" name="social_links[<?= (int)$index ?>][url]" maxlength="2048" placeholder="https://" value="<?= h($link['url']??'') ?>"></label></div><button type="button" class="secondary" data-remove-row>Remove</button></div><?php endforeach; ?></div><button type="button" class="secondary" data-add-row="social">+ Add social media link</button></div>
<div class="field"><label for="client_logo">Client logo</label><input type="file" id="client_logo" name="client_logo" accept="image/png,image/jpeg,image/webp"><p class="help">PNG, JPG or WebP, up to 50 MB. Leave empty to keep the current logo.</p><?php if (!empty($record['logo_path'])): ?><img class="client-logo" src="client-logo.php?id=<?= (int)$record['id'] ?>" alt="Current logo"><?php endif; ?></div></div></details></section>
<section class="panel form-section"><h2><span class="section-number">2</span> Work to deliver each month</h2><p class="help">Enter how many of each item you need to create. Use 0 if it is not included.</p><div class="fields">
<?php foreach (['monthly_reels'=>'Reels','monthly_posters'=>'Posters','monthly_carousels'=>'Carousels'] as $key=>$label): ?><div class="field"><label for="<?= $key ?>"><?= $label ?> per month</label><input type="number" min="0" max="1000000" step="1" id="<?= $key ?>" name="<?= $key ?>" value="<?= h($value($key,0)) ?>" required></div><?php endforeach; ?>
</div></section>
<section class="panel form-section"><h2><span class="section-number">3</span> Payments</h2>
<div class="field"><label for="payment_total">Total agreed amount (₹)</label><input id="payment_total" name="payment_total" type="number" min="0" max="9999999999.99" step="0.01" value="<?= h($value('payment_total','0.00')) ?>" required></div>
<div class="money-summary"><div class="money-card"><span>Total amount</span><strong id="total-preview"><?= h(money_label($record['payment_total']??0)) ?></strong></div><div class="money-card"><span>Received so far</span><strong id="paid-preview"><?= h(money_label($record['paid_amount']??0)) ?></strong></div><div class="money-card highlight"><span>Still to receive</span><strong id="remaining-preview" data-paid="<?= h($record['paid_amount']??0) ?>"><?= h(money_label($record['remaining_amount']??0)) ?></strong></div></div>
<p class="help">Example: ₹10,000 total − ₹2,000 received = ₹8,000 remaining.</p>
<h3>Add money received</h3><p class="help">Enter the date and amount paid by the client. Leave empty if no new payment was received.</p>
<input type="hidden" name="payment_request_key" value="<?= h($_POST['payment_request_key']??bin2hex(random_bytes(16))) ?>"><div id="payment-rows"><?php $draft=$_POST['payments']??[['amount'=>'','date'=>'']]; if (!is_array($draft)) $draft=[]; foreach ($draft as $index=>$payment): if (!is_array($payment)) continue; ?><div class="fields repeat-row"><div class="field"><label>Date received<input type="date" name="payments[<?= (int)$index ?>][date]" value="<?= h($payment['date']??'') ?>"></label></div><div class="field"><label>Amount received (₹)<input type="number" name="payments[<?= (int)$index ?>][amount]" min="0.01" max="9999999999.99" step="0.01" placeholder="e.g. 2000" value="<?= h($payment['amount']??'') ?>"></label></div><button type="button" class="secondary" data-remove-row>Remove</button></div><?php endforeach; ?></div>
<button type="button" class="secondary" data-add-row="payment">+ Add another payment</button><p class="help">The totals above include new entries. Click Save client to save them.</p>
</section><div class="form-actions"><button class="primary" type="submit">Save client</button><a class="button secondary" href="add-client.php">Cancel</a></div></form>
<?php if ($record): $history=rows('SELECT * FROM client_payments WHERE client_id=? ORDER BY payment_date,id',[$id]); ?><section class="panel" id="saved-payments"><details class="simple-details" open><summary>View saved payments (<?= count($history) ?>)</summary><div class="table-scroll"><table><thead><tr><th>Date received</th><th>Amount received</th><th>Action</th></tr></thead><tbody><?php if (!$history) empty_row(3,'No payments received yet.'); foreach ($history as $payment): $editing=(int)($_GET['edit_payment']??0)===(int)$payment['id']; ?><tr><?php if ($editing): $formId='edit-payment-'.$payment['id']; ?><td><input form="<?= $formId ?>" type="date" name="payment_date" aria-label="Date received" value="<?= h($payment['payment_date']) ?>" required></td><td><input form="<?= $formId ?>" type="number" name="amount" aria-label="Amount received" min="0.01" max="9999999999.99" step="0.01" value="<?= h($payment['amount']) ?>" required></td><td><form id="<?= $formId ?>" method="post" action="admin-add-client.php?id=<?= $id ?>&edit_payment=<?= (int)$payment['id'] ?>#saved-payments"><?= csrf_field() ?><input type="hidden" name="action" value="update_payment"><input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>"></form><button form="<?= $formId ?>" class="primary" type="submit">Save</button> <a class="button secondary" href="admin-add-client.php?id=<?= $id ?>#saved-payments">Cancel</a></td><?php else: ?><td><?= h($payment['payment_date']) ?></td><td><?= h(money_label($payment['amount'])) ?></td><td><a class="button secondary" href="admin-add-client.php?id=<?= $id ?>&edit_payment=<?= (int)$payment['id'] ?>#saved-payments">Edit</a></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></details></section><?php endif; ?>
<script src="assets/js/records.js" defer></script><?php page_end(); ?>
