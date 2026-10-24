<?php
// Included by the authenticated employee daily work page.
$formValues=$editEntry??[];
if ($error && ($_POST['action']??'')==='save_entry') {
    foreach ($_POST as $key=>$value) if (is_string($value)) $formValues[$key]=$value;
}
$batchWork=$creativeWork && !$editEntry;
$socialBatch=$socialWork && !$editEntry;
?>
<section class="panel" id="add-work">
<h2><?= $editEntry?'Edit work entry':'Update daily work' ?></h2>
<?php if ($socialBatch):
    $socialTaskRows=[['task_status'=>'completed']];
    if ($error && ($_POST['action']??'')==='save_social_tasks' && is_array($_POST['tasks']??null) && $_POST['tasks']) {
        $socialTaskRows=[];
        foreach(array_values($_POST['tasks']) as $task) $socialTaskRows[]=is_array($task)?array_filter($task,static fn($v)=>is_string($v)||is_array($v)):[];
    }
    $taskClients=clients();
    $socialChoices=[];
    foreach(rows('SELECT client_id,id,platform_name,url FROM client_social_links ORDER BY client_id,id') as $link) $socialChoices[(int)$link['client_id']][]=$link;
    $renderSocialTask=static function($index,array $values=[]) use($taskClients,$socialChoices): void {
        $prefix='tasks['.$index.']'; $id='social-task-'.$index.'-'; $selected=array_map('strval',$values['platforms']??[]); ?>
        <fieldset class="daily-task-card" data-daily-task>
        <legend data-daily-task-heading>Client task <?= is_int($index)?$index+1:'__NUMBER__' ?></legend>
        <div class="fields">
          <div class="field"><label for="<?= h($id) ?>client">Client</label><select id="<?= h($id) ?>client" name="<?= h($prefix) ?>[client_id]" data-social-client required><option value="">Choose client</option><?= options($taskClients,'client_name',$values['client_id']??null) ?></select></div>
          <div class="field"><label for="<?= h($id) ?>type">Content type</label><select id="<?= h($id) ?>type" name="<?= h($prefix) ?>[work_type]" required><option value="">Choose type</option><?php foreach(['poster'=>'Poster','reel'=>'Reel','carousel'=>'Carousel'] as $type=>$label): ?><option value="<?= h($type) ?>" <?= ($values['work_type']??'')===$type?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="<?= h($id) ?>count">Quantity</label><input id="<?= h($id) ?>count" name="<?= h($prefix) ?>[item_count]" type="number" min="1" max="999999" step="1" value="<?= h($values['item_count']??'1') ?>" required></div>
          <div class="field full"><span class="field-label">Social media accounts used</span><div class="status-choice" data-social-platforms><?php foreach($socialChoices[(int)($values['client_id']??0)]??[] as $link): ?><label><input type="checkbox" name="<?= h($prefix) ?>[platforms][]" value="<?= (int)$link['id'] ?>" <?= in_array((string)$link['id'],$selected,true)?'checked':'' ?>><?= h($link['platform_name']) ?></label><?php endforeach; ?><span class="muted" data-social-empty><?= empty($socialChoices[(int)($values['client_id']??0)])?'Select a client with social accounts saved in its profile.':'' ?></span></div></div>
          <div class="field full"><label for="<?= h($id) ?>remark">Remark (optional)</label><textarea id="<?= h($id) ?>remark" name="<?= h($prefix) ?>[remark]" maxlength="5000"><?= h($values['remark']??'') ?></textarea></div>
        </div><div class="form-actions"><button class="button secondary" type="button" data-remove-daily-task>Remove client</button></div></fieldset><?php
    };
