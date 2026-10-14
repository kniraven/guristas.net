<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/MarketIntelligence.php';
$command=$argv[1]??'';
if(!in_array($command,['raid','priority','region','rotate','offers','publish','trade-cycle','collect-region','collect-priority','collect-rotate'],true)){
    fwrite(STDERR,"Usage: php bin/guristas-intelligence.php raid|priority|region REGION_ID|collect-region REGION_ID|collect-priority|collect-rotate|rotate|offers|publish|trade-cycle\n");exit(2);
}
$intel=new GuristasMarketIntelligence();
$dir=$intel->dir();
if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cache directory unavailable');
$lock=fopen($dir.'/'.($command==='raid'?'raid':'trade').'-collector.lock','c');
if(!$lock){fwrite(STDERR,"Cannot open collector lock file; verify cache permissions.\n");exit(3);}
if(!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Collector already running.\n");fclose($lock);exit(3);}
try{
    $log=[];
    if($command==='raid')$log[]=$intel->refreshRaid();
    if(in_array($command,['priority','trade-cycle','collect-priority'],true)){
        foreach([10001000,10000015] as $id){$log[]=$intel->collectRegion($id);}
    }
    if(in_array($command,['region','collect-region'],true))$log[]=$intel->collectRegion((int)($argv[2]??0));
    if(in_array($command,['rotate','trade-cycle','collect-rotate'],true)){
        // Rotating worker: favor the five outside trade-hub regions when stale,
        // but preserve every other rotation for broader New Eden discovery.
        // Existing dedicated hub/priority cron tasks remain unchanged.
        $hubRegions=[10000002,10000032,10000043,10000042,10000030]; // Jita, Dodixie, Amarr, Hek, Rens
        $ids=array_values(array_diff($intel->regionIds(),[10001000,10000015]));
        if($ids){
            $cursor=(int)($intel->read('rotation')['index']??0);
            $background=array_values(array_diff($ids,$hubRegions));
            $candidates=[];
            foreach($hubRegions as $region){
                if(!in_array($region,$ids,true))continue;
                $snap=$intel->read('region-'.$region);
                $stamp=strtotime((string)($snap['fetched_at']??''))?:0;
                if(time()-$stamp>3600)$candidates[]=['id'=>$region,'age'=>time()-$stamp];
            }
            usort($candidates,static fn($a,$b)=>$b['age']<=>$a['age']);
            $useHub=($cursor%2===0)&&$candidates;
            $region=$useHub?$candidates[0]['id']:($background?$background[intdiv($cursor,2)%count($background)]:$ids[$cursor%count($ids)]);
            // Advance before scanning so a failed region does not stall rotation.
            $intel->publish('rotation',['index'=>$cursor+1,'region'=>$region,'fetched_at'=>gmdate('c'),'priority_hub'=>(bool)$useHub]);
            $log[]=$intel->collectRegion($region);
        }
    }
    if(in_array($command,['offers','trade-cycle'],true))$log[]=$intel->refreshOffers();
    // LP offers expire after 24h in TRADE; a normal scheduled publish must not
    // silently drop an entire mandatory homepage role when offers age out.
    // Refresh daily, but never use the old snapshot if refresh fails and it
    // has crossed the existing 24h eligibility limit.
    if(in_array($command,['publish','priority','region','rotate'],true)){
        $snapshot=$intel->read('lp-offers');
        $stamp=strtotime((string)($snapshot['fetched_at']??''))?:0;
        if(!$stamp || time()-$stamp>=86400){
            try{$log[]=['lp_auto_refresh'=>$intel->refreshOffers()];}
            catch(Throwable $e){
                error_log('Guristas LP scheduled refresh failed: '.$e->getMessage());
                $log[]=['lp_auto_refresh'=>'failed; stale offers excluded by existing 24h guard'];
            }
        }
    }
    if(in_array($command,['publish','trade-cycle','priority','region','rotate','offers'],true)){
        require_once dirname(__DIR__).'/app/services/TradeOrders.php';
        $report=(new GuristasTradeOrders($intel))->collect();
        $intel->decorateTrade($report);
        $intel->publish('trade-orders',['data'=>$report,'collected_at'=>gmdate('c')]);
        // Observational shadow audit: never prevent publishing if audit storage fails.
        try {
            require_once dirname(__DIR__).'/app/services/TradeAudit.php';
            $audit=GuristasTradeAudit::record($dir,$report,GuristasTradeAudit::snapshots($intel,$dir,$report));
            $log[]=['shadow_audit'=>'recorded','observations'=>$audit['observations']];
        } catch (Throwable $auditError) {
            error_log('Guristas trade shadow audit failed: '.$auditError->getMessage());
            $log[]=['shadow_audit'=>'failed; recommendations still published'];
        }
        $log[]=['published'=>count($report['orders']),'candidates'=>$report['coverage']['candidate_items']??0];
        $log[]=['publisher_peak_memory_mib'=>round(memory_get_peak_usage(true)/1048576,1)];
    }
    echo json_encode(['ok'=>true,'steps'=>$log],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
}catch(Throwable $error){fwrite(STDERR,"Collection failed: ".$error->getMessage()."\n");exit(1);}finally{flock($lock,LOCK_UN);fclose($lock);}
