<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/MarketIntelligence.php';
$intel=new GuristasMarketIntelligence();
$history=$intel->read('raid-momentum');
$samples=is_array($history['samples']??null)?array_values(array_filter($history['samples'],'is_array')):[];
if(count($samples)<2){
    echo "RAID MOMENTUM: INSUFFICIENT HISTORY (".count($samples)." sample(s)). Collect RAID at least 30 minutes apart; current ranking remains unchanged.\n";
    exit(0);
}
$a=$samples[count($samples)-2];$b=$samples[count($samples)-1];
$elapsed=(int)($b['at']??0)-(int)($a['at']??0);
if($elapsed<=0){echo "RAID MOMENTUM: invalid sample spacing; ranking unchanged.\n";exit(0);}
$rows=[];
foreach(($b['systems']??[]) as $id=>$recent){
    $before=$a['systems'][$id]??null;
    if(!is_array($before)||!is_array($recent))continue;
    $cp=$recent['cp']??null;$oldCp=$before['cp']??null;
    $sp=$recent['sp']??null;$oldSp=$before['sp']??null;
    $cDelta=$cp!==null&&$oldCp!==null?((int)($recent['c']??0)-(int)($before['c']??0))*100+(float)$cp-(float)$oldCp:null;
    $sDelta=$sp!==null&&$oldSp!==null&&($recent['s']??null)!==null&&($before['s']??null)!==null?
        ((int)$recent['s']-(int)$before['s'])*100+(float)$sp-(float)$oldSp:null;
    if($cDelta===null&&$sDelta===null)continue;
    $rows[]=['system_id'=>(int)$id,'corruption_change_points'=>$cDelta===null?null:round($cDelta,2),
        'suppression_change_points'=>$sDelta===null?null:round($sDelta,2),
        'sample_interval_minutes'=>round($elapsed/60,1)];
}
usort($rows,static function($x,$y){$dx=$x['suppression_change_points']??-INF;$dy=$y['suppression_change_points']??-INF;return $dy<=>$dx;});
echo json_encode(['status'=>'observational_only','samples'=>count($samples),'last_interval_minutes'=>round($elapsed/60,1),
    'systems_with_comparable_progress'=>count($rows),'largest_recent_suppression_increases'=>array_slice($rows,0,10),
    'warning'=>'Two observations do not predict future progress. Existing RAID priority selection remains authoritative.'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
