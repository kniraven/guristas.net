<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/TicketService.php';
$user=eve_require_user();
if(!tickets_staff((int)$user['character_id'])) {http_response_code(403);exit('Staff access required.');}
$q=eve_db()->prepare('SELECT * FROM guristas_ticket_attachments WHERE id=?');$q->execute([(int)($_GET['id']??0)]);$file=$q->fetch();
if(!$file || !preg_match('/^[a-f0-9]{48}$/',$file['storage_key'])) {http_response_code(404);exit('Attachment not found.');}
$path=tickets_upload_dir().'/'.$file['storage_key'];
session_write_close();
$bytes=null;
try {
    if (tickets_storage_remote()) {
        $bytes=tickets_storage_request('get',(int)$user['character_id'],'',(int)$file['id']);
        if (strlen($bytes)!==(int)$file['size_bytes']) throw new RuntimeException('Attachment size mismatch.');
    } elseif (!is_file($path)) {http_response_code(404);exit('Attachment not found.');}
} catch (Throwable $error) {error_log('Ticket attachment download: '.$error->getMessage());http_response_code(502);exit('Attachment storage unavailable.');}
$preview=($_GET['preview']??'')==='1' && in_array($file['mime_type'],['image/png','image/jpeg','image/gif'],true);
header('Content-Type: '.($preview?$file['mime_type']:'application/octet-stream'));
header('Content-Disposition: '.($preview?'inline':'attachment').'; filename="attachment"; filename*=UTF-8\'\''.rawurlencode($file['original_name']));
header('Content-Length: '.($bytes===null?filesize($path):strlen($bytes)));
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");
if ($bytes===null) readfile($path); else echo $bytes;
