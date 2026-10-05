<?php
// Private document storage. No public upload URLs are created.
function employee_document_uploads(): array
{
    $files=$_FILES['documents']??null; $result=[];
    if (!$files) return $result;
    if (!is_array($files['error']??null) || count($files['error'])>10) throw new InvalidArgumentException('Upload up to 10 documents at a time.');
    $total=0;
    foreach ($files['error'] as $index=>$error) {
        if ($error===UPLOAD_ERR_NO_FILE) continue;
        $path=$files['tmp_name'][$index]??'';
        if ($error!==UPLOAD_ERR_OK || !is_uploaded_file($path)) throw new InvalidArgumentException('Document upload failed. Please choose the files again.');
        $size=filesize($path); $total+=$size;
        if ($size>2*1024*1024 || $total>6*1024*1024) throw new InvalidArgumentException('Each document must be under 2 MB and the upload total under 6 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime!=='application/pdf' && (!str_starts_with($mime,'image/') || $mime==='image/svg+xml')) throw new InvalidArgumentException('Upload PDF or image documents. SVG files are not supported.');
        $name=basename(str_replace('\\','/',(string)$files['name'][$index]));
        if (strlen($name)>255) throw new InvalidArgumentException('Document filename is too long.');
        $result[]=['name'=>$name,'mime'=>$mime,'content'=>file_get_contents($path)];
    }
    return $result;
}
