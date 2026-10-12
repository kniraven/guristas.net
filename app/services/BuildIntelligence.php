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
            foreach([10000015=>'Venal',10001000=>'The Fulcrum'] as $region=>$name){
                foreach(self::orderRows($markets[$region],$product,true) as $bid){
                    if((int)$bid['v']<$yield)continue;
                    $revenue=$yield*(float)$bid['p'];$margin=.925*$revenue-$input['cost']-$offer['cost'];
                    if($margin<=0 || ($best && $margin<=$best['margin']))continue;
                    $best=['margin'=>$margin,'blueprint_id'=>(int)$bpId,'product_type_id'=>$product,'yield'=>$yield,'cost'=>$input['cost'],'offer'=>$offer,'inputs'=>$input['inputs'],'bid_order_id'=>(int)$bid['i'],'bid_station_id'=>(int)$bid['l'],'region'=>$name,'revenue'=>$revenue];
                }
            }
        }
        if(!$best)return null;
        return self::card('manufacture','market_lead',$this->name($best['product_type_id']),'MANUFACTURE FOR '.$best['region'],
            '1 blueprint run · '.number_format($best['yield']).' finished unit(s)',
            'Recent, traceable destination buy order and full Jita material asks. BPC charged at full LP-store acquisition cost (1,000 ISK/LP). Modeled 7.5% sales tax; job fees, access, haul and BPC run count not verified.',
            ['metric_isk'=>$best['margin'],'metric_label'=>'MARGIN BEFORE JOB/HAUL','evidence'=>$best,'url'=>'/industry/?view=build&bp='.$best['blueprint_id']]);
    }
    /** Mineral demand is grounded in Guristas blueprints. Mining site/ore presence is NOT inferred from market data. */
    private function mineralLead(?array $jita):array {
        $use=[];
        foreach($this->ref['blueprints'] as $bp){
            foreach($bp['materials']??[] as $m){$id=(int)($m['typeID']??0);if($id>=34&&$id<=40)$use[$id]=($use[$id]??0)+(int)($m['quantity']??0);}
        }
        $chosen=null;
        foreach($use as $id=>$units){
            if(!self::fresh($jita))continue;
            $bid=self::orderRows($jita,$id,true,60003760)[0]??null;
            if(!$bid)continue;
            $value=$units*(float)$bid['p'];
            if($chosen===null || $value>$chosen['weighted'])$chosen=['id'=>$id,'weighted'=>$value,'price'=>(float)$bid['p'],'order'=>(int)$bid['i'],'units'=>$units];
        }
        if($chosen){
            return self::card('mine','market_context','Mine for '.$this->name($chosen['id']),'SCOUT VENAL ORE SOURCES',
                'Reference mineral: '.$this->name($chosen['id']).' · Jita bid '.number_format($chosen['price'],2).' ISK / unit',
                'Demand context comes from the Guristas blueprint recipes and a real Jita buy order. The weighted value ranks ingredients, NOT ore mining profitability. Ore availability, mineral yield, refinery taxes and local demand must be verified before mining.',
                ['evidence'=>['type_id'=>$chosen['id'],'jita_order_id'=>$chosen['order'],'blueprint_units'=>$chosen['units']],'url'=>'/industry/']);
        }
        // Morphite is an actual input in multiple Guristas blueprints, but local Mercoxit availability is not established.
        return self::card('mine','supply_objective','Morphite for Guristas production','LOCATE SUITABLE VENAL ORE',
            'Guristas blueprint input · verify ore presence and refining yield in-game',
            'The Guristas blueprint reference establishes material demand, not live asteroid availability or profitable extraction. Do not assume Mercoxit is present in any named system.',
            ['evidence'=>['material_type_id'=>11399,'blueprint_recipes_using_input'=>count(array_filter($this->ref['blueprints'],static fn($bp)=>in_array(11399,array_column($bp['materials']??[],'typeID'),true)))],'url'=>'/industry/']);
    }
    /** PI material demand from SDE recipes. Production-chain sourcing remains a planning lead. */
    private function piLead(?array $jita,?array $venal,?array $fulcrum):array {
        // Verified PI-derived ingredients used by the Guristas blueprint dataset.
        $ids=[2346,2348,12836];$uses=[];
        foreach($this->ref['blueprints'] as $bp){foreach($bp['materials']??[] as $m){$id=(int)($m['typeID']??0);if(in_array($id,$ids,true))$uses[$id]=($uses[$id]??0)+(int)$m['quantity'];}}
        $best=null;
        foreach($ids as $id){
            if(empty($uses[$id]))continue;
            $bid=null;$region='';
            foreach([10000015=>'Venal',10001000=>'The Fulcrum'] as $rid=>$name){
                if(!self::fresh($rid===10000015?$venal:$fulcrum))continue;
                $row=self::orderRows($rid===10000015?$venal:$fulcrum,$id,true)[0]??null;
                if($row && (!$bid || (float)$row['p']>(float)$bid['p'])){$bid=$row;$region=$name;}
            }
            if($bid){$score=(float)$bid['p']*$uses[$id];if($best===null||$score>$best['score'])$best=['id'=>$id,'score'=>$score,'region'=>$region,'bid'=>$bid,'use'=>$uses[$id]];}
        }
        if($best){
            return self::card('pi','market_context','PI: '.$this->name($best['id']),'PLAN INDUSTRIAL PI IN VENAL',
                'Observed '.$best['region'].' buyer · '.number_format((float)$best['bid']['p'],2).' ISK / unit',
                'PI-derived material is required by Guristas ship blueprints. An existing buy order supports demand, NOT extraction yield or net colony profit. Verify intermediate recipes, planetary suitability, customs taxes and transport.',
                ['evidence'=>['item_type_id'=>$best['id'],'order_id'=>(int)$best['bid']['i'],'required_in_blueprint_materials'=>$best['use']],'url'=>'/industry/']);
        }
        return self::card('pi','supply_objective','PI: Synthetic Synapses','ESTABLISH A VENAL PI SUPPLY CHAIN',
            'Guristas blueprint ingredient · survey suitable planets and production steps',
            'Synthetic Synapses (type 2346) is an actual ingredient in the local Guristas ship blueprint reference. This does not verify planet resource density, a specific planet, production feasibility for one character, or profitability.',
            ['evidence'=>['item_type_id'=>2346,'blueprint_recipes_using_input'=>count(array_filter($this->ref['blueprints'],static fn($bp)=>in_array(2346,array_column($bp['materials']??[],'typeID'),true)))],'url'=>'/industry/']);
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
        $manufacture=$snap[10000002]&&$snap[10000015]&&$snap[10001000]&&$offers?$this->bestManufacture($snap,$offers):null;
        $pi=$this->piLead($snap[10000002],$snap[10000015],$snap[10001000]);
        $catalog=$this->intel->read('build-venal-planets');
        if(!empty($catalog['planets']) && is_array($catalog['planets'])) {
            $count=count($catalog['planets']);
            $pi['detail'].=' · '.$count.' Venal planet types surveyed'.(!empty($catalog['complete'])?' (catalog complete)':' (catalog in progress)');
            $pi['reason'].=' Public ESI planet-type inventory surveyed '.$count.' planets; does not establish resource density or full production-chain viability.';
            $pi['evidence']['venal_planets_surveyed']=$count;
            $pi['evidence']['planet_catalog_complete']=!empty($catalog['complete']);
        }
        $cards=[$manufacture??$this->fallbackManufacture(),$this->mineralLead($snap[10000002]),$pi];
        return ['as_of'=>gmdate('c'),'status'=>'ready','orders'=>$cards,'coverage'=>['activity_count'=>3,'manufacturing_recipes'=>count($this->ref['blueprints']),'market_snapshot_regions'=>array_keys(array_filter($snap)), 'qualifying_manufacturing_opportunity'=>$manufacture!==null, 'fallback_count'=>count(array_filter($cards,static fn($c)=>$c['state']==='supply_objective'))],
            'note'=>'Only MARKET LEAD can display an estimated margin, before omitted costs. MARKET CONTEXT provides observed prices, not activity profit. SUPPLY OBJECTIVE is a verified input relationship without verified economics.','source'=>'CCP SDE site reference, current Commando Guri LP offers and collected ESI regional market orders.'];
    }
}
