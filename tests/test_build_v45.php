<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
function v45assert(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS v45: $message\n";}
$now=gmdate('c');
$book=static fn(array $orders):array=>['complete'=>true,'fetched_at'=>$now,'orders'=>$orders];
$order=static fn(int $id,bool $buy,float $p,int $station,int $index,int $qty=2000):array=>['t'=>$id,'b'=>$buy,'p'=>$p,'v'=>$qty,'m'=>1,'i'=>$index,'l'=>$station];
$books=[10000002=>$book([$order(22,true,80,60003760,1),$order(22,false,120,60003760,2)]),10000032=>$book([]),10000015=>$book([]),10001000=>$book([])];
$a=GuristasBuildIntelligence::fulcrumMiningPrice(22,16.0,$books);
v45assert($a!==null && abs($a['listing_unit_isk']-100)<.001,'one Fulcrum target is midpoint of quoted buy/sell sides');
v45assert($a['mode']==='midpoint'&&abs($a['value_isk_per_m3']-6.25)<.001,'ranking measures suggested list ISK per cubic meter');
$books[10000002]=$book([$order(22,true,80,60003760,1)]);
$b=GuristasBuildIntelligence::fulcrumMiningPrice(22,16,$books);
v45assert($b!==null&&$b['mode']==='one_sided'&&$b['listing_unit_isk']===80.0,'one-sided quote is disclosed rather than invented');
v45assert(GuristasBuildIntelligence::fulcrumMiningPrice(22,16,[10000002=>$book([])])===null,'missing market evidence never generates fictional price');
$books[10000002]=$book([$order(22,true,10000,60003760,1,1),$order(22,false,120,60003760,2,2000)]);
v45assert(GuristasBuildIntelligence::fulcrumMiningPrice(22,16,$books)['mode']==='one_sided','dust-sized order excluded from midpoint');
$ref=json_decode(file_get_contents(dirname(__DIR__).'/app/data/industry-reference.json'),true,512,JSON_THROW_ON_ERROR);
$intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
$build=new GuristasBuildIntelligence($intel,$ref);
$method=new ReflectionMethod(GuristasBuildIntelligence::class,'resourceLeads');$method->setAccessible(true);
$orders=[];$index=20;
foreach([22=>150,1223=>140,28701=>100000,16268=>200000,16267=>190000] as $id=>$p){
 $orders[]=$order($id,true,$p,60003760,$index++);
 $orders[]=$order($id,false,$p*1.2,60003760,$index++);
}
$leads=$method->invoke($build,$book($orders),null,null,null);
$cats=array_column($leads,'resource_category');
v45assert(in_array('ore',$cats,true)&&in_array('ice',$cats,true)&&in_array('gas',$cats,true),'ore, gas and ice compete on the same listed-value basis');
v45assert(count($leads)===5,'every priced eligible mining candidate is retained for logged-in skill alternatives');
foreach($leads as $lead){
 v45assert(str_starts_with($lead['title'],'Mine ')&&str_contains($lead['action'],'LIST AT THE FULCRUM'),'each resource remains an imperative Fulcrum listing instruction');
 v45assert(($lead['evidence']['suggested_sell_unit_isk']??0)>0&&isset($lead['evidence']['eligibility_source']),'price and Venal source evidence are preserved');
}
$marketSource=file_get_contents(dirname(__DIR__).'/app/services/MarketIntelligence.php');
v45assert(str_contains($marketSource,"venal-mining-resources.json")&&str_contains($marketSource,"$".'types[(int)$resource[\'type_id\']]'),'external collector explicitly retains eligible mining resource types');
$js=file_get_contents(dirname(__DIR__).'/public/assets/js/home-command.js');
v45assert(str_contains($js,'FULCRUM SELL ')&&str_contains($js,'evPrice.suggested_sell_unit_isk'),'mining card shows one suggested Fulcrum sale price');
