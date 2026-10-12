<?php
declare(strict_types=1);
/** Optional bounded ESI history cache warmer; no changes to active recommendations. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/MarketIntelligence.php';
$limit=(int)($argv[1]??6);
if($limit<1||$limit>8){fwrite(STDERR,"Usage: php bin/guristas-lp-history-warm.php [1..8]\n");exit(2);}
$intel=new GuristasMarketIntelligence();$dir=$intel->dir();
if(!is_dir($dir)){fwrite(STDERR,"Missing market cache.\n");exit(2);}
$lock=fopen($dir.'/trade-collector.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Collector already running.\n");exit(3);}
try{
    $offers=$intel->read('lp-offers');
    $ts=strtotime((string)($offers['fetched_at']??''))?:0;
    if(!$ts||time()-$ts>=86400||!is_array($offers['offers']??null))throw new RuntimeException('Fresh LP offers required');
    $ref=guri_industry_reference();$prices=[];
    foreach([10000002,10000032] as $region){
        $snap=$intel->read('region-'.$region);
        $age=time()-(strtotime((string)($snap['fetched_at']??''))?:0);
        if(empty($snap['complete'])||$age<0||$age>10800)continue;
        foreach($snap['orders']??[] as $order){
            if(!empty($order['b'])||(float)($order['p']??0)<=0)continue;
            $type=(int)($order['t']??0);$price=(float)$order['p'];
            if($type<1)continue;
            $key=$region.'-'.$type;
            if(!isset($prices[$key])||$price<$prices[$key]['price'])$prices[$key]=['region'=>$region,'type'=>$type,'price'=>$price];
        }
    }
    $candidate=[];
    foreach($offers['offers'] as $offer){
        $type=(int)($offer['type_id']??0);$lp=(int)($offer['lp_cost']??0);
        $qty=(int)($offer['quantity']??0);
        if($type<1||$lp<1||$qty<1||!empty($offer['ak_cost'])||isset($ref['blueprints'][(string)$type]))continue;
        foreach([10000002,10000032] as $region){
            $key=$region.'-'.$type;
            if(!isset($prices[$key]))continue;
            // Only prioritizes which price histories to look up: NOT a profit
            // calculation, since LP cash costs, input items, and fees differ.
            $priority=$qty*$prices[$key]['price']/$lp;
            if(!isset($candidate[$key])||$priority>$candidate[$key]['priority'])
                $candidate[$key]=['region'=>$region,'type'=>$type,'priority'=>$priority];
        }
    }
    uasort($candidate,static fn($a,$b)=>$b['priority']<=>$a['priority']);
    $checked=0;$cached=0;$ok=0;$missing=0;
    foreach($candidate as $key=>$c){
        $snap=$intel->read('history-'.$key);
        $stamp=strtotime((string)($snap['collected_at']??''))?:0;
        if($stamp&&time()-$stamp<86400){$cached++;continue;}
        if($checked===$limit)break;
        $checked++;
        if($intel->marketHistory($c['region'],$c['type']))$ok++;else $missing++;
    }
    echo json_encode(['ok'=>true,'esi_histories_requested'=>$checked,'histories_available'=>$ok,
        'histories_unavailable'=>$missing,'fresh_histories_reused'=>$cached,
        'candidate_region_types'=>count($candidate),
        'note'=>'Cache preparation only; no recommendation published and no promised LP sale'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"LP history warm failed: ".$e->getMessage()."\n");exit(1);}finally{flock($lock,LOCK_UN);fclose($lock);}
