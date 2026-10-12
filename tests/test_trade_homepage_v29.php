<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/TradeOrders.php';
function requireV29(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);}
$r=new ReflectionClass(GuristasTradeOrders::class);
$select=$r->getMethod('selectHomepageSlots');
$sample=static function(int $type,string $kind,float $score,float $profit,array $extra=[]):array{
    return array_merge(['type'=>$type,'kind'=>$kind,'score'=>$score,'net'=>$profit,'lp'=>0,
        'buy_order'=>false,'listing'=>false,'conditional'=>false,'coverage_mask'=>0,
        'srcStation'=>60000001,'destStation'=>60003760,'goal'=>'VENAL'], $extra);
};
$immediate=['quote_evidence'=>['source_asks_used'=>[['order_id'=>321]],'destination_buy_order'=>['order_id'=>456]]];
$pool=[
    $sample(101,'MARKET',1000,1000000,$immediate),
    $sample(102,'MARKET',900,900000,$immediate),
    $sample(103,'MARKET',800,800000,['buy_order'=>true]),
    $sample(104,'MARKET',700,700000,['listing'=>true]),
    $sample(105,'LP REDEEM',12,150000,['lp'=>10]),
    $sample(106,'LP REDEEM',10,210000,['lp'=>100]),
];
[$chosen,$audit]=$select->invoke(null,$pool);
requireV29(array_keys($chosen)===['IMMEDIATE','NEW_ORDER','GURISTAS_LP'],'Slot order mismatch');
requireV29($chosen['IMMEDIATE']['type']===101,'Immediate trade selection wrong');
requireV29($chosen['NEW_ORDER']['type']===103,'New order selection wrong');
requireV29($chosen['GURISTAS_LP']['type']===105,'LP best ISK-per-LP conversion not selected');
requireV29(count(array_unique(array_map(static fn($x)=>$x['type'],$chosen)))===3,'Duplicate type across roles');
foreach($audit as $slot=>$row){if($slot==='GURISTAS_LP' && !empty($row['comparison']))continue;requireV29(!empty($row['selected']),'Selected role reported missing');}
// Pure arbitrage can never occupy the new-order or LP position.
[$chosen,$audit]=$select->invoke(null,[$sample(201,'MARKET',999,999000,$immediate)]);
requireV29($chosen['IMMEDIATE']['type']===201,'Lost sole valid immediate route');
requireV29($chosen['NEW_ORDER']===null&&$chosen['GURISTAS_LP']===null,'Missing role silently substituted');
// An unproven arbitrage without order identifiers cannot be called immediate.
[$chosen]=$select->invoke(null,[$sample(202,'MARKET',1000,1000000)]);
requireV29($chosen['IMMEDIATE']===null,'Unverified ask-to-bid marked executable');
// LP conversions should remain LP even if they propose a new sell listing.
[$chosen]=$select->invoke(null,[$sample(203,'LP REDEEM',100,100000,['lp'=>10,'listing'=>true])]);
requireV29($chosen['GURISTAS_LP']!==null&&$chosen['NEW_ORDER']===null,'LP listing counted as second slot');
// Two roles can reference same product: choose the next distinct alternative.
[$chosen]=$select->invoke(null,[
    $sample(301,'MARKET',100,100000,$immediate),
    $sample(301,'MARKET',99,99000,['buy_order'=>true]),
    $sample(302,'MARKET',90,90000,['listing'=>true]),
    $sample(303,'LP REDEEM',80,80000,['lp'=>20]),
]);
requireV29($chosen['IMMEDIATE']['type']===301&&$chosen['NEW_ORDER']['type']===302,'Distinct-item selection failed');
echo "PASS v29: fixed arbitrage, new-order and Guristas LP slots; efficiency ranking, missing evidence, no duplicates\n";
