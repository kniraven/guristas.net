<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/PilotDataService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
try {
    $user=eve_current_user();
    if(!$user){http_response_code(401);echo json_encode(['ok'=>false,'state'=>'logged_out']);exit;}
    $id=(int)$user['character_id'];
    $config=require dirname(__DIR__,3).'/config/esi.php';
    $cache=new GuristasEsiCache(dirname(__DIR__,3).'/storage/cache/pilot/'.eve_token_table());
    $service=new GuristasPilotDataService(new GuristasEsiClient($config,$cache));
    $data=$service->read($id)['skills']??[];
    if(($data['state']??'')!=='ready' || !is_array($data['data']??null)){
        echo json_encode(['ok'=>true,'state'=>'unknown']);exit;
    }
    $levels=[];
    foreach([3380,3386,13279,2495] as $skill)$levels[(string)$skill]=(int)($data['data'][$skill]??0);
    echo json_encode(['ok'=>true,'state'=>'ready','levels'=>$levels],JSON_THROW_ON_ERROR);
} catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'state'=>'unknown']);}
