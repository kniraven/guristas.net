<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
function a34(bool $pass,string $message):void {if(!$pass)throw new RuntimeException($message);}
$intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
$ref=['blueprints'=>[],'types'=>[
 '2312'=>['name'=>'Supertensile Plastics'],'2319'=>['name'=>'Test Cultures'],
 '2346'=>['name'=>'Synthetic Synapses'],'1228'=>['name'=>'Scordite'],'34'=>['name'=>'Tritanium']
],'pi_schematics'=>['92'=>['name'=>'Synthetic Synapses','cycle_seconds'=>3600,
    'inputs'=>[['typeID'=>2312,'quantity'=>10],['typeID'=>2319,'quantity'=>10]],
    'output'=>['typeID'=>2346,'quantity'=>3]]],
 'reprocessing'=>['1228'=>['portion_size'=>100,'materials'=>[['materialTypeID'=>34,'quantity'=>200]]]]];
$build=new GuristasBuildIntelligence($intel,$ref);
$market=['complete'=>true,'fetched_at'=>gmdate('c'),'orders'=>[
 ['t'=>2312,'l'=>60003760,'p'=>100,'v'=>50,'m'=>1,'b'=>false,'i'=>601],
 ['t'=>2319,'l'=>60003760,'p'=>200,'v'=>50,'m'=>1,'b'=>false,'i'=>602],
 ['t'=>2346,'l'=>60003760,'p'=>3000,'v'=>50,'m'=>1,'b'=>true,'i'=>603],
 ['t'=>34,'l'=>60003760,'p'=>10,'v'=>50000,'m'=>1,'b'=>true,'i'=>604],
]];
$method=(new ReflectionClass($build))->getMethod('importedPiLead');
$pi=$method->invoke($build,$market,null,null);
a34(is_array($pi),'SDE PI schematic should produce an offer-backed lead');
a34(($pi['evidence']['schematic_id']??0)===92,'Must show imported schematic ID');
a34(($pi['evidence']['input_cost_isk']??0)===3000.0,'Must price material input asks');
a34(abs(($pi['evidence']['modeled_margin_before_pi_and_haul']??0)-5325.0)<.01,'Must subtract modeled sales tax and required inputs');
a34(str_contains($pi['reason'],'EXCLUDES customs'),'Must not claim net colony profit');
$tooShallow=$market;
$tooShallow['orders'][2]['v']=2;
a34($method->invoke($build,$tooShallow,null,null)===null,'Reject insufficient output buyer depth');
$reproc=(new ReflectionClass($build))->getMethod('reprocessingScenario');
$scenario=$reproc->invoke($build,$market,1228,250);
a34(is_array($scenario)&&$scenario['outputs'][0]['quantity']===200,'Must account for whole reprocessing batches and 50% illustrative yield');
a34(($scenario['gross_isk']??0)===2000.0,'Must price resulting materials using buyer orders');
a34($reproc->invoke($build,$market,1228,50)===null,'No reprocessing smaller than portion size');
echo "PASS v34: imported PI selection, input costs, buyer depth, conditional labeling and SDE reprocessing scenarios\n";
