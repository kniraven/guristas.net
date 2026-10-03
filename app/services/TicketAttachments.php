<?php
declare(strict_types=1);
require_once __DIR__.'/TicketStorageBridge.php';
function tickets_upload_dir(): string { return dirname(__DIR__,2).'/storage/ticket-attachments'; }
function tickets_upload_prepare(array $files): array {
    if (!$files || !isset($files['error'])) return [];
    if (!is_array($files['error'])) throw new InvalidArgumentException('Invalid attachment request.');
    $accepted=[]; $total=0;
    $mimeMap=[
        'csv'=>['text/plain','text/csv','application/csv','application/vnd.ms-excel'],
        'txt'=>['text/plain'], 'md'=>['text/plain','text/markdown'], 'json'=>['application/json','text/plain'],
        'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'gif'=>['image/gif'],
        'pdf'=>['application/pdf'],
        'xls'=>['application/vnd.ms-excel','application/x-ole-storage','application/CDFV2','application/octet-stream'],
        'doc'=>['application/msword','application/x-ole-storage','application/CDFV2','application/octet-stream'],
        'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'xlsm'=>['application/zip','application/vnd.ms-excel.sheet.macroEnabled.12'],
        'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];
    foreach ($files['error'] as $i=>$error) {
        if ($error===UPLOAD_ERR_NO_FILE) continue;
        if ($error!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Attachment upload failed or exceeds the server upload limit.');
        if (count($accepted)>=5) throw new InvalidArgumentException('Attach up to five files per submission.');
        $tmp=$files['tmp_name'][$i]??'';
        if (!is_uploaded_file($tmp)) throw new InvalidArgumentException('Invalid uploaded file.');
        $size=filesize($tmp); if ($size===false || $size<1 || $size>10*1024*1024) throw new InvalidArgumentException('Files must be nonempty and no larger than 10 MB each.');
        $total+=$size; if ($total>25*1024*1024) throw new InvalidArgumentException('Attachments must total 25 MB or less per submission.');
        $name=basename(str_replace('\\','/',(string)($files['name'][$i]??'')));
        $name=preg_replace('/[\x00-\x1f\x7f]/','',$name);
        if ($name==='' || strlen($name)>240) throw new InvalidArgumentException('Use a filename under 240 bytes.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if (!isset($mimeMap[$ext])) throw new InvalidArgumentException('Unsupported file type: '.$ext);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime,$mimeMap[$ext],true)) throw new InvalidArgumentException('The contents do not match the file type: '.$name);
        if (in_array($ext,['png','jpeg','jpg','gif'],true) && getimagesize($tmp)===false) throw new InvalidArgumentException('Invalid image: '.$name);
        if (in_array($ext,['doc','xls'],true)) {
            $h=fopen($tmp,'rb');$magic=fread($h,8);fclose($h);
            if ($magic!==hex2bin('d0cf11e0a1b11ae1')) throw new InvalidArgumentException('Invalid legacy Office file: '.$name);
        }
        if (in_array($ext,['xlsx','xlsm','docx'],true)) {
            if (!class_exists('ZipArchive')) throw new RuntimeException('Office attachments require PHP ZipArchive.');
            $zip=new ZipArchive(); if ($zip->open($tmp)!==true) throw new InvalidArgumentException('Invalid Office document: '.$name);
            try { if ($zip->locateName('[Content_Types].xml')===false || $zip->locateName($ext==='docx'?'word/document.xml':'xl/workbook.xml')===false) throw new InvalidArgumentException('Invalid Office document: '.$name); } finally {$zip->close();}
        }
        $accepted[]=['tmp'=>$tmp,'name'=>$name,'size'=>$size,'mime'=>$mime];
    }
    return $accepted;
}
/** Called inside the ticket transaction. Caller removes moved files on rollback. */
function tickets_upload_store(array $files,int $ticket,?int $activity,int $actor,array &$moved): void {
    if (!$files) return;
    $remote=tickets_storage_remote();
    $dir=tickets_upload_dir();
    if (!$remote && (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir))) throw new RuntimeException('Cannot create private attachment directory.');
    foreach ($files as $file) {
        $key=bin2hex(random_bytes(24));$path=$dir.'/'.$key;
        if ($remote) {
            $moved[]='remote:'.$actor.':'.$key;
            $reply=json_decode(tickets_storage_request('put',$actor,$key,0,$file),true,16,JSON_THROW_ON_ERROR);
            if (($reply['key']??'')!==$key || ($reply['size']??0)!==$file['size'] || ($reply['mime']??'')!==$file['mime']) throw new RuntimeException('EC2 attachment verification failed.');
        } else {
            if (!move_uploaded_file($file['tmp'],$path)) throw new RuntimeException('Unable to store attachment.');
            $moved[]=$path;chmod($path,0640);
        }
        $q=eve_db()->prepare('INSERT INTO guristas_ticket_attachments(ticket_id,activity_id,author_id,original_name,storage_key,mime_type,size_bytes,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$ticket,$activity,$actor,$file['name'],$key,$file['mime'],$file['size']]);
    }
}
function tickets_upload_cleanup(array $paths):void {
    foreach ($paths as $path) {
        try {
            if (preg_match('/^remote:([0-9]+):([a-f0-9]{48})$/D',$path,$m)) tickets_storage_request('delete',(int)$m[1],$m[2]);
            elseif (is_file($path)) unlink($path);
        } catch (Throwable $error) {error_log('Ticket attachment cleanup failed: '.$error->getMessage());}
    }
}
function tickets_attachment_list(int $ticket,?int $activity=null): array {
    $q=eve_db()->prepare('SELECT id,original_name,size_bytes,mime_type FROM guristas_ticket_attachments WHERE ticket_id=? AND '.($activity===null?'activity_id IS NULL':'activity_id=?').' ORDER BY id');
    $q->execute($activity===null?[$ticket]:[$ticket,$activity]);return $q->fetchAll();
}
function tickets_attachment_links(int $ticket,?int $activity=null): void {
    $files=tickets_attachment_list($ticket,$activity);if(!$files)return;
    echo '<ul class="ticket-attachments" aria-label="Attachments">';
    foreach($files as $file) {
        echo '<li>';
        if(in_array($file['mime_type'],['image/png','image/jpeg','image/gif'],true)) echo '<button type="button" class="ticket-image-button" data-image-preview="/admin/tickets/download.php?id='.(int)$file['id'].'&amp;preview=1" aria-label="Preview '.eve_e($file['original_name']).'"><img loading="lazy" src="/admin/tickets/download.php?id='.(int)$file['id'].'&amp;preview=1" alt="'.eve_e($file['original_name']).'"></button>';
        echo '<a href="/admin/tickets/download.php?id='.(int)$file['id'].'" download target="_blank" rel="noopener">'.eve_e($file['original_name']).'</a><span>'.number_format($file['size_bytes']/1024,1).' KB</span></li>';
    }
    echo '</ul>';
}
