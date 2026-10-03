<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/TicketService.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);header('Allow: POST');exit;}
    if (!empty(tickets_storage_config()['remote_url'])) throw new RuntimeException('Storage endpoint must run on the native EC2 store.');
    $fields=[];
    foreach (['operation','actor','key','id','time','nonce','name','digest'] as $field) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) {http_response_code(403);exit;}
        $fields[$field]=$_POST[$field];
    }
    $signature=$_POST['signature']??'';
    if (!is_string($signature) || !ctype_digit($fields['time']) || abs(time()-(int)$fields['time'])>120 || !preg_match('/^[a-f0-9]{32}$/D',$fields['nonce']) || !hash_equals(tickets_storage_signature($fields,tickets_storage_secret()),$signature)) {http_response_code(403);exit;}
    $actor=(int)$fields['actor'];
    if (!ctype_digit($fields['actor']) || !tickets_staff($actor)) {http_response_code(403);exit;}
    $nonceDir=dirname(__DIR__,3).'/storage/ticket-transfer-nonces';
    if (!is_dir($nonceDir) && !mkdir($nonceDir,0750,true) && !is_dir($nonceDir)) throw new RuntimeException('Cannot create transfer nonce directory.');
    $noncePath=$nonceDir.'/'.$fields['nonce'];$nonce=fopen($noncePath,'x');
    if (!$nonce) {http_response_code(409);exit;}
    fclose($nonce);chmod($noncePath,0640);
    // Nonces expire after the complete timestamp acceptance window.
    if (random_int(1,20)===1) foreach (new DirectoryIterator($nonceDir) as $entry) {
        if ($entry->isFile() && preg_match('/^[a-f0-9]{32}$/D',$entry->getFilename()) && $entry->getMTime()<time()-300) unlink($entry->getPathname());
    }
    $operation=$fields['operation'];$key=$fields['key'];
    if ($operation==='get') {
        if (!ctype_digit($fields['id'])) {http_response_code(400);exit;}
        $q=eve_db()->prepare('SELECT storage_key,size_bytes FROM guristas_ticket_attachments WHERE id=?');$q->execute([(int)$fields['id']]);$row=$q->fetch();
        if (!$row || !preg_match('/^[a-f0-9]{48}$/D',$row['storage_key'])) {http_response_code(404);exit;}
        $path=tickets_upload_dir().'/'.$row['storage_key'];
        if (!is_file($path) || filesize($path)!==(int)$row['size_bytes']) {http_response_code(404);exit;}
        header('Content-Type: application/octet-stream');header('Content-Length: '.filesize($path));readfile($path);exit;
    }
    if (!preg_match('/^[a-f0-9]{48}$/D',$key)) {http_response_code(400);exit;}
    $path=tickets_upload_dir().'/'.$key;
    if ($operation==='put') {
        $files=tickets_upload_prepare($_FILES['attachments']??[]);
        if (count($files)!==1 || $files[0]['name']!==$fields['name'] || !hash_equals(hash_file('sha256',$files[0]['tmp']),$fields['digest'])) {http_response_code(400);exit;}
        $dir=tickets_upload_dir();
        if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Cannot create attachment directory.');
        // Exclusive destination creation protects existing files from replacement.
        $out=fopen($path,'x+b');if (!$out) {http_response_code(409);exit;}
        $in=fopen($files[0]['tmp'],'rb');
        try {
            if (!$in || stream_copy_to_stream($in,$out)!==$files[0]['size']) throw new RuntimeException('Incomplete attachment transfer.');
        } catch (Throwable $error) {fclose($out);if ($in) fclose($in);unlink($path);throw $error;}
        fclose($in);fclose($out);chmod($path,0640);
        header('Content-Type: application/json');echo json_encode(['key'=>$key,'size'=>$files[0]['size'],'mime'=>$files[0]['mime']],JSON_THROW_ON_ERROR);exit;
    }
    if ($operation==='delete') {
        $q=eve_db()->prepare('SELECT id FROM guristas_ticket_attachments WHERE storage_key=? LIMIT 1');$q->execute([$key]);
        if ($q->fetch()) {http_response_code(409);exit;}
        if (is_file($path) && !unlink($path)) throw new RuntimeException('Cannot remove uncommitted attachment.');
        header('Content-Type: application/json');echo '{"ok":true}';exit;
    }
    http_response_code(400);
} catch (Throwable $error) {
    error_log('Ticket storage bridge: '.$error->getMessage());http_response_code(500);echo 'Attachment storage unavailable.';
}
