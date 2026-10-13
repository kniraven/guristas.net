<?php
require_once __DIR__.'/../app/services/BuildIntelligence.php';
function checkScore(float $margin,int $seconds,?float $expected):void {
    $got=GuristasBuildIntelligence::piHourlyScore($margin,$seconds);
    if($expected===null ? $got!==null : abs($got-$expected)>0.00001)throw new RuntimeException('Hourly normalization mismatch');
}
checkScore(100.0,3600,100.0);
checkScore(80.0,900,320.0);
checkScore(100.0,7200,50.0);
if(GuristasBuildIntelligence::piHourlyScore(80.0,900)<=GuristasBuildIntelligence::piHourlyScore(100.0,3600))throw new RuntimeException('Fast cycle should beat higher single-cycle surplus');
checkScore(-1.0,3600,null);
checkScore(100.0,0,null);
$src=file_get_contents(__DIR__.'/../app/services/BuildIntelligence.php');
foreach(['pi_buyer_candidates_qualified','pi_missing_input_quote_rejections','modeled_margin_per_factory_hour_before_pi_costs','pi_recipes_evaluated'] as $term){if(strpos($src,$term)===false)throw new RuntimeException('Missing source contract: '.$term);}
echo "PASS v42: PI per-hour normalization, non-positive rejection, coverage and evidence\n";
