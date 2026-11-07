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
            $columns[]=$group!==''?($group.' — '.$header):($header!==''?$header:(string)($groups[$index]??'Column '.($index+1)));
        }
    }
    $lines=['Report: '.$toAscii(str_replace(['-','_'],' ',$filename)),'Generated: '.date('d-m-Y H:i')];
    foreach ($records as $record) {
        $lines[]='';
        foreach (array_values($record) as $index=>$value) {
            $text=$toAscii((string)($columns[$index]??('Column '.($index+1))).': '.$toAscii($value));
            foreach (explode("\n",wordwrap($text,92,"\n",true)) as $line) $lines[]=$line;
        }
    }
    $pages=array_chunk($lines,48); if (!$pages) $pages=[[]];
    $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>']; $pageRefs=[];
    foreach ($pages as $index=>$pageLines) {
        $pageObject=4+($index*2); $contentObject=$pageObject+1; $pageRefs[]=$pageObject.' 0 R';
        $content="BT\n/F1 9 Tf\n48 790 Td\n13 TL\n".'(Bhavi report | Page '.($index+1).' of '.count($pages).") Tj\nT*\n";
        foreach ($pageLines as $line) $content.='('.$escape($toAscii($line)).") Tj\nT*\n";
        $content.='ET';
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
