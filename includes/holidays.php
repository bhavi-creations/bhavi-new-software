<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/calendar.php';
require_roles();
$error=null;
$editId=(int)($_GET['edit']??0);
if ($editId && !is_staff()) fail(403,'Only administrators and managers can edit holidays.');
$record=$editId?one('SELECT * FROM holidays WHERE id=? AND is_published=1',[$editId]):null;
if ($editId && !$record) fail(404,'Holiday not found.');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $user=require_roles(['admin','manager']); check_csrf();
    try {
        if (($_POST['action']??'')==='delete_holiday') {
            query('UPDATE holidays SET is_published=0 WHERE id=?',[positive_id($_POST['id']??null)]);
            flash('Holiday deleted.');
        } elseif (($_POST['action']??'')==='save_holiday') {
            $name=text_input('holiday_name',150); $date=date_input('holiday_date'); $description=text_input('description',3000,false);
            if ($editId) query('UPDATE holidays SET holiday_name=?,holiday_date=?,description=? WHERE id=? AND is_published=1',[$name,$date,$description,$editId]);
            else {
                $existing=one('SELECT id,is_published FROM holidays WHERE holiday_date=?',[$date]);
                if ($existing && $existing['is_published']) throw new InvalidArgumentException('A holiday is already listed for this date. Edit that holiday instead.');
                if ($existing) query('UPDATE holidays SET holiday_name=?,description=?,created_by=?,is_published=1 WHERE id=?',[$name,$description,$user['id'],$existing['id']]);
                else query('INSERT INTO holidays (holiday_name,holiday_date,description,created_by) VALUES (?,?,?,?)',[$name,$date,$description,$user['id']]);
            }
            flash('Holiday saved. It is now visible to the whole team.');
        } else throw new InvalidArgumentException('Invalid action.');
        redirect('admin-holidays.php');
    } catch (Throwable $e) { $error=mutation_error($e); }
}
$holidays=rows('SELECT h.*,u.full_name AS added_by FROM holidays h JOIN users u ON u.id=h.created_by WHERE h.is_published=1 ORDER BY h.holiday_date');
$events=[]; foreach ($holidays as $holiday) $events[$holiday['holiday_date']][]=['label'=>$holiday['holiday_name'],'class'=>''];
page_start('Holidays','admin-holidays.php'); error_message($error);
?><p class="muted">Published holidays are visible to administrators, managers and employees.</p><?php render_calendar(calendar_month(),$events); ?>
<?php if (is_staff()): ?><section class="panel"><h2><?= $record?'Edit holiday':'Add holiday' ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_holiday"><div class="fields"><div class="field"><label for="holiday_name">Holiday name</label><input id="holiday_name" name="holiday_name" value="<?= h($_POST['holiday_name']??$record['holiday_name']??'') ?>" maxlength="150" required></div><div class="field"><label for="holiday_date">Date</label><input type="date" id="holiday_date" name="holiday_date" value="<?= h($_POST['holiday_date']??$record['holiday_date']??today()) ?>" required></div><div class="field full"><label for="description">Description</label><textarea id="description" name="description" maxlength="3000"><?= h($_POST['description']??$record['description']??'') ?></textarea></div></div><div class="form-actions"><button class="primary" type="submit">Save holiday</button><?php if ($record): ?><a class="button secondary" href="admin-holidays.php">Cancel</a><?php endif; ?></div></form></section><?php endif; ?>
<section class="panel"><h2>Holiday list</h2><div class="table-scroll"><table><thead><tr><th>Name</th><th>Date</th><th>Description</th><th>Added by</th><th>Actions</th></tr></thead><tbody><?php if (!$holidays) empty_row(5); foreach ($holidays as $holiday): ?><tr><td><?= h($holiday['holiday_name']) ?></td><td><?= h($holiday['holiday_date']) ?></td><td class="wrap"><?= h($holiday['description']?:'—') ?></td><td><?= h($holiday['added_by']) ?></td><td><div class="actions"><?php detail_button('holiday-'.$holiday['id']); if (is_staff()): ?><a class="icon-button" href="admin-holidays.php?edit=<?= $holiday['id'] ?>" title="Edit holiday" aria-label="Edit holiday"><?= icon('edit') ?></a><?php delete_button((int)$holiday['id'],'delete_holiday','Delete this holiday?'); endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php foreach ($holidays as $holiday) detail_dialog('holiday-'.$holiday['id'],$holiday['holiday_name'],['Name'=>$holiday['holiday_name'],'Date'=>$holiday['holiday_date'],'Description'=>$holiday['description'],'Added by'=>$holiday['added_by']]); page_end(); ?>