?>
<p class="muted">Add each client separately. Choose Poster, Reel or Carousel, enter quantity, and tick the client accounts where you published. Work date: <?= h(date('d-m-Y',strtotime($date))) ?>.</p>
<form method="post" data-daily-work-form data-social-work-form><?= csrf_field() ?><input type="hidden" name="action" value="save_social_tasks"><div data-daily-tasks><?php foreach($socialTaskRows as $index=>$values) $renderSocialTask($index,$values); ?></div><input type="hidden" name="task_count" value="<?= count($socialTaskRows) ?>"><div class="form-actions daily-work-actions"><button type="button" class="button secondary" data-add-daily-task>+ Add client</button><button type="submit" class="primary">Save &amp; submit all work</button></div><p class="help" data-daily-task-summary aria-live="polite"></p></form>
<template id="daily-task-template"><?php $renderSocialTask('__INDEX__',['item_count'=>'1','task_status'=>'completed']); ?></template>
<script>window.socialClientPlatforms=<?= json_encode($socialChoices,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;</script><script src="assets/js/daily-work.js?v=<?= filemtime(__DIR__.'/../assets/js/daily-work.js') ?>" defer></script>
<?php elseif ($batchWork):
    $taskRows=[['task_status'=>'completed']];
    if ($error && ($_POST['action']??'')==='save_tasks' && is_array($_POST['tasks']??null) && $_POST['tasks']) {
        $taskRows=[];
        foreach (array_values($_POST['tasks']) as $task) $taskRows[]=is_array($task)?array_filter($task,'is_string'):[];
    }
    $taskClients=clients();
    $renderTask=static function ($index,array $values=[]) use ($taskClients): void {
        $prefix='tasks['.$index.']';
        $id='daily-task-'.$index.'-';
        ?>
        <fieldset class="daily-task-card" data-daily-task>
        <legend data-daily-task-heading>Task <?= is_int($index)?$index+1:'__NUMBER__' ?></legend>
        <div class="fields">
            <div class="field"><label for="<?= h($id) ?>type">Task type</label><select id="<?= h($id) ?>type" name="<?= h($prefix) ?>[work_type]" required><option value="">Choose task type</option><?php foreach (creative_work_types() as $value=>$label): ?><option value="<?= h($value) ?>" <?= ($values['work_type']??'')===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="<?= h($id) ?>title">Title</label><input id="<?= h($id) ?>title" name="<?= h($prefix) ?>[task_title]" value="<?= h($values['task_title']??'') ?>" maxlength="255" required></div>
            <div class="field"><label for="<?= h($id) ?>time">Time taken (up to 24 hours)</label><input id="<?= h($id) ?>time" name="<?= h($prefix) ?>[time_spent_hours]" value="<?= h($values['time_spent_hours']??'') ?>" maxlength="32" placeholder="e.g. 45 min or 1 hour 30 min" aria-describedby="<?= h($id) ?>time-help" required><p class="help" id="<?= h($id) ?>time-help">Enter how long this task took. You can also use 01:30 or decimal hours such as 1.5.</p></div>
            <div class="field"><label for="<?= h($id) ?>client">Client (optional)</label><select id="<?= h($id) ?>client" name="<?= h($prefix) ?>[client_id]"><option value="">No client / internal work</option><?= options($taskClients,'client_name',$values['client_id']??null) ?></select></div>
            <div class="field"><label for="<?= h($id) ?>status">Status</label><select id="<?= h($id) ?>status" name="<?= h($prefix) ?>[task_status]" required><?= options([['id'=>'completed','name'=>'Completed'],['id'=>'pending','name'=>'Pending']],'name',$values['task_status']??'completed') ?></select></div>
            <div class="field full"><label for="<?= h($id) ?>remark">Remark</label><textarea id="<?= h($id) ?>remark" name="<?= h($prefix) ?>[remark]" maxlength="5000"><?= h($values['remark']??'') ?></textarea></div>
        </div>
        <div class="form-actions"><button class="button secondary" type="button" data-remove-daily-task>Remove task</button></div>
        </fieldset>
        <?php
    };
?>
<p class="muted">Choose Poster, Video Editing or Carousel for each task. Add more tasks, then submit them together for <?= h($date) ?>.</p>
<form method="post" data-daily-work-form>
<?= csrf_field() ?><input type="hidden" name="action" value="save_tasks">
<div data-daily-tasks><?php foreach ($taskRows as $index=>$values) $renderTask($index,$values); ?></div>
<input type="hidden" name="task_count" value="<?= count($taskRows) ?>">
<div class="form-actions daily-work-actions"><button type="button" class="button secondary" data-add-daily-task>+ Add task</button><button type="submit" class="primary">Save &amp; submit all tasks</button></div>
<p class="help" data-daily-task-summary aria-live="polite"></p>
</form>
<template id="daily-task-template"><?php $renderTask('__INDEX__',['task_status'=>'completed']); ?></template>
<script src="assets/js/daily-work.js?v=<?= filemtime(__DIR__.'/../assets/js/daily-work.js') ?>" defer></script>
<?php else: ?>
<form method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="save_entry"><input type="hidden" name="entry_id" value="<?= h($editEntry['id']??'') ?>">
<div class="fields">
    <?php if ($creativeWork) render_work_type($formValues,'-daily'); ?>
    <div class="field"><label for="client_id">Client (optional)</label><select id="client_id" name="client_id"><option value="">No client / internal work</option><?= options(clients(),'client_name',$formValues['client_id']??null) ?></select></div>
    <div class="field"><label for="task_title"><?= $creativeWork?'Title':'Task / page' ?></label><input id="task_title" name="task_title" value="<?= h($formValues['task_title']??'') ?>" maxlength="255" required></div>
    <div class="field"><label for="daily-time">Time spent (<?= $creativeWork?'':'optional, ' ?>up to 24 hours)</label><input id="daily-time" name="time_spent_hours" type="text" maxlength="32" placeholder="e.g. 2 hours 30 min or 02:30" value="<?= h($formValues['time_spent_hours']??(isset($formValues['time_spent_minutes'])?format_work_duration($formValues['time_spent_minutes']):'')) ?>" <?= $creativeWork?'required':'' ?>><p class="help">Hours and minutes, HH:MM, or decimal hours. Maximum: 24 hours.</p></div>
    <div class="field full"><label>Status</label><div class="status-choice"><?php foreach (['pending','completed'] as $status): ?><label><input type="radio" name="task_status" value="<?= $status ?>" <?= ($formValues['task_status']??'pending')===$status?'checked':'' ?> required><?= ucfirst($status) ?></label><?php endforeach; ?></div></div>
    <div class="field full"><label for="remark">Remark</label><textarea id="remark" name="remark" maxlength="5000"><?= h($formValues['remark']??'') ?></textarea></div>
</div>
<?php render_metrics($workDepartment,$formValues,'-new'); ?>
<div class="form-actions daily-work-actions"><button class="primary" name="submit_mode" value="submitted">Save &amp; submit</button></div>
</form>
<?php endif; ?>
</section>
