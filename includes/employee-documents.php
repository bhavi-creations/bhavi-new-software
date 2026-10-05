<?php
// Private document storage. No public upload URLs are created.
function employee_document_mime(string $path): ?string
{
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($mime==='application/pdf' || (is_string($mime) && str_starts_with($mime,'image/'))) return $mime;
    // Older Fileinfo databases do not recognize some phone and camera formats.
    $image=@getimagesize($path);
    if ($image && isset($image['mime'])) return $image['mime'];
    $head=file_get_contents($path,false,null,0,4096);
    if ($head===false) return null;
    if (substr($head,4,4)==='ftyp') {
        $boxSize=unpack('Nsize',substr($head,0,4))['size'];
        $brands=[substr($head,8,4)];
        for ($offset=16;$offset+4<=min($boxSize,strlen($head));$offset+=4) $brands[]=substr($head,$offset,4);
        foreach (['avif'=>'image/avif','avis'=>'image/avif','heic'=>'image/heic','heix'=>'image/heic','hevc'=>'image/heic','hevx'=>'image/heic','mif1'=>'image/heif','msf1'=>'image/heif'] as $brand=>$type) if (in_array($brand,$brands,true)) return $type;
    }
    // SVG may be detected as XML or plain text; verify its document element.
    if (in_array($mime,['text/xml','application/xml','text/plain'],true)) {
        $previous=libxml_use_internal_errors(true);
        try {
            $xml=new DOMDocument();
            if ($xml->load($path,LIBXML_NONET) && !$xml->doctype && $xml->documentElement && $xml->documentElement->localName==='svg' && $xml->documentElement->namespaceURI==='http://www.w3.org/2000/svg') return 'image/svg+xml';
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }
    return null;
}
function employee_document_uploads(): array
{
    $files=$_FILES['documents']??null; $result=[];
    if (!$files) return $result;
    if (!is_array($files['error']??null) || count($files['error'])>20) throw new InvalidArgumentException('Upload up to 20 documents at a time.');
    $total=0;
    foreach ($files['error'] as $index=>$error) {
        if ($error===UPLOAD_ERR_NO_FILE) continue;
        $path=$files['tmp_name'][$index]??'';
        if ($error===UPLOAD_ERR_INI_SIZE || $error===UPLOAD_ERR_FORM_SIZE) throw new InvalidArgumentException('This file exceeds the server upload limit. Maximum document size is 20 MB.');
        if ($error!==UPLOAD_ERR_OK || !is_uploaded_file($path)) {
            $message=match($error) {
                UPLOAD_ERR_PARTIAL=>'The file was only partially uploaded. Choose it again and retry.',
                UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE=>'The server cannot write uploaded files. Please check the upload temporary folder.',
                default=>'Document upload failed. Please choose the files again.',
            };
            throw new InvalidArgumentException($message);
        }
        $size=filesize($path); $total+=$size;
        if ($size>20*1024*1024 || $total>100*1024*1024) throw new InvalidArgumentException('Each document must be at most 20 MB and the upload total under 100 MB.');
        $mime=employee_document_mime($path);
        if (!$mime) throw new InvalidArgumentException('Unsupported document: '.basename((string)$files['name'][$index]).'. Choose an image (JPG, PNG, GIF, WebP, BMP, TIFF, HEIC, HEIF, AVIF, SVG) or PDF. Renaming another file to an image does not convert it.');
        $name=basename(str_replace('\\','/',(string)$files['name'][$index]));
        if (strlen($name)>255) throw new InvalidArgumentException('Document filename is too long.');
        $result[]=['name'=>$name,'mime'=>$mime,'tmp_path'=>$path];
    }
    return $result;
}
function store_employee_document(array $document): string
{
    $root=dirname(__DIR__).'/storage/employee-documents';
    if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) throw new InvalidArgumentException('The server cannot create the document storage folder. Please check storage permissions.');
    if (!is_writable($root)) throw new InvalidArgumentException('The document storage folder is not writable. Please check storage permissions.');
    $relative='storage/employee-documents/'.bin2hex(random_bytes(24)).'.bin';
    if (!move_uploaded_file($document['tmp_path'],dirname(__DIR__).'/'.$relative)) throw new InvalidArgumentException('The document could not be saved to storage. Please retry the upload.');
    return $relative;
}
