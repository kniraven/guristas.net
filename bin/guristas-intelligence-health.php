<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/MarketIntelligence.php';
$intel=new GuristasMarketIntelligence();
$now=time();
$regions=[10000002=>'Jita / The Forge',10000015=>'Venal',10001000=>'The Fulcrum / Yasna Zakh',10000032=>'Dodixie / Sinq Laison'];
$benchmarks=[1224=>'Pyroxeres',1228=>'Scordite',16268=>'Gelidus',16269=>'Krystallos',2312=>'Supertensile Plastics',2319=>'Test Cultures',2346=>'Synthetic Synapses'];
echo "GURISTAS ECONOMIC INTELLIGENCE HEALTH (READ ONLY)\n";
foreach($regions as $rid=>$label){
    $snapshot=$intel->read('region-'.$rid);
    $ts=strtotime((string)($snapshot['fetched_at']??''))?:0;
    $age=$ts?max(0,$now-$ts):null;
    $orders=is_array($snapshot['orders']??null)?$snapshot['orders']:[];
    printf("%-32s age=%-7s orders=%-7d %s\n",$label,$age===null?'missing':$age.'s',count($orders),($age!==null&&$age<=10800&&!empty($snapshot['complete']))?'FRESH':'STALE/MISSING');
    if($rid===10000002){
        $present=[];
        foreach($orders as $o){$id=(int)($o['t']??0);if(isset($benchmarks[$id]))$present[$id]=true;}
        foreach($benchmarks as $id=>$name)printf("  %-26s %s\n",$name,isset($present[$id])?'QUOTED':'NO RETAINED ORDER');
    }
}
foreach(['trade-orders','build-orders','raid'] as $key){
    $r=$intel->read($key);
    if(!$r){echo "$key: NOT FOUND\n";continue;}
    $data=$r['data']??$r;
    if($key==='raid'){
        $campaigns=$data['campaigns']??[];
        $target=$data['server_recommendation']??null;
        printf("raid: %d campaigns; recommendation: %s\n",count($campaigns),is_array($target)?(string)($target['title']??'UNKNOWN'):'NO PUBLISHED RECOMMENDATION');
        continue;
    }
    printf("%s: %d cards\n",$key,count($data['orders']??[]));
    if($key==='trade-orders'){
        $coverage=$data['coverage']??[];$audit=$coverage['selection_audit']??[];
        printf("  Five strategic goals met: %s; LP cards: %d; unrestricted ranking gap: %s%%\n",
            !empty($coverage['selection_requirements_met'])?'YES':'NO',
            (int)($coverage['selected_lp_count']??0),
            isset($audit['opportunity_score_difference_pct'])?(string)$audit['opportunity_score_difference_pct']:'unknown');
        printf("  Coverage policy: %s; within score budget: %s\n",
            (string)($audit['coverage_policy']??'legacy'),!empty($audit['chosen_within_economic_budget'])?'YES':'NO');
        echo "  NOTE: Ranking score gap is NOT an ISK profit difference.\n";
    }
    if($key==='build-orders'){
        $counts=[];
        foreach($data['orders']??[] as $o){$label=(string)($o['activity']??'unknown').'/'.(string)($o['state']??'unknown');$counts[$label]=($counts[$label]??0)+1;}
        foreach($counts as $k=>$v)echo "  $k: $v\n";
    }
}
echo "Interpretation: A missing external quote is not evidence of zero demand or worthless production. A posted buyer is not proof of a Venal resource deposit or an executable manufacturing profit.\n";
