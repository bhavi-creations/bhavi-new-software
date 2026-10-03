<?php
require_once __DIR__ . '/layout.php';
$user=require_roles(['admin','manager']);
$id=(int)($_GET['id'] ?? 0);
$record=$id?one('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL',[$id]):null;
if ($id && !$record) fail(404,'Client not found.');
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf(); $savedFile=null;
    try {
        $name=text_input('client_name',150);
        $website=text_input('website_url',2048,false);
        if ($website!=='' && (!filter_var($website,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($website,PHP_URL_SCHEME)??''),['http','https'],true))) { throw new InvalidArgumentException('Enter an http:// or https:// website URL.'); }
        $logo=$record['logo_path'] ?? '';
        $file=$_FILES['client_logo'] ?? null;
        if ($file && $file['error']!==UPLOAD_ERR_NO_FILE) {
            if ($file['error']!==UPLOAD_ERR_OK || $file['size']>5*1024*1024) { throw new InvalidArgumentException('Choose a PNG, JPG or WebP logo under 5 MB.'); }
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extension=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime] ?? null;
            if (!$extension || !getimagesize($file['tmp_name'])) { throw new InvalidArgumentException('Choose a valid PNG, JPG or WebP image.'); }
            $directory=dirname(__DIR__).'/uploads/logos';
            if (!is_dir($directory) && !mkdir($directory,0755,true)) { throw new RuntimeException('Unable to create logo folder.'); }
            $logo='uploads/logos/'.bin2hex(random_bytes(16)).'.'.$extension;
            $savedFile=dirname(__DIR__).'/'.$logo;
            if (!move_uploaded_file($file['tmp_name'],$savedFile)) { throw new RuntimeException('Unable to save logo.'); }
        }
        if ($id) query('UPDATE clients SET client_name=?,website_url=?,logo_path=? WHERE id=?',[$name,$website,$logo,$id]);
        else query('INSERT INTO clients (client_name,website_url,logo_path,created_by) VALUES (?,?,?,?)',[$name,$website,$logo,$user['id']]);
        flash('Client saved.'); redirect('add-client.php');
    } catch (Throwable $e) { if ($savedFile && is_file($savedFile)) unlink($savedFile); $error=mutation_error($e); }
}
page_start($record?'Edit client':'Add client','add-client.php'); error_message($error);
?><section class="panel"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><div class="fields"><div class="field"><label for="client_name">Client name</label><input id="client_name" name="client_name" value="<?= h($_POST['client_name'] ?? $record['client_name'] ?? '') ?>" maxlength="150" required></div><div class="field"><label for="website_url">Website URL</label><input type="url" id="website_url" name="website_url" value="<?= h($_POST['website_url'] ?? $record['website_url'] ?? '') ?>" placeholder="https://www.example.com" maxlength="2048"></div><div class="field full"><label for="client_logo">Client logo</label><input type="file" id="client_logo" name="client_logo" accept="image/png,image/jpeg,image/webp"><p class="help">PNG, JPG or WebP · Up to 5 MB. <?= $record?'Leave blank to keep the current logo.':'' ?></p><?php if (!empty($record['logo_path'])): ?><img class="client-logo" src="<?= h($record['logo_path']) ?>" alt="Current logo"><?php endif; ?></div></div><div class="form-actions"><button class="primary" type="submit">Save client</button><a class="button secondary" href="add-client.php">Cancel</a></div></form></section><?php page_end(); ?>
