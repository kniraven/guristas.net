<?php
declare(strict_types=1);
/** Server-side, observational market audit. Does not use ESI or modify recommendations. */
final class GuristasTradeAudit {
    private const WINDOW = 604800; // seven days
    private const MAX_EVENTS = 2016; // > 7 days at one event per five minutes
    private static function quantity(array $order):int{
        if(isset($order['quantity_units']))return max(0,(int)$order['quantity_units']);
        if(preg_match('/QTY\s*\/\/\s*([0-9,]+)/',(string)($order['quantity']??''),$m))return (int)str_replace(',','',$m[1]);
        return 0;
    }
    private static function observed(array $order,array $snapshots):array {
        $e=$order['quote_evidence']??[];
        $needed=[];
        foreach(($e['source_asks_used']??[]) as $ask) if(is_array($ask)) $needed[]=['side'=>'ask','row'=>$ask];
        if(is_array($e['destination_buy_order']??null))$needed[]=['side'=>'bid','row'=>$e['destination_buy_order']];
        $missing=0;$checked=0;$changed=0;$latest=0;$evidence=[];
        foreach($needed as $n){
            $r=$n['row'];$id=(int)($r['order_id']??0);$station=(int)($r['station_id']??0);
            $type=(int)($order['type_id']??0);$found=null;$age=null;
            foreach($snapshots as $region=>$snapshot){
                if(!is_array($snapshot))continue;
                $timestamp=strtotime((string)($snapshot['fetched_at']??''));
                if(!$timestamp||time()-$timestamp>10800||$timestamp>time()+300)continue;
                foreach(($snapshot['orders']??[]) as $item){
                    if((int)($item['i']??0)===$id&&$id>0&&(int)($item['l']??0)===$station&&(int)($item['t']??0)===$type){
                        $found=$item;$age=time()-$timestamp;break 2;
                    }
                }
            }
            if($id<=0){$missing++;$evidence[]=['side'=>$n['side'],'result'=>'missing_order_id'];continue;}
            if(!$found){$missing++;$evidence[]=['side'=>$n['side'],'order_id'=>$id,'result'=>'not_found_in_fresh_snapshots'];continue;}
            $checked++;$latest=max($latest,$age??0);
            $price=(float)($r['price_isk']??0);$available=(int)($r['units_used']??($order['quantity_units']??$order['quantity_value']??0));
            $quantityNeeded=$n['side']==='ask'?(int)($r['units_used']??1):self::quantity($order);
            $priceMatch=abs((float)$found['p']-$price)<0.005;
            $volumeMatch=(int)$found['v']>=max(1,$quantityNeeded);
            $sideMatch=((bool)($found['b']??false)===($n['side']==='bid'));
            $ok=$priceMatch&&$volumeMatch&&$sideMatch;
            if(!$ok)$changed++;
            $evidence[]=['side'=>$n['side'],'order_id'=>$id,'result'=>$ok?'available_at_quote':'price_volume_or_side_changed',
                'quoted_price'=>$price,'observed_price'=>(float)$found['p'],'quoted_units_needed'=>max(1,$quantityNeeded),
                'remaining_units'=>(int)$found['v'],'snapshot_age_seconds'=>$age];
        }
        return ['existing_orders_expected'=>count($needed),'orders_checked'=>$checked,'orders_unverified'=>$missing,
            'orders_changed'=>$changed,'observations'=>$evidence];
    }
    /** Keep only matching order IDs, not whole ESI region snapshots, in memory. */
    public static function snapshots(object $intel,string $dir,array $report):array{
        $ids=[];
        $add=static function(?array $quote)use(&$ids):void{
            if(!$quote)return;
            foreach($quote['source_asks_used']??[] as $r)if((int)($r['order_id']??0)>0)$ids[(int)$r['order_id']]=true;
            if((int)($quote['destination_buy_order']['order_id']??0)>0)$ids[(int)$quote['destination_buy_order']['order_id']]=true;
        };
        foreach($report['orders']??[] as $r)$add($r['quote_evidence']??null);
        $file=rtrim($dir,'/').'/trade-shadow-audit.json';
        if(is_file($file)){
            $stored=json_decode((string)file_get_contents($file),true);
            $events=$stored['events']??[];
            if($events)foreach(($events[count($events)-1]['rows']??[]) as $r)$add($r['quote_evidence']??null);
        }
        $snaps=[];
        if(!$ids)return $snaps;
        foreach(array_unique(array_merge([10001000,10000015],$intel->regionIds())) as $region){
            $data=$intel->read('region-'.$region);
            if(!is_array($data)||empty($data['complete']))continue;
            $timestamp=strtotime((string)($data['fetched_at']??''));
            if(!$timestamp||time()-$timestamp>10800||$timestamp>time()+300)continue;
            $matches=[];
            foreach(($data['orders']??[]) as $row){
                if(isset($ids[(int)($row['i']??0)]))$matches[]=$row;
            }
            if($matches)$snaps[$region]=['fetched_at'=>$data['fetched_at'],'orders'=>$matches];
            unset($data);
        }
        return $snaps;
    }
    public static function record(string $dir,array $report,array $snapshots):array {
        $path=rtrim($dir,'/').'/trade-shadow-audit.json';
        $now=time();$old=[];
        if(is_file($path)){
            $decoded=json_decode((string)file_get_contents($path),true);
            if(is_array($decoded))$old=$decoded['events']??[];
        }
        $events=array_values(array_filter($old,static fn($e)=>is_array($e)&&isset($e['timestamp'])&&(int)$e['timestamp']>=$now-self::WINDOW));
        $rows=[];
        // Recheck the preceding publication's orders against today's snapshots.
        // This estimates quote survival, not whether any player actually traded.
        $priorChecks=[];
        if($events){
            $previous=$events[count($events)-1];
            foreach(($previous['rows']??[]) as $prev){
                if(empty($prev['quote_evidence']))continue;
                $shadow=['type_id'=>$prev['type_id'],'quote_evidence'=>$prev['quote_evidence'],
                    'quantity_units'=>$prev['qty']??0];
                $priorChecks[]=array_merge(['type_id'=>$prev['type_id'],'strategy'=>$prev['strategy']],
                    self::observed($shadow,$snapshots));
            }
        }
        foreach(($report['orders']??[]) as $order){
            if(!is_array($order)||!isset($order['type_id']))continue;
            $rows[]=['type_id'=>(int)$order['type_id'],'item'=>(string)($order['item']??''),
                'strategy'=>(string)($order['title']??''),'source_station'=>(int)($order['source_id']??0),
                'destination_station'=>(int)($order['destination_id']??0),'qty'=>self::quantity($order),
                'net_isk'=>(float)($order['revenue_isk']??0)-(float)($order['cost_isk']??0)-(float)($order['lp_required']??0)*1000-(float)($order['sales_tax_isk']??0)-(float)($order['broker_fee_isk']??0),
                'conditional'=>!empty($order['buy_order'])||!empty($order['listing'])||($order['status']??'')==='conditional',
                'quote_evidence'=>$order['quote_evidence']??null,
                'verification'=>self::observed($order,$snapshots)];
        }
        $events[]=['timestamp'=>$now,'published_as_of'=>$report['as_of']??null,'coverage_met'=>!empty($report['coverage']['selection_requirements_met']),
            'selected_lp_count'=>(int)($report['coverage']['selected_lp_count']??0),'prior_quote_checks'=>$priorChecks,'rows'=>$rows];
        if(count($events)>self::MAX_EVENTS)$events=array_slice($events,-self::MAX_EVENTS);
        $summary=self::summarize($events);
        $payload=json_encode(['schema_version'=>1,'updated_at'=>gmdate('c',$now),'summary'=>$summary,'events'=>$events],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        // Atomic replacement in a transient file, cleaned immediately; not a backup.
        $tmp=$path.'.writing';
        if(file_put_contents($tmp,$payload,LOCK_EX)===false||!rename($tmp,$path))throw new RuntimeException('Unable to save trade audit');
        return $summary;
    }
    public static function summarize(array $events):array{
        $count=0;$sampled=0;$missing=0;$changed=0;$possible=0;$covered=0;$conditional=0;$profitChanged=0;$profitImproved=0;$profitDeclined=0;$priorProfits=[];$priorSamples=0;$priorStill=0;$priorChanges=0;$repeats=[];
        foreach($events as $event){$count++;if(!empty($event['coverage_met']))$covered++;
            foreach($event['prior_quote_checks']??[] as $p){
                if((int)($p['existing_orders_expected']??0)<1)continue;
                $priorSamples++;
                if(!(int)($p['orders_unverified']??0)&&!(int)($p['orders_changed']??0))$priorStill++;
                else $priorChanges++;
            }
            foreach($event['rows']??[] as $r){$sampled++;$v=$r['verification']??[];
                $missing+=(int)($v['orders_unverified']??0);$changed+=(int)($v['orders_changed']??0);
                $possible+=(int)($v['orders_checked']??0);if(!empty($r['conditional']))$conditional++;
                $k=$r['type_id'].':'.$r['strategy'];$repeats[$k]=($repeats[$k]??0)+1;
                if(isset($priorProfits[$k])){
                    $diff=(float)($r['net_isk']??0)-$priorProfits[$k];
                    if(abs($diff)>1){$profitChanged++;if($diff>0)$profitImproved++;else $profitDeclined++;}
                }
                $priorProfits[$k]=(float)($r['net_isk']??0);
            }
        }
        arsort($repeats);
        return ['observations'=>$count,'recommendations_observed'=>$sampled,'coverage_met_runs'=>$covered,
            'existing_orders_checked'=>$possible,'existing_orders_unverified'=>$missing,'existing_orders_changed'=>$changed,
            'prior_publication_quotes_rechecked'=>$priorSamples,'prior_quotes_still_available'=>$priorStill,
            'prior_quotes_changed_or_missing'=>$priorChanges,
            'profit_change_observations'=>$profitChanged,'profit_improved_observations'=>$profitImproved,
            'profit_declined_observations'=>$profitDeclined,
            'conditional_recommendations_observed'=>$conditional,'distinct_recommendations'=>count($repeats),
            'first_observation_at'=>$events?gmdate('c',(int)$events[0]['timestamp']):null,
            'last_observation_at'=>$events?gmdate('c',(int)$events[count($events)-1]['timestamp']):null,
            'days_observed'=>$events?round(((int)$events[count($events)-1]['timestamp']-(int)$events[0]['timestamp'])/86400,2):0,
            'warning'=>'These are snapshot observations, not completed trades. Current order presence does not prove a future fill. Regional history does not prove station-level execution.'];
    }
}
