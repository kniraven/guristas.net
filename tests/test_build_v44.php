<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
function v44check(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException($msg);echo "PASS v44: $msg\n";}
$ref=json_decode(file_get_contents(dirname(__DIR__).'/app/data/industry-reference.json'),true,512,JSON_THROW_ON_ERROR);
$catalog=GuristasBuildIntelligence::venalMiningCatalog(null,$ref['types']);
$eligible=array_filter($catalog,static fn($r)=>$r['status']==='eligible');
foreach(['ore','gas','ice'] as $category)v44check((bool)array_filter($eligible,static fn($r)=>$r['category']===$category),'eligible '.$category.' catalog entry');
v44check(isset($catalog[28701])&&$catalog[28701]['status']==='eligible','Venal gas is mapped by its exact CCP SDE item ID');
v44check(!isset($eligible[28696])&&!isset($eligible[16269]),'disputed gas/ice cannot qualify');
$intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
$build=new GuristasBuildIntelligence($intel,$ref);
$method=new ReflectionMethod(GuristasBuildIntelligence::class,'resourceLeads');$method->setAccessible(true);
$buy=static fn($id,$price,$index)=>['t'=>$id,'b'=>true,'p'=>$price,'v'=>20000,'m'=>1,'i'=>$index,'l'=>60003760];
$market=['complete'=>true,'fetched_at'=>gmdate('c'),'orders'=>[
 $buy(22,1000,1),$buy(1223,1100,2),$buy(28701,200000,3),$buy(16268,100000,4),$buy(16267,110000,5),
 $buy(28696,99999999,6),$buy(16269,99999999,7),
 ]];
$results=$method->invoke($build,$market,null);
$ids=array_column(array_map(static fn($r)=>$r['evidence']??[],$results),'item_type_id');
v44check(in_array(22,$ids,true)&&in_array(1223,$ids,true),'eligible Arkonor and Bistot enter buyer ranking');
v44check(in_array(28701,$ids,true),'eligible gas enters buyer ranking');
v44check(in_array(16268,$ids,true)&&in_array(16267,$ids,true),'eligible ice enters buyer ranking');
v44check(!in_array(28696,$ids,true)&&!in_array(16269,$ids,true),'disputed entries excluded even with huge bids');
v44check(($results[0]['evidence']['item_type_id']??0)===28701,'gas wins when its comparable modeled return is strongest');
foreach($results as $r){
 v44check(str_starts_with($r['title'],'Mine '),'direct mining instruction '.$r['title']);
 v44check(isset($r['evidence']['eligibility_source'],$r['evidence']['eligible_site']),'source and site accompany '.$r['title']);
}
$withoutGas=$market;$withoutGas['orders']=array_values(array_filter($market['orders'],static fn($o)=>$o['t']!==28701));
$again=$method->invoke($build,$withoutGas,null);
v44check(($again[0]['evidence']['item_type_id']??0)!==28701,'rank responds to changing buyer quotes');
// A catalog edit is read at runtime, not compiled into PHP.
$temp=tempnam(sys_get_temp_dir(),'guri-v44');
try {
 $raw=json_decode(file_get_contents(dirname(__DIR__).'/config/venal-mining-resources.json'),true,512,JSON_THROW_ON_ERROR);
 foreach($raw['resources'] as &$item)if($item['type_id']===28701)$item['status']='disabled';unset($item);
 file_put_contents($temp,json_encode($raw,JSON_THROW_ON_ERROR));
 $edited=GuristasBuildIntelligence::venalMiningCatalog($temp,$ref['types']);
 v44check($edited[28701]['status']==='disabled','editable catalog disables gas without code edits');
}finally{unlink($temp);}
