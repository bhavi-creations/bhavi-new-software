<?php
function download_csv(array $headers, iterable $records, string $filename): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
    $output=fopen('php://output','wb');
    if ($output===false) throw new RuntimeException('Unable to create report download.');
    fwrite($output,"\xEF\xBB\xBF");
    $writeRow=static function(array $row) use ($output): void {
        $safe=array_map(static function($value): string {
            $value=(string)($value??'');
            if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u',$value)) $value="'".$value;
            return $value;
        },$row);
        if (fputcsv($output,$safe,',','"','')===false) throw new RuntimeException('Unable to write report download.');
    };
    try {
        $writeRow($headers);
        foreach ($records as $record) $writeRow($record);
    } finally {
        fclose($output);
    }
}

// Minimal XLSX workbook: inline strings preserve employee-entered text as text.
function download_excel(array $headers, iterable $records, string $filename): void
{
    if (!class_exists('ZipArchive')) {
        download_csv($headers,$records,$filename);
        return;
    }
    $escape=static fn($value)=>htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',(string)($value??'')),ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $sheet=fopen('php://temp/maxmemory:2097152','w+');
    fwrite($sheet,'<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
    $rowNumber=0;
    $writeRow=static function(array $row) use ($sheet,$escape,&$rowNumber): void {
        $rowNumber++; fwrite($sheet,'<row r="'.$rowNumber.'">');
        foreach (array_values($row) as $index=>$value) {
            $column=''; for ($n=$index+1;$n>0;$n=intdiv($n-1,26)) $column=chr(65+(($n-1)%26)).$column;
            fwrite($sheet,'<c r="'.$column.$rowNumber.'" t="inlineStr"><is><t xml:space="preserve">'.$escape($value).'</t></is></c>');
        }
        fwrite($sheet,'</row>');
    };
    $writeRow($headers); foreach ($records as $record) $writeRow($record);
    fwrite($sheet,'</sheetData></worksheet>'); rewind($sheet);
    $path=tempnam(sys_get_temp_dir(),'bhavi_excel_');
    try {
        $zip=new ZipArchive();
        if ($zip->open($path,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Unable to create Excel report.');
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Daily work" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml',stream_get_contents($sheet));
        $zip->close();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
        header('Content-Length: '.filesize($path)); readfile($path);
    } finally { fclose($sheet); if (is_file($path)) unlink($path); }
}
