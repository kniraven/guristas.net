<?php
declare(strict_types=1);
/** Observational, compact, seven-day BUILD quality log. Never writes to TRADE audit. */
final class GuristasBuildAudit {
    public static function record(string $cacheDir,array $report):array {
        $path=rtrim($cacheDir,'/').'/build-shadow-audit.json';
        $read=is_file($path)?json_decode((string)file_get_contents($path),true):null;
        $old=is_array($read['observations']??null)?$read['observations']:[];
        $now=time();
        $old=array_values(array_filter($old,static fn($row)=>isset($row['time']) && $row['time']>=$now-7*86400));
        $orders=[];
        foreach($report['orders']??[] as $o){
            $e=$o['evidence']??[];
            $orders[]=['activity'=>$o['activity']??'unknown','title'=>$o['title']??'',
                'state'=>$o['state']??'unknown','margin_isk'=>isset($o['metric_isk'])?round((float)$o['metric_isk'],2):null,
                'source_order_count'=>count($e['inputs']??[]),
                'destination_order_id'=>$e['bid_order_id']??$e['buyer_order_id']??null,
                'live_resource_location_verified'=>!empty($e['live_deposit_verified']),
                'quote_scope'=>$e['market']??$e['buyer_region']??null];
        }
        $previous=end($old)?:null;
        // Check surviving destination orders against current snapshots; order presence
        // does NOT guarantee its price, volume or that a future fill is possible.
        $seenNow=[];
        foreach(['region-10000002','region-10000015','region-10001000'] as $file){
            $f=rtrim($cacheDir,'/').'/'.$file.'.json';
            $d=is_file($f)?json_decode((string)file_get_contents($f),true):null;
            foreach(($d['orders']??[]) as $order)if(!empty($order['i']))$seenNow[(string)$order['i']]=true;
        }
        $previousChecked=0;$previousPresent=0;
        foreach(($previous['orders']??[]) as $order){
            $id=(string)($order['destination_order_id']??'');
            if($id===''||$id==='0')continue;
            $previousChecked++;
            if(!empty($seenNow[$id]))$previousPresent++;
        }
        $changes=0;
        foreach($orders as $i=>$o){if(isset($previous['orders'][$i]) && ($previous['orders'][$i]['title']!==$o['title'] || $previous['orders'][$i]['state']!==$o['state']))$changes++;}
        $old[]=['time'=>$now,'at'=>gmdate('c',$now),'orders'=>$orders,'changed_from_previous'=>$changes,'previous_quotes_rechecked'=>$previousChecked,'previous_order_ids_still_present'=>$previousPresent,'coverage'=>$report['coverage']??[]];
        $old=array_slice($old,-1100); // bounded even if cron runs every ten minutes
        $bytes=json_encode(['schema'=>1,'observations'=>$old],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        // Atomic replacement, no backup artifacts.
        $tmp=$path.'.writing';if(file_put_contents($tmp,$bytes,LOCK_EX)===false || !rename($tmp,$path))throw new RuntimeException('BUILD audit write failed');
        return ['observations'=>count($old),'last_changed_cards'=>$changes];
    }
    public static function summary(string $cacheDir):array {
        $path=rtrim($cacheDir,'/').'/build-shadow-audit.json';
        $d=is_file($path)?json_decode((string)file_get_contents($path),true):null;
        $rows=$d['observations']??[];
        $summary=['observations'=>count($rows),'first_at'=>$rows[0]['at']??null,'last_at'=>$rows[count($rows)-1]['at']??null,'changes'=>0,'previous_quotes_rechecked'=>0,'previous_order_ids_still_present'=>0,'by_activity'=>[],'unverified_locations'=>0];
        foreach($rows as $r){$summary['changes']+=(int)($r['changed_from_previous']??0);$summary['previous_quotes_rechecked']+=(int)($r['previous_quotes_rechecked']??0);$summary['previous_order_ids_still_present']+=(int)($r['previous_order_ids_still_present']??0);foreach($r['orders']??[] as $o){$activity=$o['activity']??'unknown';$state=$o['state']??'unknown';$key=$activity.' / '.$state;$summary['by_activity'][$key]=($summary['by_activity'][$key]??0)+1;if($activity==='mine'&&!$o['live_resource_location_verified'])$summary['unverified_locations']++;}}
        return $summary;
    }
}
