<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
$dir=sys_get_temp_dir().'/guri-v21-test-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);
try{
    $intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(GuristasMarketIntelligence::class,'dir'))->setValue($intel,$dir);
    $put=static fn($key,$value)=>file_put_contents($dir.'/'.$key.'.json',json_encode($value,JSON_THROW_ON_ERROR));
    $now=gmdate('c');$snapshot=static fn($rows)=>['complete'=>true,'fetched_at'=>$now,'orders'=>$rows];
    $ref=['types'=>['101'=>['name'=>'Test Hull'],'34'=>['name'=>'Tritanium'],'2346'=>['name'=>'Synthetic Synapses'],'11399'=>['name'=>'Morphite']],
        'blueprints'=>['999'=>['name'=>'Test Blueprint','product'=>['typeID'=>101,'quantity'=>1],'materials'=>[['typeID'=>34,'quantity'=>3],['typeID'=>2346,'quantity'=>2]]],
        '17931'=>['name'=>'Worm Blueprint','product'=>['typeID'=>17930,'quantity'=>1],'materials'=>[['typeID'=>34,'quantity'=>9]]]]];
    $ask1=['t'=>34,'l'=>60003760,'p'=>100,'v'=>30,'b'=>false,'i'=>9001];$ask2=['t'=>2346,'l'=>60003760,'p'=>30,'v'=>20,'b'=>false,'i'=>9003];
    $bid=['t'=>101,'l'=>60009999,'p'=>3000,'v'=>5,'m'=>1,'b'=>true,'r'=>'station','i'=>9002];
    $put('region-10000002',$snapshot([$ask1,$ask2]));$put('region-10000015',$snapshot([$bid]));$put('region-10001000',$snapshot([]));
    $put('lp-offers',['fetched_at'=>$now,'offers'=>[['type_id'=>999,'lp_cost'=>1,'isk_cost'=>100,'ak_cost'=>0,'required_items'=>[]]]]);
    $r=(new GuristasBuildIntelligence($intel,$ref))->collect();
    if(count($r['orders'])!==3 || array_column($r['orders'],'activity')!==['manufacture','mine','pi'])throw new RuntimeException('Three activity slots failed');
    if($r['orders'][0]['state']!=='market_lead')throw new RuntimeException('Qualified manufacture failed');
    if($r['orders'][0]['metric_isk']<=0)throw new RuntimeException('Positive margin failed');
    $put('region-10000015',$snapshot([array_replace($bid,['r'=>'region'])]));
    $r=(new GuristasBuildIntelligence($intel,$ref))->collect();
    if($r['orders'][0]['state']!=='supply_objective'||$r['orders'][0]['title']!=='Worm blueprint supply chain')throw new RuntimeException('Fallback or station-range check failed');
    $put('region-10000002',$snapshot([]));$r=(new GuristasBuildIntelligence($intel,$ref))->collect();
    if($r['orders'][1]['state']!=='supply_objective')throw new RuntimeException('Mining fallback failed');
    $stale=$snapshot([]);$stale['fetched_at']=gmdate('c',time()-16000);$put('region-10000015',$stale);
    $r=(new GuristasBuildIntelligence($intel,$ref))->collect();
    if($r['orders'][2]['state']!=='supply_objective')throw new RuntimeException('PI fallback failed');
    echo "PASS BUILD v21: three slots, market lead, bid range, and independent fallbacks\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
