<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
require_once dirname(__DIR__).'/app/services/TradeOrders.php';
function v32assert(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
$build=new GuristasBuildIntelligence($intel,['types'=>[],'blueprints'=>[]]);
$method=(new ReflectionClass($build))->getMethod('resourceLeads');
$now=gmdate('c');
$s=['complete'=>true,'fetched_at'=>$now,'orders'=>[
    // Top bid alone is small; other orders at the same station support a larger lot.
    ['t'=>1228,'l'=>60003760,'p'=>600,'v'=>1,'m'=>1,'r'=>'region','b'=>true,'i'=>111],
    ['t'=>1228,'l'=>60003760,'p'=>500,'v'=>99,'m'=>1,'r'=>'station','b'=>true,'i'=>112],
    // A different station cannot be combined with the Jita delivery lot.
    ['t'=>1228,'l'=>60011866,'p'=>490,'v'=>10000,'m'=>1,'r'=>'station','b'=>true,'i'=>113],
]];
$results=$method->invoke($build,$s,null);
v32assert(count($results)===1,'One priced Scordite resource lead expected');
$card=$results[0];
v32assert(($card['evidence']['reference_lot_units']??null)===100,'Lot must respect depth at one destination station');
v32assert(($card['evidence']['reference_lot_gross_isk']??null)===50100.0,'Use a depth-weighted fill, not top-bid price times batch');
v32assert(count($card['evidence']['bid_fills']??[])===2,'Two exact order IDs must support the batch');
v32assert(($card['evidence']['bid_station_id']??0)===60003760,'Do not combine bids from remote stations');
// Buy-order range is compatible with fulfillment at that order's own station.
$rows=(new ReflectionClass($build))->getMethod('orderRows')->invoke(null,$s,1228,true,60003760);
v32assert(count($rows)===2,'Station and regional bids at their listed station must qualify');
// A minimum-fill requirement larger than the quantity excludes the bid.
$s['orders']=[['t'=>1228,'l'=>60003760,'p'=>600,'v'=>100,'m'=>101,'r'=>'station','b'=>true,'i'=>114]];
$results=$method->invoke($build,$s,null);
v32assert($results===[],'Cannot fulfill an order below its minimum volume');
$raidReport=['campaigns'=>[['id'=>'campaign-1']], 'systems'=>[['id'=>3001,'corruption_stage'=>2,'corruption_percent'=>33.3,'suppression_stage'=>1]]];
$hist=GuristasMarketIntelligence::raidHistoryAppend(null,$raidReport,10000000);
v32assert(count($hist['samples'])===1,'First RAID trend sample should be recorded');
$hist=GuristasMarketIntelligence::raidHistoryAppend($hist,$raidReport,10000100);
v32assert(count($hist['samples'])===1,'Avoid an extra RAID history entry within 30 minutes');
$raidReport['systems'][0]['corruption_percent']=77.7;
$hist=GuristasMarketIntelligence::raidHistoryAppend($hist,$raidReport,10002000);
v32assert(count($hist['samples'])===2 && $hist['samples'][1]['systems']['3001']['cp']===77.7,'Keep actual observed progress changes');
$hist=GuristasMarketIntelligence::raidHistoryAppend($hist,$raidReport,10002000+8*86400);
v32assert(count($hist['samples'])===1,'Old RAID history must age out after seven days');
$trade=file_get_contents(dirname(__DIR__).'/app/services/TradeOrders.php');
v32assert(str_contains($trade,'lot_share_of_regional_14d_volume_pct'),'LP listings must surface proposed batch as a share of executed regional volume');
$js=file_get_contents(dirname(__DIR__).'/public/assets/js/home-command.js');
v32assert(str_contains($js,"line('MARKET LIQUIDITY'") && str_contains($js,"line('ASK VS TRADED PRICE'"),'LP market liquidity must be explained in INTEL');
$collector=file_get_contents(dirname(__DIR__).'/app/services/MarketIntelligence.php');
v32assert(str_contains($collector,"foreach(("),'Imported industry references should get outside market benchmark quotes');
echo "PASS v32: regional orders, min fills, depth-weighted single-station lot, LP liquidity, expanded imported references\n";
