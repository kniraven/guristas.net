<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
require_once dirname(__DIR__,3).'/app/services/MarketIntelligence.php';
try {
    $snapshot=(new GuristasMarketIntelligence())->read('build-orders');
    if(!$snapshot || !isset($snapshot['data']['orders'],$snapshot['collected_at']))throw new RuntimeException('Missing publication');
    $age=time()-(strtotime((string)$snapshot['collected_at'])?:0);
    if($age<0||$age>1800)throw new RuntimeException('Stale publication');
    echo json_encode(['ok'=>true,'data'=>$snapshot['data']],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {http_response_code(503);echo json_encode(['ok'=>false,'message'=>'Build intelligence unavailable or awaiting fresh market evidence.']);}
