<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
$dir=sys_get_temp_dir().'/guri-build-test-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);
try {
    $intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(GuristasMarketIntelligence::class,'dir'))->setValue($intel,$dir);
    $ref=['types'=>['101'=>['name'=>'Test Guristas Hull']],'blueprints'=>['999'=>['name'=>'Test Blueprint','product'=>['typeID'=>101,'quantity'=>1],'materials'=>[['typeID'=>11,'quantity'=>3]]]]];
    $now=gmdate('c');
    $put=static function($key,$value)use($dir){file_put_contents($dir.'/'.$key.'.json',json_encode($value,JSON_THROW_ON_ERROR));};
    $snapshot=static fn($rows)=>['complete'=>true,'fetched_at'=>$now,'orders'=>$rows];
    $ask=['t'=>11,'l'=>60003760,'p'=>100,'v'=>3,'b'=>false,'i'=>9001];
    $bid=['t'=>101,'l'=>60009999,'p'=>3000,'v'=>5,'m'=>1,'b'=>true,'r'=>'station','i'=>9002];
    $put('region-10000002',$snapshot([$ask]));$put('region-10000015',$snapshot([$bid]));$put('region-10001000',$snapshot([]));
    $put('lp-offers',['fetched_at'=>$now,'offers'=>[['type_id'=>999,'lp_cost'=>1,'isk_cost'=>100,'ak_cost'=>0,'required_items'=>[],'offer_id'=>42]]]);
    $report=(new GuristasBuildIntelligence($intel,$ref))->collect();
    if(count($report['orders'])!==1||$report['orders'][0]['gross_material_spread_isk']!==2700.0)throw new RuntimeException('Valid quote failed');
    $put('region-10000002',$snapshot([]));
    if((new GuristasBuildIntelligence($intel,$ref))->collect()['orders']!==[])throw new RuntimeException('Missing material quote should reject');
    $put('region-10000002',$snapshot([$ask]));
    $put('region-10000015',$snapshot([array_replace($bid,['r'=>'region'])]));
    if((new GuristasBuildIntelligence($intel,$ref))->collect()['orders']!==[])throw new RuntimeException('Station-range requirement failed');
    $put('region-10000015',$snapshot([$bid]));
    $stale=$snapshot([]);$stale['fetched_at']=gmdate('c',time()-15000);$put('region-10001000',$stale);
    if((new GuristasBuildIntelligence($intel,$ref))->collect()['status']!=='pending')throw new RuntimeException('Stale market should fail closed');
    echo "PASS BUILD: complete inputs, missing liquidity, order range, stale snapshots\n";
} finally {foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
