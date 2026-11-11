<?php
require_once __DIR__.'/includes/layout.php';
require_roles(['admin']); $error=null;
const DEPARTMENT_COLORS = ['navy' => '#103d83', 'blue' => '#1769d2', 'gold' => '#d6ad48', 'sky' => '#9db8e0'];
$editId=(int)($_GET['edit']??0);
$record=$editId?one('SELECT * FROM departments WHERE id=? AND is_active=1',[$editId]):null;
if ($editId && !$record) fail(404,'Department not found.');
$headOptions=rows("SELECT u.id,u.full_name,u.role FROM users u WHERE u.role IN ('employee','manager','admin') AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.full_name");
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (($_POST['action']??'')==='delete_department') {
            $id=positive_id($_POST['id']??null);
            db()->beginTransaction();
            if (!one('SELECT id FROM departments WHERE id=? AND is_active=1 FOR UPDATE',[$id])) throw new InvalidArgumentException('Department not found.');
            if (count_value('SELECT COUNT(*) FROM employee_profiles ep JOIN users u ON u.id=ep.user_id WHERE ep.department_id=? AND u.deleted_at IS NULL',[$id])) throw new InvalidArgumentException('Move the employees to another department before deleting this department.');
            query('UPDATE departments SET is_active=0 WHERE id=?',[$id]);
            db()->commit(); flash('Department deleted. Historical reports are retained.');
        } else {
            $name=text_input('name',100);
            $description=isset($_POST['description']) ? text_input('description',255,false) : ($record['description'] ?? '');
            $color=is_string($_POST['card_color']??null) && isset(DEPARTMENT_COLORS[$_POST['card_color']]) ? $_POST['card_color'] : 'navy';
            $headValue=$_POST['head_user_id']??'';
            $head=$headValue===''||$headValue===null?null:positive_id($headValue);
            if ($head!==null && !in_array($head,array_map('intval',array_column($headOptions,'id')),true)) throw new InvalidArgumentException('Choose an active employee as department head.');
            $existing=one('SELECT id,is_active FROM departments WHERE name=? AND id<>? LIMIT 1',[$name,$editId]);
            if ($existing && (int)$existing['is_active']===1) throw new InvalidArgumentException('This department name is already in use.');
            if ($record) query('UPDATE departments SET name=?,description=?,card_color=?,head_user_id=? WHERE id=?',[$name,$description,$color,$head,$editId]);
            elseif ($existing) query('UPDATE departments SET is_active=1,description=?,card_color=?,head_user_id=? WHERE id=?',[$description,$color,$head,$existing['id']]);
            else query('INSERT INTO departments (code,name,description,card_color,head_user_id) VALUES (?,?,?,?,?)',['dept_'.bin2hex(random_bytes(8)),$name,$description,$color,$head]);
            flash($record?'Department updated.':'Department added. You can now select it when adding employees.');
        }
        redirect('departments.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$departmentList=rows("SELECT d.id,d.name,d.description,d.card_color,d.head_user_id,head.full_name AS head_name,COUNT(u.id) AS total FROM departments d LEFT JOIN users head ON head.id=d.head_user_id AND head.deleted_at IS NULL LEFT JOIN employee_profiles ep ON ep.department_id=d.id LEFT JOIN users u ON u.id=ep.user_id AND u.deleted_at IS NULL AND u.role='employee' WHERE d.is_active=1 GROUP BY d.id,d.name,d.description,d.card_color,d.head_user_id,head.full_name ORDER BY d.name");
$memberTotal=array_sum(array_map(static fn($d)=>(int)$d['total'],$departmentList));
$headTotal=count(array_filter($departmentList,static fn($d)=>$d['head_name']!==null));
$initials=static function(string $name): string { $parts=preg_split('/\s+/',trim($name)) ?: []; $out=''; foreach (array_slice($parts,0,2) as $p) $out.=mb_strtoupper(mb_substr($p,0,1)); return $out ?: '?'; };
$deptIcon=static function(string $name): string {
    $n=strtolower($name);
    foreach (['design'=>'pen','graphic'=>'pen','video'=>'video','production'=>'video','social'=>'megaphone','ads'=>'megaphone','web'=>'code','software'=>'code','seo'=>'search','content'=>'search','print'=>'printer','tele'=>'phone','call'=>'phone','admin'=>'briefcase','account'=>'briefcase','hr'=>'people'] as $key=>$icon) if (str_contains($n,$key)) return $icon;
    return 'folder';
};
$form=['name'=>$_POST['name']??$record['name']??'','description'=>$_POST['description']??$record['description']??'','card_color'=>$_POST['card_color']??$record['card_color']??'navy','head_user_id'=>(string)($_POST['head_user_id']??$record['head_user_id']??'')];
page_start('Departments','departments.php'); error_message($error);
?>
<div class="dept-page" data-dept-page>
    <header class="dept-header">
        <div><p class="dept-crumb">Dashboard / Departments</p><h2 class="dept-title">Departments</h2><p class="dept-sub"><?= count($departmentList) ?> department<?= count($departmentList)===1?'':'s' ?> · <?= $memberTotal ?> team member<?= $memberTotal===1?'':'s' ?></p></div>
        <div class="dept-tools">
            <label class="dept-search"><?= icon('search') ?><span class="sr-only">Search departments</span><input type="search" placeholder="Search departments…" data-dept-search></label>
            <div class="dept-toggle" role="group" aria-label="View"><button type="button" class="active" data-dept-view="cards">Cards</button><button type="button" data-dept-view="list">List</button></div>
            <a class="dept-btn dept-btn-dark" href="departments.php#dept-form" data-dept-focus>＋ Add department</a>
        </div>
    </header>

    <section class="dept-stats">
        <div class="dept-stat is-primary"><span>Departments</span><strong><?= count($departmentList) ?></strong></div>
        <div class="dept-stat"><span>Team members</span><strong><?= $memberTotal ?></strong></div>
        <div class="dept-stat"><span>Department heads</span><strong><?= $headTotal ?></strong></div>
    </section>

    <div class="dept-layout">
        <div>
            <div class="dept-grid" data-dept-cards>
                <?php if (!$departmentList): ?><p class="muted">No departments yet. Add your first department.</p><?php endif; ?>
                <?php foreach ($departmentList as $d): $color=DEPARTMENT_COLORS[$d['card_color']] ?? DEPARTMENT_COLORS['navy']; $solid=in_array($d['card_color'],['navy','blue'],true); ?>
                <article class="dept-card" data-dept-item data-name="<?= h(strtolower($d['name'].' '.$d['description'].' '.$d['head_name'])) ?>">
                    <div class="dept-card-top">
                        <span class="dept-icon<?= $solid?' is-solid':'' ?>" style="--dept-color:<?= h($color) ?>"><?= icon($deptIcon($d['name'])) ?></span>
                        <div class="dept-card-name"><h3><?= h($d['name']) ?></h3><p><?= h($d['description'] ?: 'No description added yet.') ?></p></div>
                        <details class="dept-menu"><summary aria-label="Department actions"><?= icon('more') ?></summary><div class="dept-menu-list"><a href="departments.php?edit=<?= (int)$d['id'] ?>#dept-form"><?= icon('edit') ?>Edit</a><?php delete_button((int)$d['id'],'delete_department','Delete this department? Existing reports will be retained.'); ?></div></details>
                    </div>
                    <div class="dept-head-box"><span class="dept-initials" style="--dept-color:<?= h($color) ?>"><?= h($d['head_name'] ? $initials($d['head_name']) : '—') ?></span><div><small>Head</small><strong><?= h($d['head_name'] ?? 'Not assigned') ?></strong></div></div>
                    <div class="dept-members"><strong><?= (int)$d['total'] ?></strong><span>Member<?= (int)$d['total']===1?'':'s' ?></span></div>
                    <a class="dept-btn dept-btn-outline" href="admin-employees.php?department=<?= (int)$d['id'] ?>">View team</a>
                </article>
                <?php endforeach; ?>
            </div>
            <div class="dept-list panel" data-dept-list hidden>
                <div class="table-scroll"><table><thead><tr><th>Department</th><th>Head</th><th>Members</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($departmentList as $d): ?><tr data-dept-item data-name="<?= h(strtolower($d['name'].' '.$d['description'].' '.$d['head_name'])) ?>"><td><strong><?= h($d['name']) ?></strong><br><small class="muted"><?= h($d['description']) ?></small></td><td><?= h($d['head_name'] ?? '—') ?></td><td><?= (int)$d['total'] ?></td><td><div class="actions"><a class="button secondary" href="admin-employees.php?department=<?= (int)$d['id'] ?>">View team</a><a class="button secondary" href="departments.php?edit=<?= (int)$d['id'] ?>#dept-form">Edit</a><?php delete_button((int)$d['id'],'delete_department','Delete this department? Existing reports will be retained.'); ?></div></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
            <p class="muted dept-empty" data-dept-empty hidden>No departments match your search.</p>
        </div>

        <aside class="dept-form-card" id="dept-form">
            <div class="dept-form-head"><small><?= $record?'Edit':'Quick add' ?></small><h3><?= $record?'Edit department':'New department' ?></h3></div>
            <form method="post" class="dept-form"><?= csrf_field() ?>
                <label>Department name<input id="name" name="name" maxlength="100" placeholder="e.g. Photography" value="<?= h($form['name']) ?>" required></label>
                <label>Department head<select name="head_user_id"><option value="">Select an employee</option><?php foreach ($headOptions as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (string)$o['id']===$form['head_user_id']?'selected':'' ?>><?= h($o['full_name']) ?><?= $o['role']!=='employee'?' ('.h(ucfirst($o['role'])).')':'' ?></option><?php endforeach; ?></select></label>
                <label>Description<textarea name="description" maxlength="255" rows="3" placeholder="What does this team handle?"><?= h($form['description']) ?></textarea></label>
                <fieldset class="dept-colors"><legend>Card colour</legend><?php foreach (DEPARTMENT_COLORS as $key=>$hex): ?><label title="<?= h(ucfirst($key)) ?>"><input type="radio" name="card_color" value="<?= h($key) ?>" <?= $form['card_color']===$key?'checked':'' ?>><span style="background:<?= h($hex) ?>"></span><b class="sr-only"><?= h(ucfirst($key)) ?></b></label><?php endforeach; ?></fieldset>
                <div class="dept-form-actions"><button type="submit" class="dept-btn dept-btn-gold"><?= $record?'Save changes':'Create department' ?></button><a class="dept-btn dept-btn-outline" href="departments.php">Cancel</a></div>
            </form>
        </aside>
    </div>
</div>
<script>
(function () {
    var page = document.querySelector('[data-dept-page]'); if (!page) return;
    var cards = page.querySelector('[data-dept-cards]'), list = page.querySelector('[data-dept-list]'), empty = page.querySelector('[data-dept-empty]'), search = page.querySelector('[data-dept-search]');
    var view = 'cards';
    try { view = localStorage.getItem('deptView') || 'cards'; } catch (e) {}
    function apply() {
        cards.hidden = view !== 'cards'; list.hidden = view !== 'list';
        page.querySelectorAll('[data-dept-view]').forEach(function (b) { b.classList.toggle('active', b.dataset.deptView === view); });
        var q = search.value.trim().toLowerCase(), shown = 0;
        (view === 'cards' ? cards : list).querySelectorAll('[data-dept-item]').forEach(function (el) { var ok = !q || el.dataset.name.indexOf(q) !== -1; el.hidden = !ok; if (ok) shown++; });
        empty.hidden = shown !== 0 || !q;
    }
    page.querySelectorAll('[data-dept-view]').forEach(function (b) { b.addEventListener('click', function () { view = b.dataset.deptView; try { localStorage.setItem('deptView', view); } catch (e) {} apply(); }); });
    search.addEventListener('input', apply);
    page.querySelector('[data-dept-focus]').addEventListener('click', function (e) { e.preventDefault(); document.getElementById('dept-form').scrollIntoView({behavior: 'smooth', block: 'start'}); document.getElementById('name').focus({preventScroll: true}); });
    apply();
})();
</script>
<?php page_end();
