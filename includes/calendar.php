<?php
function calendar_month(): string
{
    $month = $_GET['month'] ?? date('Y-m');
    if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/D',$month) || !DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01') || DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01')->format('Y-m')!==$month) { fail(422,'Choose a valid month.'); }
    return $month;
}
function render_calendar(string $month, array $events): void
{
    $first=new DateTimeImmutable($month.'-01');
    ?><div class="panel"><div class="panel-heading"><h2><?= h($first->format('F Y')) ?></h2><form class="filters" method="get" style="margin:0"><div class="field"><label for="month">Month</label><input type="month" id="month" name="month" value="<?= h($month) ?>" required></div><button class="primary" type="submit">View</button></form></div><div class="calendar"><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?><div class="calendar-head"><?= $day ?></div><?php endforeach;
    for ($i=1;$i<(int)$first->format('N');$i++) echo '<div></div>';
    for ($day=1;$day<=(int)$first->format('t');$day++): $date=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT); ?><div class="calendar-day <?= $date===today()?'current':'' ?>"><strong><?= $day ?></strong><?php foreach ($events[$date] ?? [] as $event): ?><div><span class="badge <?= h($event['class']) ?>"><?= h($event['label']) ?></span></div><?php endforeach; ?></div><?php endfor; ?></div></div><?php
}
