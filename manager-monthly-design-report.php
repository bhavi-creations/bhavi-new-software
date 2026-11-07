<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/monthly-design-report.php';
require_roles(['admin','manager']);
try { $report=monthly_design_report(); } catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
$standalone=($_GET['format']??'')==='print';
if ($standalone) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="design-work-report-'.$report['from'].'-to-'.$report['to'].'.html"');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Design work report</title></head><body>';
} else page_start('Design work report','manager-monthly-design-report.php');
require __DIR__.'/includes/design-report-view.php';
if ($standalone) echo '<script>window.addEventListener("load",()=>window.print());</script></body></html>'; else page_end();
