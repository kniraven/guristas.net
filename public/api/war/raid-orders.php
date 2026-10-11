<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
require_once dirname(__DIR__,3).'/app/services/MarketIntelligence.php';
try{
    $s=(new GuristasMarketIntelligence())->read('raid');
    if(!$s||!isset($s['data'],$s['meta'],$s['collected_at']))throw new RuntimeException('Raid report not collected');
    $age=time()-(strtotime($s['collected_at'])?:0);
    if($age<0||$age>900)throw new RuntimeException('Raid data stale');
    $meta=$s['meta'];if($age>300)$meta['stale']=true;
    echo json_encode(['ok'=>true,'data'=>$s['data'],'meta'=>$meta,'sources'=>$s['sources']??[],'generated_at'=>$s['collected_at']],JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'message'=>'Raid intelligence pending or stale. Scheduled collector may need attention.']);}
