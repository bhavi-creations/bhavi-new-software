<?php
// Currency is validated as decimal text and calculated in integer paise.
function money_cents($value): int {
    if (!is_string($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$value)) throw new InvalidArgumentException('Enter a non-negative amount with at most two decimal places.');
    [$whole,$fraction]=array_pad(explode('.',$value),2,'');
    return (int)$whole*100+(int)str_pad($fraction,2,'0');
}
function decimal_money(int $cents): string { return sprintf('%d.%02d',intdiv($cents,100),$cents%100); }
function money_label($value): string { return 'INR '.number_format((float)$value,2); }
function optional_text(string $key,int $max=255): string { return isset($_POST[$key])?text_input($key,$max,false):''; }
function optional_date(string $key): ?string { return empty($_POST[$key])?null:date_input($key); }
function benefit_label(array $record): string {
    $items=[]; foreach (['pf'=>'PF','esi'=>'ESI','other'=>'Other'] as $key=>$label) if (!empty($record['benefit_'.$key])) $items[]=$label;
    return implode(', ',$items)?:'None';
}
function salary_breakdown(int $gross,bool $pf,bool $esi,string $basis): array {
    $half=intdiv($gross+1,2); $esiBase=$basis==='half'?$half:$gross;
    $employeePf=$pf?(int)round($half*12/100):0;
    $employeeEsi=$esi?(int)round($esiBase*75/10000):0;
    return array_map('decimal_money',['pf_base'=>$half,'esi_base'=>$esiBase,'employee_pf'=>$employeePf,'company_pf'=>$employeePf,'employee_esi'=>$employeeEsi,'company_esi'=>$esi?(int)round($esiBase*325/10000):0,'net_salary'=>$gross-$employeePf-$employeeEsi]);
}
function salary_rows_input(): array {
    $rows=$_POST['salary']??[];
    if (!is_array($rows) || count($rows)>100) throw new InvalidArgumentException('Add up to 100 salary periods at a time.');
    $result=[];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Invalid salary entry.');
        if (($row['amount']??'')==='' && ($row['from']??'')==='' && ($row['to']??'')==='') continue;
        $amount=money_cents($row['amount']??''); if (!$amount) throw new InvalidArgumentException('Salary must be greater than zero.');
        $from=date_input('from',$row); $to=empty($row['to'])?null:date_input('to',$row);
        if ($to && $to<$from) throw new InvalidArgumentException('Salary end date must be on or after its start date.');
        $result[]=['amount'=>decimal_money($amount),'effective_from'=>$from,'effective_to'=>$to];
    }
    usort($result,static fn($a,$b)=>strcmp($a['effective_from'],$b['effective_from']));
    return $result;
}
function save_salary_rows(int $id,array $entries,array $benefits,string $joining,?string $relieving,int $actor): void {
    foreach ($entries as $entry) {
        if ($entry['effective_from']<$joining || ($relieving && ($entry['effective_from']>$relieving || ($entry['effective_to'] && $entry['effective_to']>$relieving)))) throw new InvalidArgumentException('Salary dates must fall within employment dates.');
        $last=one('SELECT * FROM employee_salary_history WHERE employee_id=? ORDER BY effective_from DESC LIMIT 1 FOR UPDATE',[$id]);
        if ($last && ($entry['effective_from']<=$last['effective_from'] || ($last['effective_to'] && $entry['effective_from']<=$last['effective_to']))) throw new InvalidArgumentException('New salary periods must start after the saved history, without overlaps.');
        if ($last && !$last['effective_to']) query('UPDATE employee_salary_history SET effective_to=? WHERE id=?',[(new DateTimeImmutable($entry['effective_from']))->modify('-1 day')->format('Y-m-d'),$last['id']]);
        $calculation=salary_breakdown(money_cents($entry['amount']),(bool)$benefits['benefit_pf'],(bool)$benefits['benefit_esi'],$benefits['esi_basis']);
        $data=array_merge(['employee_id'=>$id],$entry,$benefits,$calculation,['created_by'=>$actor]);
        query('INSERT INTO employee_salary_history ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));
    }
}
function salary_summary(array $row): void {
    $deductions=(float)$row['employee_pf']+(float)$row['employee_esi']; ?>
<div class="money-summary"><div class="money-card"><span>Monthly salary</span><strong><?= h(money_label($row['amount'])) ?></strong></div><div class="money-card"><span>Deducted from salary</span><strong><?= h(money_label($deductions)) ?></strong></div><div class="money-card highlight"><span>Employee receives</span><strong><?= h(money_label($row['net_salary'])) ?></strong></div></div>
<?php }
function salary_details(array $row): void { ?>
<div class="contribution-grid"><div class="contribution-box"><h3>Deducted from employee salary</h3><dl class="simple-amounts"><dt>PF</dt><dd><?= h(money_label($row['employee_pf'])) ?></dd><dt>ESI</dt><dd><?= h(money_label($row['employee_esi'])) ?></dd><dt>Total deduction</dt><dd><?= h(money_label((float)$row['employee_pf']+(float)$row['employee_esi'])) ?></dd></dl></div>
<div class="contribution-box"><h3>Company pays separately</h3><dl class="simple-amounts"><dt>PF</dt><dd><?= h(money_label($row['company_pf'])) ?></dd><dt>ESI</dt><dd><?= h(money_label($row['company_esi'])) ?></dd><dt>Total company contribution</dt><dd><?= h(money_label((float)$row['company_pf']+(float)$row['company_esi'])) ?></dd></dl><p class="help">This is not deducted from employee salary.</p></div></div>
<p class="help">PF uses <?= h(money_label($row['pf_base'])) ?>: employee 12%, company 12%. ESI uses <?= h(money_label($row['esi_base'])) ?>: employee 0.75%, company 3.25%. Only selected benefits apply.</p>
<?php }
function salary_table(array $history): void {
    if (!$history) { echo '<p class="muted">No salary history saved yet.</p>'; return; }
    $previous=null;
    foreach ($history as $row): ?>
<article class="salary-history-item"><div class="history-heading"><h3><?= h($row['effective_from']) ?> <span class="muted">to</span> <?= h($row['effective_to']?:'Ongoing') ?></h3><?php if ($previous!==null): ?><span class="badge">Salary change: <?= h(money_label((float)$row['amount']-$previous)) ?></span><?php endif; ?></div>
<?php salary_summary($row); ?><details class="simple-details"><summary>View PF, ESI & benefit details</summary><p>Benefits: <?= h(benefit_label($row)) ?><?= !empty($row['benefit_other'])?' · '.h($row['other_benefits']):'' ?></p><?php salary_details($row); ?></details></article>
<?php $previous=(float)$row['amount']; endforeach; ?>
<p class="help">Employee receives = monthly salary − employee PF − employee ESI. Other deductions and attendance adjustments are not included.</p>
<?php }
