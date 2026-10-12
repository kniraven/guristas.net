<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/TradeOrders.php';
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
function checkV28(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$reflection=new ReflectionClass(GuristasTradeOrders::class);
$method=$reflection->getMethod('eligibleBidOrder');
$instance=$reflection->newInstanceWithoutConstructor();
foreach(['region','solarsystem','2',2,'40'] as $range){
    checkV28($method->invoke($instance,['m'=>1,'v'=>4,'r'=>$range],2),'Valid order-range-at-origin rejected: '.json_encode($range));
}
checkV28(!$method->invoke($instance,['m'=>1,'v'=>4,'r'=>'unknown'],2),'Invalid range accepted');
checkV28(!$method->invoke($instance,['m'=>1,'v'=>4,'r'=>41],2),'Invalid jump range accepted');
checkV28($method->invoke($instance,['m'=>1,'v'=>4,'r'=>'station'],2),'Station bid incorrectly rejected');
checkV28(!$method->invoke($instance,['m'=>3,'v'=>4,'r'=>'station'],2),'Unfillable minimum volume accepted');
$portfolio=$reflection->getMethod('selectPortfolio');
$base=function(int $type,float $score,int $mask):array{
    return ['type'=>$type,'score'=>$score,'coverage_mask'=>$mask,'kind'=>'MARKET','goal'=>'FULCRUM',
    'srcStation'=>60003760,'destStation'=>60000001,'net'=>$score,'buy_order'=>false,'listing'=>false];
};
// Full strategic coverage is expensive: preserve the materially better portfolio.
$candidates=[$base(1,100,1),$base(2,95,2),$base(3,90,16),$base(4,10,12)];
[$pick,$mask,$score,$variety,$audit]=$portfolio->invoke(null,$candidates);
checkV28($score>=270,'Coverage displaced a materially stronger financial portfolio');
checkV28($audit['opportunity_score_difference_pct']<=5.01,'Economic-score gap exceeded stated bound');
// A small score sacrifice may support coverage and strategic variety.
$candidates=[$base(1,100,1),$base(2,95,2),$base(3,90,16),$base(4,87,12)];
[$pick,$mask,$score,$variety,$audit]=$portfolio->invoke(null,$candidates);
checkV28(($mask&12)===12,'Near-tie should favor strategic coverage');
checkV28($score>=.95*285,'Near-tie exceeded economic-score budget');
// Three distinct items and never duplicate LP recommendations.
$lp=$base(5,98,4);$lp['kind']='LP REDEEM';$lp2=$base(6,97,4);$lp2['kind']='LP BUILD';
[$pick]=$portfolio->invoke(null,array_merge($candidates,[$lp,$lp2]));
checkV28(count(array_filter($pick,fn($x)=>str_starts_with($x['kind'],'LP ')))<=1,'Two LP cards selected');
checkV28(count(array_unique(array_column($pick,'type')))==count($pick),'Duplicate item selected');
// BUILD's market-valued ore reference lot must honor actual unit volume and bid depth.
$build=(new ReflectionClass(GuristasBuildIntelligence::class))->newInstanceWithoutConstructor();
$leadMethod=(new ReflectionClass(GuristasBuildIntelligence::class))->getMethod('resourceLeads');
$jita=['complete'=>true,'fetched_at'=>gmdate('c'),'orders'=>[
    ['t'=>1224,'l'=>60003760,'p'=>125.0,'v'=>6000,'m'=>1,'b'=>true,'r'=>'station','i'=>88001],
    ['t'=>16268,'l'=>60003760,'p'=>220000.0,'v'=>5,'m'=>1,'b'=>true,'r'=>'station','i'=>88002],
]];
$leads=$leadMethod->invoke($build,$jita,null);
checkV28(count($leads)===2,'Missing ore and ice candidates');
$byCategory=[];foreach($leads as $lead)$byCategory[$lead['resource_category']]=$lead;
checkV28(($byCategory['ore']['evidence']['reference_lot_units']??0)===3333,'Ore example should be 1,000 m³ reference lot');
checkV28(($byCategory['ice']['evidence']['reference_lot_units']??0)===1,'Ice example should be one unit');
checkV28($byCategory['ore']['evidence']['reference_lot_gross_isk']===3333*125.0,'Wrong ore gross lot calculation');
checkV28(($byCategory['ore']['evidence']['bid_order_id']??0)===88001,'Buyer provenance missing');
$health=file_get_contents(dirname(__DIR__).'/bin/guristas-intelligence-health.php');
checkV28(str_contains($health,"['trade-orders','build-orders','raid']"),'Health report still points to nonexistent raid-orders cache');
echo "PASS v28: buyer-range eligibility at the listed station, economically bounded trade diversity, LP cap, BUILD reference volume, RAID health path\n";
