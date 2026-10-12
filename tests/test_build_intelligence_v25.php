<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
require_once dirname(__DIR__).'/app/services/BuildAudit.php';
$dir=sys_get_temp_dir().'/guri-build-v25-'.bin2hex(random_bytes(4));mkdir($dir,0700,true);
try{
$intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(GuristasMarketIntelligence::class,'dir'))->setValue($intel,$dir);
$put=static fn($k,$v)=>file_put_contents($dir.'/'.$k.'.json',json_encode($v,JSON_THROW_ON_ERROR));
$mk=static fn($rows)=>['complete'=>true,'fetched_at'=>gmdate('c'),'orders'=>$rows];
$bid=static fn($t,$p,$n,$id)=>['t'=>$t,'l'=>60003760,'p'=>$p,'v'=>$n,'m'=>1,'b'=>true,'r'=>'station','i'=>$id];
$put('region-10000002',$mk([$bid(1224,2500,1000,10),$bid(16268,15000000,12,11)]));
$put('region-10000015',$mk([]));$put('region-10001000',$mk([]));
$r=(new GuristasBuildIntelligence($intel,['types'=>[],'blueprints'=>[]]))->collect();
if(count($r['orders'])!==3)throw new RuntimeException('Three activity slots absent');
$m=$r['orders'][1];
if($m['title']!=='Mine Gelidus in Venal'||($m['resource_category']??'')!=='ice')throw new RuntimeException('Ice candidate failed');
if(($m['evidence']['bid_order_id']??0)!==11||!empty($m['evidence']['live_deposit_verified']))throw new RuntimeException('Ice quote provenance invalid');
if(empty($m['alternatives'])||($m['alternatives'][0]['resource_category']??'')!=='ore')throw new RuntimeException('Qualified ore alternative absent');
if(($m['requires_skills']['16281']??0)!==1||($m['alternatives'][0]['requires_skills']['3386']??0)!==1)throw new RuntimeException('Skill filtering metadata invalid');
$a=GuristasBuildAudit::record($dir,$r);$b=GuristasBuildAudit::record($dir,$r);
if(($a['observations']??0)!==1||($b['observations']??0)!==2)throw new RuntimeException('Audit failed');
$sum=GuristasBuildAudit::summary($dir);
if($sum['observations']!==2||$sum['unverified_locations']!==2)throw new RuntimeException('Audit missing location warnings');
// The engine must never invent live Venal gas availability from gas market orders.
if(str_contains(strtolower($m['title']),'cytoserocin'))throw new RuntimeException('Gas location assertion unsupported');
echo "PASS BUILD v25: verified ore/ice quotes, pilot alternatives, seven-day audit\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
