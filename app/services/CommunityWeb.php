<?php
declare(strict_types=1);
require_once __DIR__.'/CommunityNetwork.php';
require_once __DIR__.'/CommunityImages.php';
function community_post_limit(): void
{
    $limit=trim((string)ini_get('post_max_size'));$unit=strtolower(substr($limit,-1));$bytes=(float)$limit;
    $bytes*=match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
    if($bytes>0 && (float)($_SERVER['CONTENT_LENGTH']??0)>$bytes){http_response_code(413);exit('This upload exceeds the server request limit. Use fewer or smaller images.');}
}
function community_message(Throwable $e): string
{
    return $e instanceof InvalidArgumentException || $e->getMessage()==='The board changed. Reload before saving.' ? $e->getMessage() : 'Unable to save. Reload and try again. If this continues, contact an officer.';
}
function community_receive(array $user,bool $staff): void
{
    $images=community_images()->receive($_FILES['images']??[],$_POST['alternatives']??[],$_POST['type']??'');
    try {community_network()->apply(array_replace($_POST,['action'=>'media_submit']),(int)$user['character_id'],(string)$user['character_name'],$staff,$images);}
    catch(Throwable $e){community_images()->cleanup($images);throw $e;}
}
function community_entry_url(array $entry): string
{
    return in_array($entry['type'],['art','comic'],true)?'/community/read.php?id='.$entry['id']:'/operations/entry.php?id='.$entry['id'];
}
