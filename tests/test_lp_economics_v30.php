<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/TradeOrders.php';
function assertV30(bool $test,string $message):void{if(!$test)throw new RuntimeException($message);}
$r=new ReflectionClass(GuristasTradeOrders::class);
$o=$r->newInstanceWithoutConstructor();
$r->getProperty('ref')->setValue($o,['hubs'=>['fulcrum'=>['station_id'=>60000001]]]);
$method=$r->getMethod('candidate');
$lp=$method->invoke($o,12345,1,100000.0,1000.0,900000.0,0,60003760,'LP REDEEM LIST','EXPORT','reference',true,true,10);
assertV30(is_array($lp),'Positive cash-yield LP candidate incorrectly rejected under assumed 1000 ISK/LP benchmark');
assertV30($lp['net']<0,'LP benchmark should remain visible even when the cash return is positive');
$bad=$method->invoke($o,12345,1,1000000.0,1000.0,900000.0,0,60003760,'LP REDEEM LIST','EXPORT','reference',true,true,10);
assertV30($bad===null,'Negative cash-yield LP redemption incorrectly eligible');
$ordinary=$method->invoke($o,12345,1,100000.0,0.0,150000.0,60003760,60008494,'MARKET','EXPORT','arbitrage',false,false,10);
assertV30($ordinary===null,'Ordinary trades should retain their 1M minimum-profit rule');
$select=$r->getMethod('selectHomepageSlots');
$lp['score']=0;
[$slots]=$select->invoke(null,[$lp]);
assertV30(is_array($slots['GURISTAS_LP']),'Cash-positive below-shadow LP lead missing from fixed homepage slot');
echo "PASS v30: LP cash return ranked independently of arbitrary shadow hurdle; ordinary trade gates preserved\n";
