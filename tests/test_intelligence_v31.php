<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/TradeOrders.php';
function v31check(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException($msg);}
$reflect=new ReflectionClass(GuristasTradeOrders::class);
$instance=$reflect->newInstanceWithoutConstructor();
$reflect->getProperty('ref')->setValue($instance,['hubs'=>['fulcrum'=>['station_id'=>60015187]]]);
$make=$reflect->getMethod('candidate');
$one=$make->invoke($instance,10001,10,500000,1000,1900000,0,60003760,'LP REDEEM','EXPORT','bid',false,false,10);
$two=$make->invoke($instance,10002,5,200000,1000,1300000,0,60003760,'LP REDEEM LIST','EXPORT','listing',true,true,5);
v31check(is_array($one)&&is_array($two),'LP offers must survive shadow threshold when cash yield positive');
$selector=$reflect->getMethod('selectHomepageSlots');
[$slots]=$selector->invoke(null,[$one,$two]);
$yield=function(array $p):float{return ($p['revenue']-$p['salesTax']-$p['brokerFee']-$p['cost'])/$p['lp'];};
v31check($yield($one)>$yield($two),'Synthetic fixture should favor the immediate LP sale');
v31check((int)$slots['GURISTAS_LP']['type']===10001,'Highest after-fee cash ISK/LP must win regardless of shadow-valued net');
$root=dirname(__DIR__);
$cli=file_get_contents($root.'/bin/guristas-intelligence.php');
v31check(str_contains($cli,'86400') && str_contains($cli,"$".'intel->refreshOffers()'),'Normal collector publishes must refresh LP offers at its daily 24h threshold');
$script=file_get_contents($root.'/public/assets/js/home-command.js');
v31check(str_contains($script,'GURISTAS LP // REDEEM') && str_contains($script,'order.lp_cash_isk_per_lp') && str_contains($script,'order.unit_count'),'Homepage must show fixed LP category, financial return and reward units');
$trade=file_get_contents($root.'/app/services/TradeOrders.php');
v31check(str_contains($trade,"'unit_count'=>(int)$".'p['.'\'qty\']') && str_contains($trade,'lp_top_ranked'),'LP quote contract and ranked diagnostic must be present');
echo "PASS v31: highest cash ISK/LP, scheduled LP refresh, homepage LP contract, leaderboard\n";
