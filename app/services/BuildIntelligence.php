<?php
declare(strict_types=1);
require_once __DIR__.'/MarketIntelligence.php';
/** Server-published, source-backed industrial advice. No visitor-triggered market collection. */
final class GuristasBuildIntelligence {
    private GuristasMarketIntelligence $intel;
    private array $ref;
    public function __construct(?GuristasMarketIntelligence $intel=null, ?array $reference=null) {
        $this->intel=$intel ?? new GuristasMarketIntelligence();
        $this->ref=$reference ?? guri_industry_reference();
    }
    private static function fresh(?array $s):bool {
        if (!$s || empty($s['complete']) || !is_array($s['orders']??null))return false;
        $ts=strtotime((string)($s['fetched_at']??''));
        return $ts!==false && $ts<=time()+60 && time()-$ts<=10800;
    }
    private static function orderRows(array $s,int $type,bool $buy,int $station=0):array {
        $out=[];
        foreach($s['orders'] as $o){
            if((int)($o['t']??0)!==$type || (bool)($o['b']??false)!==$buy || (int)($o['v']??0)<1 || (float)($o['p']??0)<=0 || (int)($o['i']??0)<=0)continue;
            if($station && (int)($o['l']??0)!==$station)continue;
            if($buy && ((string)($o['r']??'')!=='station' || (int)($o['m']??1)>1))continue;
            $out[]=$o;
        }
        usort($out,static fn($a,$b)=>$buy?((float)$b['p']<=>(float)$a['p']):((float)$a['p']<=>(float)$b['p']));
        return $out;
    }
    private static function fills(array $rows,int $qty):?array {
        $rem=$qty;$sum=0.0;$ids=[];
        foreach($rows as $o){$n=min($rem,(int)$o['v']);$sum+=$n*(float)$o['p'];$rem-=$n;$ids[]=(int)$o['i'];if($rem===0)break;}
        return $rem===0?['value'=>$sum,'ids'=>$ids]:null;
    }
    private function name(int $id):string {return (string)($this->ref['types'][(string)$id]['name']??('Item '.$id));}
    private static function card(string $activity,string $state,string $title,string $action,string $detail,string $reason,array $more=[]):array {
        return ['activity'=>$activity,'state'=>$state,'title'=>$title,'action'=>$action,'detail'=>$detail,'reason'=>$reason]+$more;
    }
    private function blueprintCosts(array $s,array $bp):?array {
        $inputs=[];$total=0.0;
        foreach($bp['materials']??[] as $m){
            $type=(int)($m['typeID']??0);$qty=(int)($m['quantity']??0);
            if($type<1 || $qty<1)return null;
            $quote=self::fills(self::orderRows($s,$type,false,60003760),$qty);
            if(!$quote)return null;
            $total+=$quote['value'];$inputs[]=['id'=>$type,'name'=>$this->name($type),'quantity'=>$qty,'cost'=>$quote['value'],'orders'=>$quote['ids']];
        }
        return ['cost'=>$total,'inputs'=>$inputs];
    }
    private function bestManufacture(array $markets,array $offers):?array {
        $bpOffers=[];
        foreach($offers as $o){
            $bpId=(int)($o['type_id']??0);
            if(!isset($this->ref['blueprints'][(string)$bpId]) || (int)($o['lp_cost']??0)<1 || (int)($o['ak_cost']??0)!==0 || !empty($o['required_items']))continue;
            $cost=(float)($o['isk_cost']??0)+1000.0*(int)$o['lp_cost'];
            if(!isset($bpOffers[$bpId]) || $cost<$bpOffers[$bpId]['cost'])$bpOffers[$bpId]=['cost'=>$cost,'lp'=>(int)$o['lp_cost'],'isk'=>(float)($o['isk_cost']??0)];
        }
        $best=null;
        foreach($this->ref['blueprints'] as $bpId=>$bp){
            $offer=$bpOffers[(int)$bpId]??null;
            if(!$offer)continue;
            $input=$this->blueprintCosts($markets[10000002],$bp);
            if(!$input)continue;
            $product=(int)($bp['product']['typeID']??0);$yield=(int)($bp['product']['quantity']??0);
            if($product<1||$yield<1)continue;
            foreach([10000015=>'Venal',10001000=>'The Fulcrum',10000002=>'Jita export'] as $region=>$name){
                if(empty($markets[$region]))continue;
                $destination=$region===10000002?60003760:0;
                foreach(self::orderRows($markets[$region],$product,true,$destination) as $bid){
                    if((int)$bid['v']<$yield)continue;
                    $revenue=$yield*(float)$bid['p'];$margin=.925*$revenue-$input['cost']-$offer['cost'];
                    if($margin<2000000 || ($best && $margin<=$best['margin']))continue;
                    $best=['margin'=>$margin,'blueprint_id'=>(int)$bpId,'product_type_id'=>$product,'yield'=>$yield,'cost'=>$input['cost'],'offer'=>$offer,'inputs'=>$input['inputs'],'bid_order_id'=>(int)$bid['i'],'bid_station_id'=>(int)$bid['l'],'region'=>$name,'revenue'=>$revenue];
                }
            }
        }
        if(!$best)return null;
        return self::card('manufacture','market_lead',$this->name($best['product_type_id']),($best['region']==='Jita export'?'BUILD IN VENAL → SELL JITA':'BUILD IN VENAL → SELL '.$best['region']),
            '1 run: '.number_format($best['yield']).' unit(s) · buyer order #'.$best['bid_order_id'],
            'NEXT STEPS: redeem the blueprint for '.number_format($best['offer']['lp']).' LP plus '.number_format($best['offer']['isk']).' ISK; buy the required materials at Jita 4-4; confirm a manufacturing-capable Venal facility and job quote; run the blueprint once; sell '.number_format($best['yield']).' output unit(s) into buyer order #'.$best['bid_order_id'].' at station ID '.$best['bid_station_id'].'. Modeled sales tax 7.5% and LP 1,000 ISK/LP. Material estimates use actual asks. Before starting, verify blueprint runs and material efficiency, facility/job fees, hauling cost, buyer availability and safe transport. Margin is not net profit.',
            ['metric_isk'=>$best['margin'],'metric_label'=>'MARGIN BEFORE JOB/HAUL','evidence'=>$best,'steps'=>['Redeem blueprint: '.number_format($best['offer']['lp']).' Commando Guri LP + '.number_format($best['offer']['isk']).' ISK.','Buy blueprint materials at Jita 4-4; confirm the actual blueprint run count and skills.','Confirm a manufacturing-capable facility and quote in Venal BEFORE hauling.','Build '.number_format($best['yield']).' unit(s); verify then fulfill buyer order #'.$best['bid_order_id'].' at station ID '.$best['bid_station_id'].'.'],'url'=>'/industry/?view=build&bp='.$best['blueprint_id']]);
    }
    /** Real ore prices, not an assertion that a belt currently contains that ore. */
    private function mineralLead(?array $jita,?array $venal=null):array {
        // CCP SDE type IDs and ore volumes: Scordite 0.15m³; Pyroxeres 0.30m³.
        // These are physical mineable ores, unlike Pyerite which is a refined mineral.
        $ore=[1228=>['name'=>'Scordite','m3'=>0.15,'mineral'=>'Tritanium + Pyerite'],
              1224=>['name'=>'Pyroxeres','m3'=>0.30,'mineral'=>'Pyerite + Mexallon']];
        $best=null;
        foreach($ore as $id=>$info){
            foreach([10000015=>[$venal,'Venal'],10000002=>[$jita,'Jita']] as $region=>$target){
                [$market,$name]=$target;
                if(!self::fresh($market))continue;
                foreach(self::orderRows($market,$id,true,$region===10000002?60003760:0) as $bid){
                    $qty=(int)$bid['v'];if($qty<100 || (int)($bid['m']??1)>100)continue;
                    $price=(float)$bid['p'];$perM3=$price/$info['m3'];
                    // Market demand at the same price may be exhausted by a single buyer.
                    // Prefer a Venal buyer when normalized values are reasonably close.
                    $score=$perM3*($region===10000015?1.12:1.0);
                    if($best===null||$score>$best['score'])$best=['id'=>$id,'name'=>$info['name'],'unit_price'=>$price,'per_m3'=>$perM3,'volume'=>$qty,'region'=>$name,'order'=>(int)$bid['i'],'score'=>$score,'minerals'=>$info['mineral']];
                }
            }
        }
        if($best){
            $local=$best['region']==='Venal';
            return self::card('mine','market_context','Mine '.$best['name'].' in Venal',
                'FIND '.$best['name'].' → SELL '.strtoupper($best['region']),
                'Buyer order #'.$best['order'].' · '.number_format($best['unit_price'],2).' ISK/ore · at least 100 units',
                'NEXT STEPS: scan Venal asteroid belts and ore anomalies for '.$best['name'].'; confirm a real deposit before deploying; mine up to the available buyer quantity (currently '.$best['volume'].' units) and transport ore to the '.($local?'Venal':'Jita 4-4').' buyer (order #'.$best['order'].'). Refines into '.$best['minerals'].'; compare direct ore sale against refining first. Gross order value '.number_format($best['per_m3'],0).' ISK/m³, NOT ISK/hour. Current asteroid availability, mining yield, hauling and loss risk are NOT verified.',
                ['evidence'=>['ore_type_id'=>$best['id'],'buy_order_id'=>$best['order'],'bid_units'=>$best['volume'],'market'=>$best['region'],'gross_isk_per_m3'=>$best['per_m3']], 'steps'=>['Scout Venal belts and ore anomalies for actual '.$best['name'].' deposits.','Mine a starter batch of 100 units ONLY if this ore is present and accessible.','Check existing buyer order #'.$best['order'].' ('. $best['region'].') and transport risk before selling.'],'url'=>'/industry/']);
        }
        return self::card('mine','supply_objective','Scout ore for Guristas hulls','FIND VENAL ORE SITES',
            'Search belts / ore anomalies · confirm deposits in-game',
            'Actual ore types produce minerals needed for ordinary industry, but no timely profitable ore buyer or live mining-site availability is verified. Compare selling ore with refining it after checking yield and transport. Do not treat this as a profit estimate.',
            ['evidence'=>['known_ore_type_ids'=>[1228,1224]],'steps'=>['Search a Venal system for asteroid belts or ore anomalies.','Check whether Scordite or Pyroxeres is actually present.','Compare an available ore buyer against your mining and hauling costs before extracting.'],'url'=>'/industry/']);
    }
    /** Verified Synthetic Synapses P3 schematic: 10 Supertensile Plastics + 10 Test Cultures -> 3 units / hour.
     * Schematic data is stable reference; price estimates still exclude colony operating costs. */
    private function piLead(?array $jita,?array $venal,?array $fulcrum):array {
        $output=2346; $ingredients=[2312=>10,2319=>10];
        $ingredientNames=['Supertensile Plastics','Test Cultures'];
        $unitCost=0.0; $inputs=[]; $complete=self::fresh($jita);
        foreach($ingredients as $type=>$quantity){
            $name=$type===2312?'Supertensile Plastics':'Test Cultures';
            $quote=$complete?self::fills(self::orderRows($jita,$type,false,60003760),$quantity):null;
            if(!$quote){$complete=false;break;}
            $unitCost+=$quote['value'];$inputs[]=['type_id'=>$type,'item'=>$name,'quantity'=>$quantity,'cost_isk'=>$quote['value'],'order_ids'=>$quote['ids']];
        }
        $best=null;
        foreach([10000015=>['Venal',$venal,0],10001000=>['The Fulcrum',$fulcrum,0],10000002=>['Jita export',$jita,60003760]] as $region=>$row){
            [$name,$market,$station]=$row;
            if(!self::fresh($market))continue;
            foreach(self::orderRows($market,$output,true,$station) as $bid){
                if((int)$bid['v']<3)continue;
                $gross=3*(float)$bid['p'];$surplus=.925*$gross-$unitCost;
                if($complete && $surplus>0 && (!$best||$surplus>$best['surplus']))$best=['surplus'=>$surplus,'name'=>$name,'bid'=>$bid,'gross'=>$gross];
            }
        }
        if($best){
            return self::card('pi','market_context','PI: Synthetic Synapses',
                'PRODUCE 3 → SELL '.strtoupper($best['name']),
                '10 Supertensile Plastics + 10 Test Cultures · 1-hour P3 cycle',
                'NEXT STEPS: obtain 10 Supertensile Plastics and 10 Test Cultures (priced at Jita asks); supply a Venal Advanced Industry Facility on a suitable colony; run one hourly schematic cycle to produce 3 Synthetic Synapses; compare customs taxes, imports, exports and hauling; then sell to buyer order #'.(int)$best['bid']['i'].'. The estimate of '.number_format($best['surplus'],0).' ISK is after modeled 7.5% sales tax, BEFORE all colony taxes, facility/cargo costs, hauling and installation. Not verified net profit or planet extraction yield.',
                ['evidence'=>['item_type_id'=>$output,'inputs'=>$inputs,'output_qty'=>3,'cycle_hours'=>1,'buyer_order_id'=>(int)$best['bid']['i'],'buyer_station_id'=>(int)$best['bid']['l'],'buyer_region'=>$best['name'],'estimated_surplus_before_pi_costs'=>$best['surplus']],'metric_isk'=>$best['surplus'],'steps'=>['Buy or produce 10 Supertensile Plastics and 10 Test Cultures.','Confirm a Venal colony with an Advanced Industry Facility; set schematic 92 (1 hour).','Produce 3 Synthetic Synapses; calculate actual customs and import/export expenses.','If still profitable, verify buyer order #'.(int)$best['bid']['i'].' at station ID '.(int)$best['bid']['l'].' before hauling.'],'url'=>'/industry/']);
        }
        return self::card('pi','supply_objective','PI: Synthetic Synapses','RUN 1 P3 CYCLE IN VENAL',
            '10 Supertensile Plastics + 10 Test Cultures → 3 Synthetic Synapses / hour',
            'NEXT STEPS: select a suitable Venal planet and confirm its Advanced Industry Facility and colony access; import or produce 10 Supertensile Plastics plus 10 Test Cultures; produce 3 Synthetic Synapses in a one-hour cycle. This is a verified P3 schematic and Guristas blueprint ingredient, NOT a verified profitable colony. No reliable buyer/input quote combination survived the cost check. Check customs fees, extraction and transport before starting.',
            ['evidence'=>['item_type_id'=>$output,'input_names'=>$ingredientNames,'input_quantities'=>[10,10],'output_qty'=>3,'cycle_hours'=>1],'steps'=>['Confirm a Venal colony with an Advanced Industry Facility and suitable available planet.','Import or produce 10 Supertensile Plastics and 10 Test Cultures.','Run Synthetic Synapses schematic 92 for one hour to produce 3 units.','Check current input/output prices, customs charges and hauling before repeating.'],'url'=>'/industry/']);
    }
    private function fallbackManufacture():array {
        $worm=$this->ref['blueprints']['17931']??null;
        if(!$worm)return self::card('manufacture','unavailable','Blueprint evidence unavailable','CHECK INDUSTRY DATA','Worm blueprint reference missing','Do not infer manufacturing inputs without a verified reference.');
        $items=[];foreach(array_slice($worm['materials']??[],0,3) as $m)$items[]=$this->name((int)$m['typeID']).' ×'.(int)$m['quantity'];
        return self::card('manufacture','supply_objective','Worm blueprint supply chain','PREPARE WORM COMPONENTS',
            implode(' · ',$items),'Worm manufacturing recipe is from the site’s CCP SDE reference. No verified destination bid, manufacturing job cost, LP redemption economics, or executable profit is claimed.',
            ['evidence'=>['blueprint_id'=>17931,'product_type_id'=>17930],'url'=>'/industry/?view=build&bp=17931']);
    }
    public function collect():array {
        $snap=[];foreach([10000002,10000015,10001000] as $rid){$s=$this->intel->read('region-'.$rid);$snap[$rid]=self::fresh($s)?$s:null;}
        $o=$this->intel->read('lp-offers');$ts=strtotime((string)($o['fetched_at']??''));
        $offers=$o && is_array($o['offers']??null) && $ts!==false && time()-$ts<=172800?$o['offers']:[];
        $manufacture=$snap[10000002]&&$offers?$this->bestManufacture($snap,$offers):null;
        $pi=$this->piLead($snap[10000002],$snap[10000015],$snap[10001000]);
        $catalog=$this->intel->read('build-venal-planets');
        if(!empty($catalog['planets']) && is_array($catalog['planets'])) {
            $count=count($catalog['planets']);
            $example=$catalog['planets'][0]??null;
            if(is_array($example) && !empty($example['system_id'])){
                $planetKinds=[11=>'temperate',12=>'ice',13=>'gas',2014=>'oceanic',2015=>'lava',2016=>'barren',2017=>'storm',2063=>'plasma'];
                $kind=$planetKinds[(int)($example['type_id']??0)]??'unknown-type';
                $pi['reason'].=' Survey candidate: Venal planet ID '.(int)$example['id'].' in solar system ID '.(int)$example['system_id'].' ('.$kind.'). Confirm the planet name and colony access in EVE before acting; type alone cannot establish input extraction rates.';
                $pi['evidence']['survey_example_planet']=$example;
            }
            $pi['reason'].=' Current public planet catalog includes '.$count.' planet entries.';
            $pi['detail'].=' · '.$count.' Venal planet types surveyed'.(!empty($catalog['complete'])?' (catalog complete)':' (catalog in progress)');
            $pi['reason'].=' Public ESI planet-type inventory surveyed '.$count.' planets; does not establish resource density or full production-chain viability.';
            $pi['evidence']['venal_planets_surveyed']=$count;
            $pi['evidence']['planet_catalog_complete']=!empty($catalog['complete']);
        }
        $cards=[$manufacture??$this->fallbackManufacture(),$this->mineralLead($snap[10000002],$snap[10000015]),$pi];
        return ['as_of'=>gmdate('c'),'status'=>'ready','orders'=>$cards,'coverage'=>['activity_count'=>3,'manufacturing_recipes'=>count($this->ref['blueprints']),'market_snapshot_regions'=>array_keys(array_filter($snap)), 'qualifying_manufacturing_opportunity'=>$manufacture!==null, 'fallback_count'=>count(array_filter($cards,static fn($c)=>$c['state']==='supply_objective'))],
            'note'=>'Only MARKET LEAD can display an estimated margin, before omitted costs. MARKET CONTEXT provides observed prices, not activity profit. SUPPLY OBJECTIVE is a verified input relationship without verified economics.','source'=>'CCP SDE site reference, current Commando Guri LP offers and collected ESI regional market orders.'];
    }
}
