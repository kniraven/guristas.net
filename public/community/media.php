<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/services/CommunityWeb.php';
header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");header('Cache-Control: private, no-store');
$id=is_string($_GET['id']??null)?$_GET['id']:'';
if(!preg_match('/^[a-f0-9]{48}$/D',$id)){http_response_code(404);exit('Image not found.');}
try{$ledger=community_network()->read();}catch(Throwable $e){http_response_code(503);exit('Image relay unavailable.');}
$found=null;$entry=null;
foreach($ledger['entries'] as $candidate)foreach($candidate['images']??[] as $image)if($image['id']===$id){$found=$image;$entry=$candidate;break 2;}
if(!$found){http_response_code(404);exit('Image not found.');}
if(!CommunityNetwork::visible($entry)) {
    require_once dirname(__DIR__,2).'/app/services/TicketService.php';$user=eve_current_user();
    if(!$user || (($entry['author']??0)!==(int)$user['character_id'] && !tickets_staff((int)$user['character_id']))){http_response_code(404);exit('Image not found.');}
}
try{$path=community_images()->path($found);}catch(Throwable $e){http_response_code(404);exit('Image not found.');}
if(!is_file($path) || is_link($path)){http_response_code(404);exit('Image not found.');}
header('Content-Type: '.$found['mime']);header('Content-Length: '.filesize($path));
header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="guristas-image.'.$found['extension'].'"');
readfile($path);
