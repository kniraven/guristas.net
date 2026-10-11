<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/MarketIntelligence.php';
$command=$argv[1]??'';
if(!in_array($command,['raid','priority','region','rotate','offers','publish','trade-cycle'],true)){
    fwrite(STDERR,"Usage: php bin/guristas-intelligence.php raid|priority|region REGION_ID|rotate|offers|publish|trade-cycle\n");exit(2);
}
$intel=new GuristasMarketIntelligence();
$dir=$intel->dir();
if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cache directory unavailable');
$lock=fopen($dir.'/'.($command==='raid'?'raid':'trade').'-collector.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Collector already running.\n");exit(3);}
try{
    $log=[];
    if($command==='raid')$log[]=$intel->refreshRaid();
    if(in_array($command,['priority','trade-cycle'],true)){
        foreach([10001000,10000015] as $id){$log[]=$intel->collectRegion($id);}
    }
    if($command==='region')$log[]=$intel->collectRegion((int)($argv[2]??0));
    if(in_array($command,['rotate','trade-cycle'],true)){
        $ids=array_values(array_diff($intel->regionIds(),[10001000,10000015]));
        if($ids){
            $cursor=(int)($intel->read('rotation')['index']??0);
            $region=$ids[$cursor%count($ids)];
            // Advance even if a region later fails, so a permanently unsupported region does not block the sweep.
            $intel->publish('rotation',['index'=>$cursor+1,'region'=>$region,'fetched_at'=>gmdate('c')]);
            $log[]=$intel->collectRegion($region);
        }
    }
    if(in_array($command,['offers','trade-cycle'],true))$log[]=$intel->refreshOffers();
    if(in_array($command,['publish','trade-cycle','priority','region','rotate','offers'],true)){
        require_once dirname(__DIR__).'/app/services/TradeOrders.php';
        $report=(new GuristasTradeOrders($intel))->collect();
        $intel->decorateTrade($report);
        $intel->publish('trade-orders',['data'=>$report,'collected_at'=>gmdate('c')]);
        $log[]=['published'=>count($report['orders']),'candidates'=>$report['coverage']['candidate_items']??0];
    }
    echo json_encode(['ok'=>true,'steps'=>$log],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
}catch(Throwable $error){fwrite(STDERR,"Collection failed: ".$error->getMessage()."\n");exit(1);}finally{flock($lock,LOCK_UN);fclose($lock);}
