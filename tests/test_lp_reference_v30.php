<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/TradeOrders.php';
function ensureLP(bool $cond,string $why):void{if(!$cond)throw new RuntimeException($why);}
$dir=sys_get_temp_dir().'/guristas-v30-'.bin2hex(random_bytes(6));
if(!mkdir($dir,0700))throw new RuntimeException('Fixture directory unavailable');
$write=static function(string $key,array $data)use($dir):void{file_put_contents($dir.'/'.$key.'.json',json_encode($data,JSON_THROW_ON_ERROR));};
try{
    $now=gmdate('c');
    $write('regions',['ids'=>[10000002,10000015,10001000],'fetched_at'=>$now]);
    foreach([10000002,10000015,10001000] as $region){
        $orders=[];
        if($region===10000002)$orders[]=['t'=>12345,'l'=>60003760,'p'=>900000,'v'=>25,'b'=>false,'i'=>111];
        $write('region-'.$region,['complete'=>true,'fetched_at'=>$now,'pages'=>1,'orders'=>$orders]);
    }
    $write('lp-offers',['fetched_at'=>$now,'offers'=>[
        ['type_id'=>12345,'quantity'=>1,'isk_cost'=>100000,'lp_cost'=>1000,'ak_cost'=>0,'required_items'=>[]]
    ]]);
    $days=[];for($i=0;$i<14;$i++)$days[]=['date'=>gmdate('Y-m-d',time()-$i*86400),'volume'=>25,'average'=>900000];
    $write('history-10000002-12345',['region_id'=>10000002,'type_id'=>12345,'verified'=>true,'collected_at'=>$now,'days'=>$days]);
    $marketClass=new ReflectionClass(GuristasMarketIntelligence::class);
    $market=$marketClass->newInstanceWithoutConstructor();
    $marketClass->getProperty('dir')->setValue($market,$dir);
    $ref=['types'=>['12345'=>['name'=>'Test LP reward']],'blueprints'=>[],
        'hubs'=>['fulcrum'=>['station_id'=>60000001]]];
    $report=(new GuristasTradeOrders($market,$ref))->collect();
    $lp=$report['orders'][2]??[];
    ensureLP(($lp['status']??'standby')==='conditional','LP reference-backed conditional listing not produced');
    ensureLP(($lp['homepage_slot']??'')==='GURISTAS_LP','LP reference displaced from its required slot');
    ensureLP(str_contains((string)$lp['margin'],'ISK/LP'),'LP result does not display cash yield per LP');
    ensureLP(($report['coverage']['lp_reference_listings']??0)>=1,'No independently priced reference listing');
    ensureLP(empty($lp['quote_evidence']['station_sales_verified']),'A listing falsely presented as verified station sales');
    echo "PASS v30: LP offer qualifies from Jita listing and regional trades without a buy order; conditional status and cash yield preserved\n";
}finally{
    foreach(glob($dir.'/*.json')?:[] as $file)unlink($file);
    rmdir($dir);
}
