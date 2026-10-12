<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/MarketIntelligence.php';
$ref=guri_industry_reference();$intel=new GuristasMarketIntelligence();
$offers=$intel->read('lp-offers');
$rows=is_array($offers['offers']??null)?$offers['offers']:[];
$direct=[];$bpc=[];$required=[];$ak=0;
foreach($rows as $o){
    $id=(int)($o['type_id']??0);
    if($id<1)continue;
    if((int)($o['ak_cost']??0)>0){$ak++;continue;}
    if(isset($ref['blueprints'][(string)$id]))$bpc[$id]=true;else $direct[$id]=true;
    foreach($o['required_items']??[] as $input){
        $item=(int)($input['type_id']??$input['typeID']??0);
        if($item>0)$required[$item]=true;
    }
}
$referenceProduct=[];
foreach($ref['blueprints']??[] as $bp){
    $t=(int)($bp['product']['typeID']??0);
    if($t>0)$referenceProduct[$t]=true;
}
$regions=[10000002=>'Jita',10000032=>'Dodixie',10000015=>'Venal',10001000=>'Fulcrum'];
$quoted=[];
foreach($regions as $id=>$label){
    $snap=$intel->read('region-'.$id);
    foreach($snap['orders']??[] as $order){
        $type=(int)($order['t']??0);
        if($type>0)$quoted[$id][$type]=true;
    }
}
echo "GURISTAS INDUSTRIAL REFERENCE COVERAGE (READ ONLY)\n";
printf("Reference blueprints: %d; finished products: %d; named types: %d\n",count($ref['blueprints']??[]),count($referenceProduct),count($ref['types']??[]));
printf("Current LP offers: %d; direct no-AK reward types: %d; BPC reward types recognized: %d; AK-gated offers: %d\n",
    count($rows),count($direct),count($bpc),$ak);
printf("Distinct LP-required input types: %d\n",count($required));
foreach($regions as $id=>$label){
    $present=$quoted[$id]??[];
    printf("%-10s priced direct LP rewards %d/%d; referenced manufactured products %d/%d\n",
        $label,count(array_intersect_key($direct,$present)),count($direct),
        count(array_intersect_key($referenceProduct,$present)),count($referenceProduct));
}
echo "NOTE: Quote coverage does not validate profitability or resource availability. A complete SDE import remains outstanding.\n";
