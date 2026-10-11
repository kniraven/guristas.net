<?php
declare(strict_types=1);
require_once __DIR__.'/PublicIndustry.php';
require_once __DIR__.'/MarketPageClient.php';

/** Public ESI market-book snapshots. All network activity is CLI-only. */
final class GuristasMarketIntelligence {
    private GuristasEsiClient $esi;
    private string $dir;
    private ?GuristasMarketPageClient $pageClient=null;
    public function __construct(?GuristasEsiClient $esi=null, ?string $dir=null) {
        if($esi!==null){$this->esi=$esi;}else{
            $config=require dirname(__DIR__,2).'/config/esi.php';
            $this->esi=new GuristasEsiClient($config,new GuristasEsiCache(dirname(__DIR__,2).'/storage/cache/public-industry'));
        }
        $this->dir=$dir??dirname(__DIR__,2).'/storage/cache/economic-intelligence';
        if(get_class($this->esi)===GuristasEsiClient::class){
            $config=require dirname(__DIR__,2).'/config/esi.php';
            $this->pageClient=new GuristasMarketPageClient($config,$this->dir.'/pages');
        }
    }
    public function dir(): string {return $this->dir;}
    public function read(string $name): ?array {
        if(!preg_match('/^[a-z0-9-]+$/',$name))throw new InvalidArgumentException('Invalid cache key');
        $file=$this->dir.'/'.$name.'.json';
        if(!is_file($file))return null;
        $v=json_decode((string)file_get_contents($file),true);
        return is_array($v)?$v:null;
    }
    public function publish(string $name,array $value): void {
        if(!preg_match('/^[a-z0-9-]+$/',$name))throw new InvalidArgumentException('Invalid cache key');
        if(!is_dir($this->dir)&&!mkdir($this->dir,0775,true)&&!is_dir($this->dir))throw new RuntimeException('Intelligence directory unavailable');
        $tmp=tempnam($this->dir,'write-');
        if(!$tmp||dirname($tmp)!==$this->dir)throw new RuntimeException('Intelligence directory is not writable');
        try{
            $content=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if(file_put_contents($tmp,$content,LOCK_EX)===false)throw new RuntimeException('Unable to write snapshot');
            if(!chmod($tmp,0640)||!rename($tmp,$this->dir.'/'.$name.'.json'))throw new RuntimeException('Unable to publish readable snapshot');
        }finally{if(is_file($tmp))unlink($tmp);}
    }
    /** External regions only need orders for Fulcrum/Venal demand and Guristas LP supply. */
    private function relevantTypes(): array {
        $types=[];
        foreach([10001000,10000015] as $region){
            $s=$this->read('region-'.$region);
            if(!$s||empty($s['complete']))throw new RuntimeException('Collect Zarzakh and Venal before scanning external markets');
            foreach($s['orders']??[] as $o){
                if(!empty($o['b']) && (int)($o['t']??0)>0)$types[(int)$o['t']]=true;
            }
        }
        $offers=$this->read('lp-offers');
        if(!$offers||!is_array($offers['offers']??null))throw new RuntimeException('Collect LP offers before scanning external markets');
        $reference=guri_industry_reference();
        foreach($offers['offers'] as $offer){
            $type=(int)($offer['type_id']??0);
            if($type>0)$types[$type]=true;
            foreach($offer['required_items']??[] as $input){
                $id=(int)($input['type_id']??$input['typeID']??0);
                if($id>0)$types[$id]=true;
            }
            $bp=$reference['blueprints'][(string)$type]??null;
            if($bp){
                $product=(int)($bp['product']['typeID']??0);
                if($product>0)$types[$product]=true;
                foreach($bp['materials']??[] as $material){
                    $id=(int)($material['type_id']??$material['typeID']??0);
                    if($id>0)$types[$id]=true;
                }
            }
        }
        return $types;
    }
    private function marketPage(int $region,int $page):array{
        return $this->pageClient!==null?$this->pageClient->getPage($region,$page):$this->esi->getJson('/markets/'.$region.'/orders',['order_type'=>'all','page'=>$page],300);
    }
    public function collectRegion(int $region): array {
        if($region<10000001||$region>11000000)throw new InvalidArgumentException('Invalid market region');
        $priority=in_array($region,[10001000,10000015],true);
        $relevant=$priority?null:$this->relevantTypes();
        $path='/markets/'.$region.'/orders';
        $first=$this->marketPage($region,1);
        $pages=(int)($first['meta']['pages']??0);
        if(!is_array($first['data']??null)||!empty($first['meta']['stale'])||$pages<1||$pages>750)throw new RuntimeException('Missing, stale or too-large regional order book: '.$region);
        $oldest=strtotime((string)($first['meta']['fetched_at']??''));
        if($oldest===false||$oldest<time()-900)throw new RuntimeException('Market first page is stale');
        $orders=[];$seen=0;
        // Limit the number of outside-region quotes kept per item/station/side.
        // Highest bids and lowest asks are most relevant for executable trades.
        $grouped=[];
        $consume=function(array $r,int $p) use($pages,$priority,$relevant,&$orders,&$seen,&$oldest,&$grouped):void {
            if(!is_array($r['data']??null)||!empty($r['meta']['stale'])||(int)($r['meta']['pages']??0)!==$pages)
                throw new RuntimeException('Partial/changed market pages; old snapshot retained');
            $t=strtotime((string)($r['meta']['fetched_at']??''));
            if($t===false||$t<time()-900)throw new RuntimeException('Stale market page; old snapshot retained');
            $oldest=min($oldest,$t);
            foreach($r['data'] as $o){
                if(!is_array($o)||!isset($o['type_id'],$o['location_id'],$o['price'],$o['volume_remain'],$o['is_buy_order']))continue;
                $seen++;
                $type=(int)$o['type_id'];
                if($relevant!==null&&!isset($relevant[$type]))continue;
                $price=(float)$o['price'];$volume=(int)$o['volume_remain'];
                $station=(int)$o['location_id'];
                if(!is_finite($price)||$price<=0||$volume<1||$station<60000000||$station>=61000000)continue;
                $row=['t'=>$type,'l'=>$station,'p'=>$price,'v'=>$volume,'m'=>max(1,(int)($o['min_volume']??1)),'b'=>(bool)$o['is_buy_order'],'r'=>$o['range']??'station'];
                if($priority){$orders[]=$row;continue;}
                $key=$type.':'.(int)$row['b'];
                $grouped[$key][]=$row;
                if(count($grouped[$key])>24){
                    usort($grouped[$key],static fn($a,$b)=>$a['b']?($b['p']<=>$a['p']):($a['p']<=>$b['p']));
                    array_pop($grouped[$key]);
                }
            }
        };
        $consume($first,1);
        unset($first);
        // Process and release each page before fetching the next.  This avoids the
        // old implementation's double-copy of the entire Jita order book.
        for($p=2;$p<=$pages;$p++){
            $r=$this->marketPage($region,$p);
            $consume($r,$p);
            unset($r);
        }
        if(!$priority){foreach($grouped as $rows)foreach($rows as $row)$orders[]=$row;unset($grouped);}
        $snapshot=['region'=>$region,'fetched_at'=>gmdate('c',$oldest),'collected_at'=>gmdate('c'),'pages'=>$pages,'orders'=>$orders,'complete'=>true,'source'=>'CCP ESI',
            'total_seen'=>$seen,'retention'=>$priority?'all_npc_orders':'up_to_24_best_quotes_per_item_side',
            'relevant_types'=>$relevant===null?null:count($relevant)];
        $this->publish('region-'.$region,$snapshot);
        return ['region'=>$region,'pages'=>$pages,'orders'=>count($orders),'seen'=>$seen];
    }
    public function regionIds(): array {
        $saved=$this->read('regions');
        if($saved&&isset($saved['ids'])&&time()-strtotime((string)$saved['fetched_at'])<604800)return $saved['ids'];
        $r=$this->esi->getJson('/universe/regions',[],86400);
        if(!is_array($r['data']??null))throw new RuntimeException('Unable to discover regions');
        $ids=array_values(array_filter(array_map('intval',$r['data']),static fn($id)=>$id>=10000001&&$id<11000000));
        sort($ids);
        $this->publish('regions',['ids'=>$ids,'fetched_at'=>gmdate('c')]);
        return $ids;
    }
    /** Scheduled-only history lookup; CCP history is regional, not station-level. */
    public function marketHistory(int $region,int $type):?array{
        if($region<10000001||$region>=11000000||$type<1)return null;
        $key='history-'.$region.'-'.$type;
        $saved=$this->read($key);
        $age=$saved?time()-(strtotime((string)($saved['collected_at']??''))?:0):PHP_INT_MAX;
        if($age>=0&&$age<86400)return !empty($saved['verified'])?$saved:null;
        try{
            $response=$this->esi->getJson('/markets/'.$region.'/history',['type_id'=>$type],3600);
            if(!is_array($response['data']??null)||!empty($response['meta']['stale']))throw new RuntimeException('Unavailable historical market data');
            $days=[];
            foreach($response['data'] as $row){
                if(!is_array($row)||!isset($row['date'],$row['volume'],$row['average']))continue;
                $days[]=['date'=>(string)$row['date'],'volume'=>(int)$row['volume'],'average'=>(float)$row['average']];
            }
            $saved=['region_id'=>$region,'type_id'=>$type,'verified'=>true,'days'=>$days,
                'collected_at'=>gmdate('c'),'source'=>'CCP ESI regional completed market history'];
            $this->publish($key,$saved);
            return $saved;
        }catch(Throwable $e){
            // Negative caching avoids retrying unsupported regions on every publish.
            $this->publish($key,['region_id'=>$region,'type_id'=>$type,'verified'=>false,
                'collected_at'=>gmdate('c'),'reason'=>'CCP regional history currently unavailable']);
            return null;
        }
    }
    public function refreshOffers(): array {
        $r=(new GuristasPublicIndustry())->offers();
        if(!is_array($r['data']??null)||!empty($r['meta']['stale'])||!empty($r['meta']['snapshot']))throw new RuntimeException('Current Commando Guri offers unavailable');
        $this->publish('lp-offers',['fetched_at'=>$r['meta']['fetched_at']??gmdate('c'),'offers'=>$r['data'],'source'=>'CCP ESI / Commando Guri LP store']);
        return ['offers'=>count($r['data'])];
    }
    /** Resolve published orders on the collector, never in visitor requests. */
    public function decorateTrade(array &$report): void {
        $reference=guri_industry_reference();
        $stations=[];
        foreach(($reference['hubs']??[]) as $hub){
            $id=(int)($hub['station_id']??0);
            if($id>0&&is_string($hub['name']??null)){
                $stations[$id]=['name'=>$hub['name'],'system_id'=>(int)($hub['system_id']??0),'system'=>$hub['system_name']??null];
            }
        }
        $names=[];
        $needed=[];
        foreach(($report['orders']??[]) as $order){
            if(($order['status']??'standby')==='standby')continue;
            foreach(['type_id','source_id','destination_id'] as $field){
                $id=(int)($order[$field]??0);
                if($id>0)$needed[$id]=true;
            }
        }
        // Public ESI supports bulk type/station/system ID-to-name resolution.
        // A single-item endpoint may be unavailable despite a valid market type.
        if($needed){
            $config=require dirname(__DIR__,2).'/config/esi.php';
            if(($config['base_url']??'')==='https://esi.evetech.net'&&extension_loaded('curl')){
                foreach(array_chunk(array_keys($needed),500) as $ids){
                    $ch=curl_init('https://esi.evetech.net/universe/names/?datasource=tranquility');
                    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($ids,JSON_THROW_ON_ERROR),
                        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
                        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','User-Agent: '.($config['user_agent']??'Guristas.net')]]);
                    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
                    if($status!==200||!is_string($body))continue;
                    $rows=json_decode($body,true);
                    if(!is_array($rows))continue;
                    foreach($rows as $row){
                        $id=(int)($row['id']??0);$name=$row['name']??null;
                        if($id>0&&is_string($name)&&trim($name)!=='')$names[$id]=trim($name);
                    }
                }
            }
        }
        $systems=[];
        foreach($stations as $station){
            $id=(int)$station['system_id'];
            if($id>0&&is_string($station['system'])&&$station['system']!=='')$systems[$id]=$station['system'];
        }
        $typeNames=[];
        $constellations=[];$regions=[];$systemRegions=[];
        $resolve=function(int $id,string $route)use(&$names):?array{
            try{
                $r=$this->esi->getJson($route.'/'.$id,[],86400);
                return is_array($r['data']??null)?$r['data']:null;
            }catch(Throwable $e){return null;}
        };
        foreach($report['orders'] as &$order){
            if(($order['status']??'standby')==='standby')continue;
            $type=(int)($order['type_id']??0);
            if(!array_key_exists($type,$typeNames)){
                $name=$reference['types'][(string)$type]['name']??($names[$type]??null);
                if(!is_string($name)||trim($name)==='')$name=$resolve($type,'/universe/types')['name']??null;
                $typeNames[$type]=is_string($name)&&trim($name)!==''?trim($name):null;
            }
            $order['item']=$typeNames[$type]??'ITEM NAME UNVERIFIED';
            $place=[];
            foreach(['source_id'=>'source','destination_id'=>'destination'] as $key=>$role){
                $id=(int)($order[$key]??0);
                if($id<=0)continue;
                if(!isset($stations[$id])||empty($stations[$id]['system_id'])){
                    $info=$resolve($id,'/universe/stations');
                    $name=$info['name']??($names[$id]??($stations[$id]['name']??null));
                    $sid=(int)($info['system_id']??($stations[$id]['system_id']??0));
                    $stations[$id]=['name'=>$name,'system_id'=>$sid,'system'=>$stations[$id]['system']??null];
                }
                $s=$stations[$id];$sid=(int)$s['system_id'];
                if($sid>0&&!isset($systems[$sid])){
                    $systems[$sid]=$names[$sid]??($resolve($sid,'/universe/systems')['name']??null);
                }
                $system=$sid>0?($systems[$sid]??null):null;
                // System -> constellation -> region. Never infer a region from station text.
                if($sid>0&&!array_key_exists($sid,$systemRegions)){
                    $systemInfo=$resolve($sid,'/universe/systems');
                    $constellationId=(int)($systemInfo['constellation_id']??0);
                    $regionId=0;
                    if($constellationId>0){
                        if(!array_key_exists($constellationId,$constellations)){
                            $constellationInfo=$resolve($constellationId,'/universe/constellations');
                            $constellations[$constellationId]=(int)($constellationInfo['region_id']??0);
                        }
                        $regionId=$constellations[$constellationId];
                    }
                    if($regionId>0&&!array_key_exists($regionId,$regions)){
                        $regionInfo=$resolve($regionId,'/universe/regions');
                        $regionName=$regionInfo['name']??($names[$regionId]??null);
                        $regions[$regionId]=is_string($regionName)&&trim($regionName)!==''?trim($regionName):null;
                    }
                    $systemRegions[$sid]=$regionId>0?($regions[$regionId]??null):null;
                }
                $region=$sid>0?($systemRegions[$sid]??null):null;
                $place[$role]=['station'=>$s['name']??null,'system'=>$system,'region'=>$region,
                    'verified'=>is_string($s['name']??null)&&($s['name']??'')!==''&&is_string($system)&&$system!==''&&is_string($region)&&$region!==''];
            }
            $kind=strtoupper((string)($order['category']??''));
            $lp=str_contains($kind,'LP REDEEM');$build=str_contains($kind,'LP BUILD');
            $order['method']=$build?'MANUFACTURE FROM LP BLUEPRINT':($lp?'REDEEM GURISTAS LP':'PURCHASE FROM SELL ORDERS');
            $src=$place['source']??null;$dst=$place['destination']??null;
            $order['source_station']=$src['station']??null;$order['source_system']=$src['system']??null;$order['source_region']=$src['region']??null;
            $order['destination_station']=$dst['station']??null;$order['destination_system']=$dst['system']??null;$order['destination_region']=$dst['region']??null;
            $complete=($lp||$build||($src['verified']??false))&&($dst['verified']??false)&&$typeNames[$type]!==null;
            $order['location_verified']=$complete;
            $order['route']=($src?($src['station']??'UNKNOWN STATION').' ('.($src['system']??'UNKNOWN SYSTEM').' / '.($src['region']??'UNKNOWN REGION').')':$order['method']).' → '.($dst['station']??'UNKNOWN STATION').' ('.($dst['system']??'UNKNOWN SYSTEM').' / '.($dst['region']??'UNKNOWN REGION').')';
            if(!$complete){
                $order['status']='conditional';
                $order['reason'].=' ITEM / LOCATION INCOMPLETE: Do not execute until the item and both required locations are confirmed in EVE.';
            }
            // Extreme quoted returns often indicate thin, manipulated or non-repeatable orders.
            // Never present these as ordinary, fully executable recommendations.
            $roi=(float)($order['roi_ratio']??0);
            if($roi>10.0){
                $order['status']='conditional';
                $order['reason'].=' EXTREME ROI WARNING: Verify actual source ask, destination bid, minimum fill, available depth and transport before risking ISK.';
            }
        }
        unset($order);
    }
    /** Same documented ranking as the previous browser implementation, computed at collection time. */
    private function raidTarget(array $data): array {
        $campaigns=$data['campaigns']??[];
        if(!is_array($campaigns)||count($campaigns)!==1||($campaigns[0]['state']??'')!=='ACTIVE'||!empty($campaigns[0]['ended_at']))
            return ['title'=>'HOLD POSITION','detail'=>'No single confirmed active insurgency.'];
        $systems=is_array($data['systems']??null)?$data['systems']:[];
        $eligible=array_values(array_filter($systems,static fn($s)=>is_array($s)&&isset($s['id'],$s['corruption_stage'])
            &&is_int($s['corruption_stage'])&&$s['corruption_stage']>=0&&$s['corruption_stage']<5));
        if(!$eligible)return ['title'=>'HOLD POSITION','detail'=>'No eligible unfinished corruption target was verified.'];
        $adj=[];$dist=[];$queue=[];
        foreach($systems as $s){$id=(int)($s['id']??0);if($id<1)continue;$adj[$id]=[];
            if(($s['highsec_adjacent']??false)===true){$dist[$id]=0;$queue[]=$id;}
        }
        foreach($data['edges']??[] as $edge){$a=(int)($edge['a']??0);$b=(int)($edge['b']??0);
            if(isset($adj[$a],$adj[$b])){$adj[$a][]=$b;$adj[$b][]=$a;}
        }
        for($i=0;$i<count($queue);$i++){
            $id=$queue[$i];foreach($adj[$id]??[] as $next)if(!isset($dist[$next])){$dist[$next]=$dist[$id]+1;$queue[]=$next;}
        }
        usort($eligible,static function($a,$b)use($dist){
            return $b['corruption_stage']<=>$a['corruption_stage']
              ?: (is_numeric($b['corruption_percent']??null)?(float)$b['corruption_percent']:-1)<=> (is_numeric($a['corruption_percent']??null)?(float)$a['corruption_percent']:-1)
              ?: ($dist[(int)$a['id']]??PHP_INT_MAX)<=>($dist[(int)$b['id']]??PHP_INT_MAX)
              ?: strcmp((string)($a['name']??''),(string)($b['name']??''));
        });
        $winner=$eligible[0];$id=(int)$winner['id'];$distance=$dist[$id]??null;
        $near=$distance===0?'Direct high-sec gate':($distance!==null?$distance.' insurgency jumps to a high-sec gate':'High-sec proximity not verified');
        $percent=is_numeric($winner['corruption_percent']??null)?' · '.number_format((float)$winner['corruption_percent'],1).'%':'';
        return ['title'=>'DEPLOY TO '.($winner['name']??'SYSTEM '.$id).' // RAISE CORRUPTION',
            'detail'=>'Stage '.$winner['corruption_stage'].'/5'.$percent.'. '.$near.'. Highest unfinished corruption progress.'];
    }
    public function refreshRaid(): array {
        require_once __DIR__.'/DataServices.php';
        $r=(new GuristasDataServices())->frontlines()->guristasOverlay();
        if(!is_array($r['data']??null)||!is_array($r['meta']??null))throw new RuntimeException('Invalid insurgency report');
        $r['data']['server_recommendation']=$this->raidTarget($r['data']);
        $this->publish('raid',['data'=>$r['data'],'meta'=>$r['meta'],'sources'=>$r['sources']??[],'collected_at'=>gmdate('c')]);
        return ['campaigns'=>count($r['data']['campaigns']??[])];
    }
}
