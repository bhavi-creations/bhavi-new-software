<?php
// Currency is validated as decimal text and calculated in integer paise.
function money_cents($value): int {
    if (!is_string($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$value)) throw new InvalidArgumentException('Enter a non-negative amount with at most two decimal places.');
    [$whole,$fraction]=array_pad(explode('.',$value),2,'');
    return (int)$whole*100+(int)str_pad($fraction,2,'0');
}
function decimal_money(int $cents): string { return sprintf('%d.%02d',intdiv($cents,100),$cents%100); }
function money_label($value): string { return 'INR '.number_format((float)$value,2); }
/** ₹ amount in Indian grouping, e.g. 150000 → ₹1,50,000 (paise shown only when not zero). */
function rupees_label($value): string {
    $cents=(int)round(abs((float)$value)*100); $whole=(string)intdiv($cents,100); $paise=$cents%100;
    if (strlen($whole)>3) $whole=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',substr($whole,0,-3)).','.substr($whole,-3);
    return ((float)$value<0?'-':'').'₹'.$whole.($paise?sprintf('.%02d',$paise):'');
}
function optional_text(string $key,int $max=255): string { return isset($_POST[$key])?text_input($key,$max,false):''; }
function optional_date(string $key): ?string { return empty($_POST[$key])?null:date_input($key); }
function benefit_label(array $record): string {
    $items=[]; foreach (['pf'=>'PF','esi'=>'ESI','other'=>'Other'] as $key=>$label) if (!empty($record['benefit_'.$key])) $items[]=$label;
    return implode(', ',$items)?:'None';
}
function other_benefit_input(bool $enabled): array {
    if (!$enabled) return ['benefit_other'=>0,'other_benefits'=>'','other_benefit_amount'=>'0.00'];
    $amount=money_cents(($_POST['other_benefit_amount']??'')===''?'0':$_POST['other_benefit_amount']);
    $reason=optional_text('other_benefits');
    if ($amount>0 && $reason==='') throw new InvalidArgumentException('Enter the reason for adding benefits.');
    return ['benefit_other'=>1,'other_benefits'=>$reason,'other_benefit_amount'=>decimal_money($amount)];
}
function salary_net_amount(int $gross,int $pf,int $esi,int $benefits=0): string {
    $net=$gross-$pf-$esi+$benefits;
    if ($net<0 || $net>999999999999) throw new InvalidArgumentException('Salary including benefits must be between INR 0 and INR 9,999,999,999.99.');
    return decimal_money($net);
}
function salary_breakdown(int $gross,bool $pf,bool $esi,string $basis,int $benefits=0): array {
    // PF and ESI are taken on the full monthly salary ($basis is kept only for older callers).
    $esiBase=$gross;
    $employeePf=$pf?(int)round($gross*12/100):0;
    $employeeEsi=$esi?(int)round($esiBase*75/10000):0;
    return array_merge(array_map('decimal_money',['pf_base'=>$gross,'esi_base'=>$esiBase,'employee_pf'=>$employeePf,'company_pf'=>$employeePf,'employee_esi'=>$employeeEsi,'company_esi'=>$esi?(int)round($esiBase*325/10000):0]),['net_salary'=>salary_net_amount($gross,$employeePf,$employeeEsi,$benefits)]);
}
function salary_rows_input(?string $initialStart = null): array {
    $rows=$_POST['salary']??[];
    if (!is_array($rows) || count($rows)>100) throw new InvalidArgumentException('Add up to 100 salary periods at a time.');
    $result=[];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Invalid salary entry.');
        if (($row['amount']??'')==='' && ($row['from']??'')==='' && ($row['to']??'')==='') continue;
        $amount=money_cents($row['amount']??''); if (!$amount) throw new InvalidArgumentException('Salary must be greater than zero.');
        if (($row['from']??'')==='' && !$result && $initialStart!==null) $row['from']=$initialStart;
        try { $from=date_input('from',$row); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException('Choose a valid salary start date for salary entry '.(count($result)+1).'.'); }
        try { $to=empty($row['to'])?null:date_input('to',$row); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException('Choose a valid salary end date for salary entry '.(count($result)+1).'.'); }
        if ($to && $to<$from) throw new InvalidArgumentException('Salary end date must be on or after its start date.');
        $result[]=['amount'=>decimal_money($amount),'effective_from'=>$from,'effective_to'=>$to];
    }
    usort($result,static fn($a,$b)=>strcmp($a['effective_from'],$b['effective_from']));
    return $result;
}
function save_salary_rows(int $id,array $entries,array $benefits,string $joining,?string $relieving,int $actor): void {
    foreach ($entries as $entry) {
        if ($entry['effective_from']<$joining || ($relieving && ($entry['effective_from']>$relieving || ($entry['effective_to'] && $entry['effective_to']>$relieving)))) throw new InvalidArgumentException('Salary dates must fall within employment dates.');
        // A salary / increment may also be added with an older date (between saved periods, e.g. past increments).
        $from=$entry['effective_from'];
        $dayBefore=static fn(string $d): string => (new DateTimeImmutable($d))->modify('-1 day')->format('Y-m-d');
        if (one('SELECT id FROM employee_salary_history WHERE employee_id=? AND effective_from=? FOR UPDATE',[$id,$from])) throw new InvalidArgumentException('A salary already starts on '.date('d-m-Y',strtotime($from)).'. Choose a different start date.');
        $before=one('SELECT * FROM employee_salary_history WHERE employee_id=? AND effective_from<? ORDER BY effective_from DESC LIMIT 1 FOR UPDATE',[$id,$from]);
        $after=one('SELECT * FROM employee_salary_history WHERE employee_id=? AND effective_from>? ORDER BY effective_from LIMIT 1 FOR UPDATE',[$id,$from]);
        // The period before the new one now ends the day before it starts.
        if ($before && (!$before['effective_to'] || $before['effective_to']>=$from)) query('UPDATE employee_salary_history SET effective_to=? WHERE id=?',[$dayBefore($from),$before['id']]);
        // A new period placed before a saved one runs until that saved period starts.
        if ($after) {
            $limit=$dayBefore($after['effective_from']);
            if ($entry['effective_to'] && $entry['effective_to']>$limit) throw new InvalidArgumentException('Salary from '.date('d-m-Y',strtotime($from)).' must end before the next saved salary starts on '.date('d-m-Y',strtotime($after['effective_from'])).'.');
            if (!$entry['effective_to']) $entry['effective_to']=$limit;
        }
        $calculation=salary_breakdown(money_cents($entry['amount']),(bool)$benefits['benefit_pf'],(bool)$benefits['benefit_esi'],$benefits['esi_basis'],money_cents($benefits['other_benefit_amount']));
        $data=array_merge(['employee_id'=>$id],$entry,$benefits,$calculation,['created_by'=>$actor]);
        query('INSERT INTO employee_salary_history ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));
    }
}
function salary_summary(array $row): void {
    $deductions=(float)$row['employee_pf']+(float)$row['employee_esi']; ?>
<div class="money-summary"><div class="money-card"><span>Monthly salary</span><strong><?= h(money_label($row['amount'])) ?></strong></div><div class="money-card"><span>Deducted from salary</span><strong><?= h(money_label($deductions)) ?></strong></div><div class="money-card highlight"><span>Employee receives</span><strong><?= h(money_label($row['net_salary'])) ?></strong></div></div>
<?php }
function salary_benefit_note(array $row): void {
    $amount=money_cents($row['other_benefit_amount']??'0.00');
    if (!$amount) return; ?>
<aside class="benefits-note" aria-label="Benefits note"><h3>Benefits note</h3><p><strong><?= h(money_label($row['other_benefit_amount'])) ?></strong> added to your monthly salary.</p><p><strong>Reason:</strong> <?= h($row['other_benefits']) ?></p></aside>
<?php }
function salary_details(array $row): void { ?>
<div class="contribution-grid"><div class="contribution-box"><h3>Deducted from employee salary</h3><dl class="simple-amounts"><dt>PF</dt><dd><?= h(money_label($row['employee_pf'])) ?></dd><dt>ESI</dt><dd><?= h(money_label($row['employee_esi'])) ?></dd><dt>Total deduction</dt><dd><?= h(money_label((float)$row['employee_pf']+(float)$row['employee_esi'])) ?></dd></dl></div>
<div class="contribution-box"><h3>Company share</h3><dl class="simple-amounts"><dt>PF</dt><dd><?= h(money_label(0)) ?></dd><dt>ESI</dt><dd><?= h(money_label(0)) ?></dd></dl><p class="help">The company does not pay any PF / ESI. It is cut only from the employee salary.</p></div></div>
<p class="help">PF = 12% of <?= h(money_label($row['pf_base'])) ?> and ESI = 0.75% of <?= h(money_label($row['esi_base'])) ?> — cut only from the employee salary. The company pays nothing. Only selected benefits apply.</p>
<?php }
/**
 * Clear salary & increment history: a highlight of the last increment, a summary,
 * and a timeline card for every salary change (date it started, old → new, increase in ₹ and %).
 */
function salary_increment_timeline(array $history): void {
    if (!$history) { echo '<p class="muted">No salary saved yet.</p>'; return; }
    $items=[]; $previous=null; $increments=0;
    foreach ($history as $row) {
        $amount=(float)$row['amount']; $change=$previous===null?null:$amount-$previous;
        if ($change!==null && $change>0) $increments++;
        $items[]=['row'=>$row,'amount'=>$amount,'previous'=>$previous,'change'=>$change];
        $previous=$amount;
    }
    $first=$items[0]; $latest=end($items); $lastIncrement=null;
    foreach ($items as $item) if ($item['change']!==null && $item['change']>0) $lastIncrement=$item;
    $date=static fn(string $d): string => date('d M Y',strtotime($d));
    $percent=static fn(float $change,float $base): string => $base>0 ? rtrim(rtrim(number_format(abs($change)/$base*100,1),'0'),'.').'%' : '';
    $since=static function (string $from): string {
        $diff=(new DateTimeImmutable($from))->diff(new DateTimeImmutable(date('Y-m-d')));
        if ($diff->invert) return 'starts soon';
        $parts=array_filter([$diff->y?$diff->y.' year'.($diff->y>1?'s':''):'',$diff->m?$diff->m.' month'.($diff->m>1?'s':''):'']);
        return $parts?implode(' ',$parts).' ago':'this month';
    }; ?>
<?php if ($lastIncrement): ?>
<div class="inc-highlight">
    <span class="inc-highlight-icon" aria-hidden="true">▲</span>
    <div>
        <p class="inc-highlight-label">Last increment · <?= h($since($lastIncrement['row']['effective_from'])) ?></p>
        <p class="inc-highlight-main">On <strong><?= h($date($lastIncrement['row']['effective_from'])) ?></strong> salary increased from <strong><?= h(rupees_label($lastIncrement['previous'])) ?></strong> to <strong><?= h(rupees_label($lastIncrement['amount'])) ?></strong></p>
        <p class="inc-highlight-sub">Increment <b>+<?= h(rupees_label($lastIncrement['change'])) ?></b> a month (<?= h($percent($lastIncrement['change'],$lastIncrement['previous'])) ?>)</p>
    </div>
</div>
<?php else: ?>
<div class="inc-highlight is-none"><span class="inc-highlight-icon" aria-hidden="true">●</span><div><p class="inc-highlight-label">No increment yet</p><p class="inc-highlight-main">Salary is <strong><?= h(rupees_label($latest['amount'])) ?></strong> a month from <strong><?= h($date($first['row']['effective_from'])) ?></strong> (joining salary).</p></div></div>
<?php endif; ?>
<div class="inc-summary">
    <div><span>Joining salary</span><strong><?= h(rupees_label($first['amount'])) ?></strong><small>from <?= h($date($first['row']['effective_from'])) ?></small></div>
    <div class="is-now"><span>Current salary</span><strong><?= h(rupees_label($latest['amount'])) ?></strong><small>from <?= h($date($latest['row']['effective_from'])) ?></small></div>
    <div><span>Increments given</span><strong><?= $increments ?> time<?= $increments===1?'':'s' ?></strong><small><?= $increments ? 'Total +'.h(rupees_label($latest['amount']-$first['amount'])).' ('.h($percent($latest['amount']-$first['amount'],$first['amount'])).')' : 'No increment yet' ?></small></div>
</div>
<ol class="inc-timeline">
<?php foreach (array_reverse($items) as $item): $row=$item['row']; $change=$item['change'];
    $type=$change===null?'Joining salary':($change>0?'Increment':($change<0?'Salary decreased':'Salary revised'));
    $cls=$change===null?'is-start':($change>0?'is-up':($change<0?'is-down':'is-same')); ?>
<li class="inc-step <?= $cls ?><?= $row['effective_to'] ? '' : ' is-current' ?>">
    <div class="inc-step-date"><strong><?= h(date('d M',strtotime($row['effective_from']))) ?></strong><span><?= h(date('Y',strtotime($row['effective_from']))) ?></span></div>
    <div class="inc-step-body">
        <div class="inc-step-top"><span class="inc-chip <?= $cls ?>"><?= h($type) ?></span><?php if (!$row['effective_to']): ?><span class="inc-current">Current salary</span><?php endif; ?></div>
        <?php if ($change===null): ?>
        <p class="inc-step-amount"><strong><?= h(rupees_label($item['amount'])) ?></strong> <small>a month</small></p>
        <?php else: ?>
        <p class="inc-step-amount"><span class="inc-old"><?= h(rupees_label($item['previous'])) ?></span> <span class="inc-arrow" aria-label="to">→</span> <strong><?= h(rupees_label($item['amount'])) ?></strong> <small>a month</small></p>
        <?php if ($change!=0): ?><p class="inc-change"><?= $change>0?'+':'−' ?><?= h(rupees_label(abs($change))) ?> <small>(<?= $change>0?'+':'−' ?><?= h($percent($change,$item['previous'])) ?>)</small></p><?php endif; ?>
        <?php endif; ?>
        <p class="inc-period"><?= h($date($row['effective_from'])) ?> → <?= $row['effective_to'] ? h($date($row['effective_to'])) : 'till now' ?></p>
    </div>
</li>
<?php endforeach; ?>
</ol>
<?php }
function salary_table(array $history): void {
    if (!$history) { echo '<p class="muted">No salary history saved yet.</p>'; return; }
    $previous=null;
    foreach ($history as $row): ?>
<article class="salary-history-item"><div class="history-heading"><h3><?= h($row['effective_from']) ?> <span class="muted">to</span> <?= h($row['effective_to']?:'Ongoing') ?></h3><?php if ($previous!==null): $diff=(float)$row['amount']-$previous; ?><span class="badge"><?= $diff>0?'Increment':($diff<0?'Decrease':'Salary revised') ?> on <?= h(date('d M Y',strtotime($row['effective_from']))) ?>: <?= $diff>0?'+':'' ?><?= h(money_label($diff)) ?> (<?= h(money_label($previous)) ?> → <?= h(money_label($row['amount'])) ?>)</span><?php else: ?><span class="badge">Joining salary</span><?php endif; ?></div>
<?php salary_summary($row); salary_benefit_note($row); ?><details class="simple-details"><summary>View PF, ESI & benefit details</summary><p>Benefits: <?= h(benefit_label($row)) ?><?= !empty($row['benefit_other'])?' · '.h($row['other_benefits']):'' ?></p><?php salary_details($row); ?></details></article>
<?php $previous=(float)$row['amount']; endforeach; ?>
<p class="help">Employee receives = monthly salary + benefits amount − employee PF − employee ESI. Other deductions and attendance adjustments are not included.</p>
<?php }
