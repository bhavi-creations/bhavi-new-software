<?php
function download_pdf(array $headers, iterable $records, string $filename, array $options=[]): void
{
    $toAscii=static function($value): string {
        $value=(string)($value??'');
        $converted=function_exists('iconv')?iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value):false;
        $value=$converted!==false?$converted:$value;
        return preg_replace('/[^\x20-\x7E\x80-\xFF]/','?', $value) ?? '';
    };
    $escape=static fn(string $value): string=>str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$value);
    $columns=$headers;
    if (is_array($options['grouped_headers']??null)) {
        $groups=$options['grouped_headers']; $columns=[];
        foreach ($headers as $index=>$header) {
            $group=(string)($groups[$index]??'');
            $columns[]=$group!==''?($group.' - '.$header):($header!==''?$header:(string)($groups[$index]??'Column '.($index+1)));
        }
    }
    $pages=[]; $commands=[]; $y=0; $rowNumber=0;
    $text=static fn(string $value,float $x,float $baseline,int $size=9,string $color='0 0 0'): string=>sprintf("%s rg BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET",$color,$size,$x,$baseline,$escape($toAscii($value)));
    $reportTitle='Report: '.$toAscii(str_replace(['-','_'],' ',$filename));
    $startPage=static function() use (&$pages,&$commands,&$y,$text,$reportTitle): void {
        if ($commands) $pages[]=$commands;
        $commands=[];
        $commands[]=$text($reportTitle,32,815,13,'0 0 0');
        $commands[]=$text('Generated: '.date('d-m-Y H:i'),32,798,8,'0.35 0.42 0.38');
        $x=32; $top=778; $height=24; $width=531; $split=170;
        $commands[]='0 0.42 0.29 rg'; $commands[]=$x.' '.($top-$height).' '.$width.' '.$height.' re f';
        $commands[]='0.45 0.60 0.52 RG 0.7 w'; $commands[]=$x.' '.($top-$height).' '.$width.' '.$height.' re S';
        $commands[]=$text('Field',$x+7,$top-16,9,'1 1 1'); $commands[]=$text('Details',$x+$split+7,$top-16,9,'1 1 1');
        $commands[]=($x+$split).' '.($top-$height).' m '.($x+$split).' '.$top.' l S';
        $y=$top-$height;
    };
    $drawRow=static function(string $label,string $value) use (&$commands,&$y,$startPage,$text): void {
        $labelLines=explode("\n",wordwrap($label,26,"\n",true));
        $valueLines=explode("\n",wordwrap($value,72,"\n",true));
        $chunks=array_chunk($valueLines,54); if (!$chunks) $chunks=[['']];
        foreach ($chunks as $chunkIndex=>$chunk) {
            $shownLabel=$chunkIndex===0?$labelLines:['(continued)'];
            $lineCount=max(count($shownLabel),count($chunk));
            $height=max(22,$lineCount*11+8);
            if ($y-$height<42) $startPage();
            $x=32; $width=531; $split=170; $bottom=$y-$height;
            $commands[]=$chunkIndex%2===0?'0.97 0.985 0.975 rg':'1 1 1 rg';
            $commands[]=$x.' '.$bottom.' '.$width.' '.$height.' re f';
            $commands[]='0.72 0.79 0.74 RG 0.55 w '.$x.' '.$bottom.' '.$width.' '.$height.' re S';
            $commands[]=($x+$split).' '.$bottom.' m '.($x+$split).' '.$y.' l S';
            foreach ($shownLabel as $lineIndex=>$line) $commands[]=$text($line,$x+7,$y-13-($lineIndex*11),8,'0.12 0.23 0.18');
            foreach ($chunk as $lineIndex=>$line) $commands[]=$text($line,$x+$split+7,$y-13-($lineIndex*11),8,'0.10 0.16 0.13');
            $y=$bottom;
        }
    };
    $startPage();
    foreach ($records as $record) {
        $rowNumber++;
        $drawRow('Report item '.$rowNumber,'');
        foreach (array_values($record) as $index=>$value) $drawRow((string)($columns[$index]??('Column '.($index+1))),$toAscii($value));
    }
    if ($rowNumber===0) $drawRow('Status','No records in this report period.');
    if ($commands) $pages[]=$commands;
    $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>']; $pageRefs=[];
    foreach ($pages as $index=>$pageCommands) {
        $pageObject=4+($index*2); $contentObject=$pageObject+1; $pageRefs[]=$pageObject.' 0 R';
        $content="q\n".implode("\n",$pageCommands)."\n".$text('Page '.($index+1).' of '.count($pages),510,24,8,'0.35 0.42 0.38')."\nQ";
        $objects[$pageObject]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentObject.' 0 R >>';
        $objects[$contentObject]='<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
    }
    $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$pageRefs).'] /Count '.count($pageRefs).' >>';
    $objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    ksort($objects); $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets=[0];
    foreach ($objects as $number=>$object) { $offsets[$number]=strlen($pdf); $pdf.=$number." 0 obj\n".$object."\nendobj\n"; }
    $xref=strlen($pdf); $size=max(array_keys($objects))+1; $pdf.="xref\n0 ".$size."\n0000000000 65535 f \n";
    for ($number=1;$number<$size;$number++) $pdf.=sprintf('%010d 00000 n ', $offsets[$number]??0)."\n";
    $pdf.="trailer\n<< /Size ".$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','-', $filename).'.pdf"');
    header('Content-Length: '.strlen($pdf)); echo $pdf;
}
