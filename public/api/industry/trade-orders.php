<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
require_once dirname(__DIR__,3).'/app/services/MarketIntelligence.php';
try {
    $s=(new GuristasMarketIntelligence())->read('trade-orders');
    if(!$s||!isset($s['data']['orders'],$s['collected_at']))throw new RuntimeException('Intelligence awaiting scheduled collector');
    $age=time()-(strtotime($s['collected_at'])?:0);
    if($age<0||$age>1800)throw new RuntimeException('Scheduled market intelligence stale');
    $data=$s['data'];$data['stale']=$age>900;
    echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'message'=>'Market intelligence pending or stale. Scheduled collector may need attention.']);}
