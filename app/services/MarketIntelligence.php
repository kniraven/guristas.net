<?php
declare(strict_types=1);
require_once __DIR__.'/PublicIndustry.php';

/** Public ESI market-book snapshots. All network activity is CLI-only. */
final class GuristasMarketIntelligence {
    private GuristasEsiClient $esi;
    private string $dir;
    public function __construct(?GuristasEsiClient $esi=null, ?string $dir=null) {
        if($esi!==null){$this->esi=$esi;}else{
            $config=require dirname(__DIR__,2).'/config/esi.php';
            $this->esi=new GuristasEsiClient($config,new GuristasEsiCache(dirname(__DIR__,2).'/storage/cache/public-industry'));
        }
        $this->dir=$dir??dirname(__DIR__,2).'/storage/cache/economic-intelligence';
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
        if(!$tmp)throw new RuntimeException('Unable to create temporary snapshot');
        try{
            $content=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if(file_put_contents($tmp,$content,LOCK_EX)===false||!rename($tmp,$this->dir.'/'.$name.'.json'))throw new RuntimeException('Unable to publish snapshot');
        }finally{if(is_file($tmp))unlink($tmp);}
    }
    public function collectRegion(int $region): array {
        if($region<10000001||$region>11000000)throw new InvalidArgumentException('Invalid market region');
        $path='/markets/'.$region.'/orders';
        $first=$this->esi->getJson($path,['order_type'=>'all','page'=>1],300);
        $pages=(int)($first['meta']['pages']??0);
        if(!is_array($first['data']??null)||!empty($first['meta']['stale'])||$pages<1||$pages>750)throw new RuntimeException('Missing, stale or too-large regional order book: '.$region);
        $reports=[1=>$first];$oldest=strtotime((string)($first['meta']['fetched_at']??''));
        if($oldest===false||$oldest<time()-900)throw new RuntimeException('Market first page is stale');
        for($start=2;$start<=$pages;$start+=8){
            $req=[];
            for($p=$start;$p<=min($pages,$start+7);$p++)$req[]=['key'=>(string)$p,'path'=>$path,'query'=>['order_type'=>'all','page'=>$p],'fallback_ttl_seconds'=>300];
            foreach($this->esi->getJsonBatch($req,8) as $p=>$report)$reports[(int)$p]=$report;
        }
        $orders=[];
        for($p=1;$p<=$pages;$p++){
            $r=$reports[$p]??null;
            if(!$r||!is_array($r['data']??null)||!empty($r['meta']['stale'])||(int)($r['meta']['pages']??0)!==$pages)throw new RuntimeException('Partial/changed market pages; old snapshot retained');
            $t=strtotime((string)($r['meta']['fetched_at']??''));
            if($t===false||$t<time()-900)throw new RuntimeException('Stale market page; old snapshot retained');
            $oldest=min($oldest,$t);
            foreach($r['data'] as $o){
                if(!is_array($o)||!isset($o['type_id'],$o['location_id'],$o['price'],$o['volume_remain'],$o['is_buy_order']))continue;
                $price=(float)$o['price'];$volume=(int)$o['volume_remain'];
                if(!is_finite($price)||$price<=0||$volume<1)continue;
                $orders[]=['t'=>(int)$o['type_id'],'l'=>(int)$o['location_id'],'p'=>$price,'v'=>$volume,'m'=>max(1,(int)($o['min_volume']??1)),'b'=>(bool)$o['is_buy_order'],'r'=>$o['range']??'station'];
            }
        }
        $snapshot=['region'=>$region,'fetched_at'=>gmdate('c',$oldest),'collected_at'=>gmdate('c'),'pages'=>$pages,'orders'=>$orders,'complete'=>true,'source'=>'CCP ESI'];
        $this->publish('region-'.$region,$snapshot);
        return ['region'=>$region,'pages'=>$pages,'orders'=>count($orders)];
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
    public function refreshOffers(): array {
        $r=(new GuristasPublicIndustry())->offers();
        if(!is_array($r['data']??null)||!empty($r['meta']['stale'])||!empty($r['meta']['snapshot']))throw new RuntimeException('Current Commando Guri offers unavailable');
        $this->publish('lp-offers',['fetched_at'=>$r['meta']['fetched_at']??gmdate('c'),'offers'=>$r['data'],'source'=>'CCP ESI / Commando Guri LP store']);
        return ['offers'=>count($r['data'])];
    }
    /** Resolve only displayed stations/items after analysis, never thousands of market IDs. */
    public function decorateTrade(array &$report): void {
        $hubs=guri_industry_reference()['hubs'];$known=[];
        foreach($hubs as $hub)$known[(int)$hub['station_id']]=(string)$hub['name'];
        foreach($report['orders']??[] as &$order){
            if(($order['status']??'')==='standby')continue;
            $stations=[];
            foreach(['source_id','destination_id'] as $field){
                $id=(int)($order[$field]??0);if($id<1)continue;
                if(!isset($known[$id])){
                    try{$r=$this->esi->getJson('/universe/stations/'.$id,[],86400);
                        $known[$id]=is_string($r['data']['name']??null)?$r['data']['name']:'NPC station #'.$id;
                    }catch(Throwable $e){$known[$id]='NPC station #'.$id;}
                }
                $stations[$field]=$known[$id];
            }
            $id=(int)($order['type_id']??0);
            if($id>0&&str_starts_with((string)($order['item']??''),'Item #')){
                try{$r=$this->esi->getJson('/universe/types/'.$id,[],86400);
                    if(is_string($r['data']['name']??null))$order['item']=$r['data']['name'];
                }catch(Throwable $e){}
            }
            $prefix=str_contains((string)$order['title'],'REDEEM')?'REDEEM LP + INPUTS':
                (str_contains((string)$order['title'],'BUILD')?'MANUFACTURE FROM LP BPC':'ACQUIRE');
            $order['route']=$prefix.' → '.($stations['source_id']??'Guristas LP store').' → SELL AT '.($stations['destination_id']??'unverified station');
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
