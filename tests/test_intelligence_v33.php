<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
function a33(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
$types=[];
foreach([1228=>'Scordite',2346=>'Synthetic Synapses',2312=>'Supertensile Plastics',2319=>'Test Cultures',900=>'Trial product',901=>'Trial ingredient'] as $id=>$name)$types[$id]=['name'=>$name];
$ref=['types'=>$types,'blueprints'=>['700'=>['materials'=>[['typeID'=>901,'quantity'=>2]],'product'=>['typeID'=>900,'quantity'=>1]]]];
$build=new GuristasBuildIntelligence($intel,$ref);
$method=(new ReflectionClass($build))->getMethod('resourceLeads');
$s=['complete'=>true,'fetched_at'=>gmdate('c'),'orders'=>[
 ['t'=>1228,'l'=>60003760,'p'=>900,'v'=>1,'m'=>1,'b'=>true,'i'=>101],
 ['t'=>1228,'l'=>60003760,'p'=>200,'v'=>99,'m'=>1,'b'=>true,'i'=>102],
 ['t'=>1228,'l'=>60011866,'p'=>800,'v'=>100,'m'=>1,'b'=>true,'i'=>103],
]];
$leads=$method->invoke($build,null,$s);
a33(count($leads)===1,'Expected one qualified resource');
a33(($leads[0]['evidence']['bid_station_id']??0)===60011866,'Should choose greater complete-lot proceeds instead of highest initial bid');
a33(($leads[0]['evidence']['reference_lot_gross_isk']??null)===80000.0,'Gross amount must come from the selected station');
a33(($leads[0]['evidence']['bid_fills'][0]['id']??0)===103,'Exact selected order evidence must be retained');
a33(str_contains($leads[0]['action'],'VERIFY'),'Availability must not be claimed confirmed');
$pi=(new ReflectionClass($build))->getMethod('piLead');
$market=['complete'=>true,'fetched_at'=>gmdate('c'),'orders'=>[
 ['t'=>2312,'l'=>60003760,'p'=>100,'v'=>100,'m'=>1,'b'=>false,'i'=>201],
 ['t'=>2319,'l'=>60003760,'p'=>200,'v'=>100,'m'=>1,'b'=>false,'i'=>202],
]];
$lead=$pi->invoke($build,$market,null,null);
a33($lead['state']==='supply_objective','No output buyer means no market-backed profit');
a33(($lead['evidence']['priced_inputs_isk']??0)===3000.0,'PI inputs must use posted executable asks');
a33(abs(($lead['evidence']['break_even_sale_unit_isk_before_pi_costs']??0)-1081.08)<0.02,'PI material-only break-even must include sale tax');
a33(str_contains($lead['reason'],'NOT colony charges'),'Must explicitly exclude operating costs');
$manufacture=(new ReflectionClass($build))->getMethod('bestManufacture');
$mkt=['complete'=>true,'fetched_at'=>gmdate('c'),'orders'=>[
 ['t'=>901,'l'=>60003760,'p'=>100,'v'=>30,'m'=>1,'b'=>false,'i'=>301],
 ['t'=>900,'l'=>60003760,'p'=>500,'v'=>20,'m'=>1,'b'=>false,'i'=>302],
 ['t'=>900,'l'=>60003760,'p'=>10000000,'v'=>5,'m'=>1,'b'=>true,'i'=>303],
]];
$offer=[['type_id'=>700,'lp_cost'=>1,'isk_cost'=>0,'ak_cost'=>0,'required_items'=>[]]];
$m=$manufacture->invoke($build,[10000002=>$mkt,10000015=>null,10001000=>null],$offer);
a33($m!==null,'Reference blueprint should qualify on high buyer margin');
a33(($m['evidence']['jita_finished_ask_total_isk']??null)===500.0,'Must compare real finished-good asks');
a33(($m['evidence']['modeled_build_acquisition_isk']??null)===1200.0,'Must include LP shadow value and input materials');
a33(($m['evidence']['build_versus_finished_purchase_isk']??null)===-700.0,'Must reveal when buying finished goods is cheaper');
echo "PASS v33: station-level lot optimization, PI break-even economics, manufacture-versus-buy, location safeguards\n";
