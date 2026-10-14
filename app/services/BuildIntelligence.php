<?php
declare(strict_types=1);
require_once __DIR__.'/MarketIntelligence.php';
/** Server-published, source-backed industrial advice. No visitor-triggered market collection. */
final class GuristasBuildIntelligence {
    private GuristasMarketIntelligence $intel;
    private array $ref;
    private array $manufacturingDiagnostics=[];
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
            // A regional-range buy order can still be fulfilled at its listed
            // station. Do not presume it is fillable from other stations.
            // Check the order's minimum quantity at the actual fill site.
            $out[]=$o;
        }
        usort($out,static fn($a,$b)=>$buy?((float)$b['p']<=>(float)$a['p']):((float)$a['p']<=>(float)$b['p']));
        return $out;
    }
    private static function fills(array $rows,int $qty):?array {
        $rem=$qty;$sum=0.0;$ids=[];
        foreach($rows as $o){$n=min($rem,(int)$o['v']);if($n<(int)($o['m']??1))continue;$sum+=$n*(float)$o['p'];$rem-=$n;$ids[]=(int)$o['i'];if($rem===0)break;}
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
    /** Enrich compact recipes with matching LP blueprints from the full SDE index.
     * Only redeemable blueprints may produce priced manufacturing leads: the
     * complete index does not prove a blueprint can actually be acquired.
     */
    private function manufacturingReference(array $offers):array {
        $result=['status'=>'not_checked','catalog_recipes'=>null,'matched'=>0,'eligible_lp_blueprints'=>0,'unmatched_lp_blueprints'=>0,'catalog_match_rate_pct'=>null,'full_catalog_only_matches'=>0];
        $ids=[];
        foreach($offers as $offer){
            if((int)($offer['lp_cost']??0)<1 || (int)($offer['ak_cost']??0)!==0 || !empty($offer['required_items']))continue;
            $id=(int)($offer['type_id']??0);
            if($id>0)$ids[$id]=true;
        }
        $result['eligible_lp_blueprints']=count($ids);
        $build=(int)($this->ref['sde_build']??0);
        if(!$ids || $build<1000000)return [$this->ref['blueprints'],$result];
        $script=dirname(__DIR__,2).'/bin/guristas-build-catalog.py';
        if(!is_file($script)||!function_exists('proc_open')){
            $result['status']='reader_unavailable';return [$this->ref['blueprints'],$result];
        }
        $pipes=[];
        try {
            $process=@proc_open(['/usr/bin/python3',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));
            if(!is_resource($process))throw new RuntimeException('Catalog reader unavailable');
            fwrite($pipes[0],json_encode(['blueprint_ids'=>array_keys($ids),'sde_build'=>$build],JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $output=stream_get_contents($pipes[1],8388608);fclose($pipes[1]);
            $error=stream_get_contents($pipes[2],4096);fclose($pipes[2]);
            $code=proc_close($process);
            $decoded=json_decode($output,true);
            if($code!==0||!is_array($decoded))throw new RuntimeException('Catalog reader failed: '.substr($error,0,200));
            $result['status']=(string)($decoded['status']??'error');
            $result['catalog_recipes']=$decoded['catalog_recipes']??null;
            $result['matched']=(int)($decoded['matched']??0);
            $result['unmatched_lp_blueprints']=max(0,count($ids)-$result['matched']);
            $result['catalog_match_rate_pct']=round(100*$result['matched']/count($ids),2);
            $result['full_catalog_only_matches']=count(array_diff(array_keys($decoded['recipes']??[]),array_keys($this->ref['blueprints'])));
            if($result['status']!=='ready')return [$this->ref['blueprints'],$result];
            // Full SDE is authoritative for recipe quantities when the builds match.
            $recipes=$this->ref['blueprints'];
            foreach(($decoded['recipes']??[]) as $id=>$recipe){
                $recipes[(string)$id]=array_replace($recipes[(string)$id]??[],$recipe);
            }
            return [$recipes,$result];
        }catch(Throwable $e){
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            $result['status']='reader_error';
            return [$this->ref['blueprints'],$result];
        }
    }
    /** Location benefit estimate, not a quoted facility job cost. CCP's Fulcrum
     * pirate subcapital ME/TE bonuses must not be generalized to other products.
     * The user must confirm the actual in-game job quote and blueprint ME/TE.
     */
    private function fulcrumShipBenefits(array $bp):array {
        $type=(int)($bp['product']['typeID']??0);
        $known=[17930];
        $name=strtolower($this->name($type));
        $eligible=in_array($type,$known,true) || in_array($name,['worm','gila','mamba','alligator','rattlesnake','dramiel','cynabal','machariel'],true);
        $before=[];$after=[];
        if($eligible){
            foreach($bp['materials']??[] as $m){
                $qty=(int)($m['quantity']??0);
                if($qty<1)continue;
                $before[]=$qty;
                $after[]=max(1,(int)ceil($qty*0.94));
            }
        }
        $seconds=(int)($bp['manufacture_seconds']??$bp['time']??0);
        return ['eligible'=>$eligible,'material_reduction_pct'=>$eligible?6:0,
            'time_reduction_pct'=>$eligible?70:0,'base_time_seconds'=>$seconds?:null,
            'estimated_facility_time_seconds'=>$eligible&&$seconds?max(1,(int)ceil($seconds*.30)):null,
            'base_material_quantities'=>$before,'estimated_material_quantities'=>$after,
            'job_cost_verified'=>false,'scc_surcharge_reduction_eligible_if_pirate_enlisted'=>true];
    }
    /** Conservative price model: the 6% Fulcrum ME scenario is counted only for
     * known eligible pirate hulls with complete, executable adjusted material asks.
     * Job fees, hauling and other unverified charges remain EXCLUDED.
     */
    public static function manufacturePreJobMargin(float $revenue,float $pricedMaterials,float $blueprintAcquisition):float {
        return .925*$revenue-$pricedMaterials-$blueprintAcquisition;
    }
    private function bestManufacture(array $markets,array $offers,array $recipes):?array {
        $stats=['lp_blueprints_with_offers'=>0,'recipes_examined'=>0,'missing_input_quotes'=>0,'eligible_bonus_quote_unavailable'=>0,'positive_margin_buyers'=>0,'bid_depth_rejections'=>0];
        $bpOffers=[];
        foreach($offers as $o){
            $bpId=(int)($o['type_id']??0);
            if(!isset($recipes[(string)$bpId]) || (int)($o['lp_cost']??0)<1 || (int)($o['ak_cost']??0)!==0 || !empty($o['required_items']))continue;
            $cost=(float)($o['isk_cost']??0)+1000.0*(int)$o['lp_cost'];
            if(!isset($bpOffers[$bpId]) || $cost<$bpOffers[$bpId]['cost'])$bpOffers[$bpId]=['cost'=>$cost,'lp'=>(int)$o['lp_cost'],'isk'=>(float)($o['isk_cost']??0)];
        }
        $stats['lp_blueprints_with_offers']=count($bpOffers);
        $best=null;
        foreach($recipes as $bpId=>$bp){
            $offer=$bpOffers[(int)$bpId]??null;
            if(!$offer)continue;
            $stats['recipes_examined']++;
            // Never recommend a job requiring more runs than the verified blueprint allows.
            if(isset($bp['max_runs']) && (int)$bp['max_runs']<1)continue;
            if(isset($bp['manufacture_seconds']) && (int)$bp['manufacture_seconds']<1)continue;
            $benefits=$this->fulcrumShipBenefits($bp);
            $pricedBp=$bp;
            if($benefits['eligible']){
                foreach($pricedBp['materials'] as $k=>$material){
                    $pricedBp['materials'][$k]['quantity']=max(1,(int)ceil((int)$material['quantity']*.94));
                }
            }
            $input=$this->blueprintCosts($markets[10000002],$bp);
            if(!$input){$stats['missing_input_quotes']++;continue;}
            $fulcrumInput=$benefits['eligible']?$this->blueprintCosts($markets[10000002],$pricedBp):null;
            if($benefits['eligible'] && $fulcrumInput===null)$stats['eligible_bonus_quote_unavailable']++;
            // Do not count an ME benefit unless adjusted materials can actually
            // be priced with depth-qualified Jita asks.
            $rankedInput=$fulcrumInput??$input;
            $benefits['indicative_material_savings_isk']=$fulcrumInput===null?null:max(0.0,$input['cost']-$fulcrumInput['cost']);
            $product=(int)($bp['product']['typeID']??0);$yield=(int)($bp['product']['quantity']??0);
            if($product<1||$yield<1)continue;
            foreach([10000015=>'Venal',10001000=>'The Fulcrum',10000002=>'Jita export'] as $region=>$name){
                if(empty($markets[$region]))continue;
                $destination=$region===10000002?60003760:0;
                foreach(self::orderRows($markets[$region],$product,true,$destination) as $bid){
                    if((int)$bid['v']<$yield || (int)($bid['m']??1)>$yield){$stats['bid_depth_rejections']++;continue;}
                    $revenue=$yield*(float)$bid['p'];
                    $margin=self::manufacturePreJobMargin($revenue,$rankedInput['cost'],$offer['cost']);
                    if($margin>0)$stats['positive_margin_buyers']++;
                    if($margin<2000000 || ($best && $margin<=$best['margin']))continue;
                    $best=['margin'=>$margin,'blueprint_id'=>(int)$bpId,'product_type_id'=>$product,'yield'=>$yield,'recipe_max_runs'=>$bp['max_runs']??null,'manufacture_seconds'=>$bp['manufacture_seconds']??null,'cost'=>$rankedInput['cost'],'baseline_material_cost_isk'=>$input['cost'],'fulcrum_material_scenario_applied'=>$fulcrumInput!==null,'offer'=>$offer,'inputs'=>$rankedInput['inputs'],'bid_order_id'=>(int)$bid['i'],'bid_station_id'=>(int)$bid['l'],'region'=>$name,'revenue'=>$revenue,'buyer_unit_isk'=>(float)$bid['p'],'manufacturing_location'=>'The Fulcrum (planned; job quote unverified)','fulcrum_bonuses'=>$benefits];
                }
            }
        }
        $this->manufacturingDiagnostics=$stats;
        if(!$best)return null;
        // Benchmark buying completed output instead of manufacturing it. Buying
        // at posted Jita asks is a comparison, not guaranteed delivery in Venal.
        $finished=self::fills(self::orderRows($markets[10000002],$best['product_type_id'],false,60003760),$best['yield']);
        $best['jita_finished_ask_total_isk']=$finished['value']??null;
        $best['jita_finished_ask_orders']=$finished['ids']??[];
        $best['modeled_build_acquisition_isk']=$best['cost']+$best['offer']['cost'];
        $best['build_versus_finished_purchase_isk']=$finished===null?null:
            $finished['value']-$best['modeled_build_acquisition_isk'];
        return self::card('manufacture','market_lead','Manufacture '.$this->name($best['product_type_id']),($best['region']==='Jita export'?'BUILD AT FULCRUM → SELL JITA':'BUILD AT FULCRUM → SELL '.$best['region']),
            'BUYER: '.number_format($best['buyer_unit_isk'],2).' ISK/unit · '.number_format($best['yield']).' output · before job/haul',
            'FULCRUM: eligible Guristas/Angel subcapital blueprints may receive 6% facility ME, 70% facility TE; pirate-enlisted pilots may receive a 90% SCC surcharge reduction. Applied the 6% material scenario only when eligible and adjusted Jita inputs were quoted; see evidence. These are estimates, not verified job quotes. Verify actual eligibility and job preview. BUY-VERSUS-BUILD: '.($best['jita_finished_ask_total_isk']===null?'No fully executable Jita ask quote for finished items.':'Jita finished item purchase quote '.number_format($best['jita_finished_ask_total_isk']).' ISK; modeled blueprint plus materials '.number_format($best['modeled_build_acquisition_isk']).' ISK; modeled build acquisition advantage '.number_format($best['build_versus_finished_purchase_isk']).' ISK, before facility and hauling.').' NEXT STEPS: redeem the blueprint for '.number_format($best['offer']['lp']).' LP plus '.number_format($best['offer']['isk']).' ISK; buy the required materials at Jita 4-4; confirm The Fulcrum manufacturing eligibility, skills and exact job quote; run the blueprint once; sell '.number_format($best['yield']).' output unit(s) into buyer order #'.$best['bid_order_id'].' at station ID '.$best['bid_station_id'].'. Modeled sales tax 7.5% and LP 1,000 ISK/LP. Material estimates use actual asks. Before starting, verify blueprint runs and material efficiency, facility/job fees, hauling cost, buyer availability and safe transport. Margin is not net profit.',
            ['requires_skills'=>['3380'=>1],'metric_isk'=>$best['margin'],'metric_label'=>'MARGIN BEFORE JOB/HAUL','why_selected'=>'Highest modeled pre-job margin among LP-redeemable blueprints with quoted materials and a buyer; applies 6% Fulcrum material scenario only for eligible pirate hulls with depth-priced adjusted inputs. Actual job eligibility, blueprint ME, job fees, logistics and final net profit remain unverified.','evidence'=>$best,'steps'=>['Redeem blueprint: '.number_format($best['offer']['lp']).' Commando Guri LP + '.number_format($best['offer']['isk']).' ISK.','Buy blueprint materials at Jita 4-4; confirm the actual blueprint run count and skills.','Confirm an eligible manufacturing job and full cost quote at The Fulcrum BEFORE hauling.','Build '.number_format($best['yield']).' unit(s); verify then fulfill buyer order #'.$best['bid_order_id'].' at station ID '.$best['bid_station_id'].'.'],'url'=>'/industry/?view=build&bp='.$best['blueprint_id']]);
    }
    /** Reprocessing reference value at an explicitly assumed 50% yield.
     *  This is a scenario, NOT a claim about the logged-in character's skills,
     *  facilities or attainable output. Current Jita buyer depth is required.
     */
    private function reprocessingScenario(?array $jita,int $type,int $units):?array {
        $recipe=$this->ref['reprocessing'][(string)$type]??null;
        if(!self::fresh($jita)||!is_array($recipe))return null;
        $portion=(int)($recipe['portion_size']??0);
        if($portion<1||$units<$portion)return null;
        $batches=intdiv($units,$portion);$value=0.0;$outputs=[];
        foreach($recipe['materials']??[] as $material){
            $typeId=(int)($material['materialTypeID']??0);
            $base=(int)($material['quantity']??0);
            $count=(int)floor($batches*$base*0.5);
            if($typeId<1||$base<1||$count<1)continue;
            $quote=self::fills(self::orderRows($jita,$typeId,true,60003760),$count);
            if(!$quote)return null;
            $value+=$quote['value'];
            $outputs[]=['type_id'=>$typeId,'name'=>$this->name($typeId),'quantity'=>$count,'quoted_buyers'=>$quote['ids'],'gross_value_isk'=>$quote['value']];
        }
        return $outputs?['assumed_yield'=>0.5,'portion_size'=>$portion,'batch_count'=>$batches,'gross_isk'=>$value,'outputs'=>$outputs]:null;
    }
    /** Shared regional eligibility catalog; never inferred from a market order. */
    public static function venalMiningCatalog(?string $path=null, ?array $types=null):array {
        $path ??= is_file(dirname(__DIR__,2).'/storage/venal-mining-resources.json') ? dirname(__DIR__,2).'/storage/venal-mining-resources.json' : dirname(__DIR__,2).'/config/venal-mining-resources.json';
        $json=@file_get_contents($path);
        $data=is_string($json)?json_decode($json,true):null;
        if(!is_array($data)||($data['region_id']??null)!==10000015||!is_array($data['resources']??null))
            throw new RuntimeException('Venal resource eligibility catalog missing or invalid');
        $types ??= [];
        $out=[];
        foreach($data['resources'] as $entry){
            if(!is_array($entry))throw new RuntimeException('Malformed resource catalog row');
            $id=(int)($entry['type_id']??0);
            $status=$entry['status']??'';
            if($id<1||!in_array($status,['eligible','disputed','disabled'],true)||isset($out[$id]))
                throw new RuntimeException('Invalid or duplicate Venal mining catalog type');
            $category=$entry['category']??'';
            if(!in_array($category,['ore','ice','gas'],true)||empty($entry['site'])||empty($entry['source'])||empty($entry['basis']))
                throw new RuntimeException('Incomplete Venal resource evidence');
            $reference=$types[(string)$id]??null;
            if(!is_array($reference)||!isset($reference['volume'])||(float)$reference['volume']<=0)
                throw new RuntimeException('Venal catalog resource missing CCP SDE type/volume: '.$id);
            if(isset($reference['name'])&&$reference['name']!==$entry['name'])
                throw new RuntimeException('Venal catalog resource name differs from CCP SDE: '.$id);
            $entry['m3']=(float)$reference['volume'];
            $entry['min_skill']=(int)($entry['skill_id']??0);
            $entry['minimum']=1;
            $out[$id]=$entry;
        }
        return $out;
    }
    private function miningCatalogCoverage():array {
        $all=self::venalMiningCatalog(null,$this->ref['types']??[]);
        $counts=['total'=>count($all),'eligible'=>0,'disputed'=>0,'disabled'=>0,'by_category'=>['ore'=>0,'gas'=>0,'ice'=>0]];
        foreach($all as $entry){$counts[$entry['status']]++;if($entry['status']==='eligible')$counts['by_category'][$entry['category']]++;}
        return $counts;
    }
    /** A listing target, not an immediately executable bid. Require observable,
     * non-dust buy and sell depth; keep Fulcrum as the sole sale destination. */
    public static function fulcrumMiningPrice(int $type,float $m3,array $markets):?array {
        if($m3<=0)return null;
        $target=max(1,(int)floor(1000/$m3));
        $bids=[];$asks=[];$sources=[];
        foreach($markets as $region=>$market){
            if(!self::fresh($market))continue;
            // Jita 4-4 and Dodixie IX-20; Fulcrum/Venal orders can be in any NPC station.
            $station=($region===10000002?60003760:($region===10000032?60011866:0));
            foreach([true,false] as $buy){
                $rows=self::orderRows($market,$type,$buy,$station);
                if(!$rows)continue;
                $byStation=[];
                foreach($rows as $row)$byStation[(int)$row['l']][]=$row;
                foreach($byStation as $stationRows){
                    $quantity=0;$value=0.0;
                    foreach($stationRows as $row){
                        $take=min($target-$quantity,(int)$row['v']);
                        if($take<max(1,(int)($row['m']??1)))continue;
                        $quantity+=$take;$value+=$take*(float)$row['p'];
                        if($quantity===$target)break;
                    }
                    // Reject dust quotes: minimum 20% reference lot or one ice unit.
                    if($quantity<max(1,(int)ceil($target*0.20)))continue;
                    $entry=['price'=>$value/$quantity,'quantity'=>$quantity,'region'=>(int)$region];
                    if($buy)$bids[]=$entry;else $asks[]=$entry;
                }
            }
        }
        if(!$bids&&!$asks)return null;
        usort($bids,static fn($a,$b)=>$b['price']<=>$a['price']);
        usort($asks,static fn($a,$b)=>$a['price']<=>$b['price']);
        // If both sides exist, midpoint; if one is absent, use the only observable
        // side as an explicitly lower-confidence reference, never invented liquidity.
        $buy=$bids[0]??null;$sell=$asks[0]??null;
        $price=($buy&&$sell)?($buy['price']+$sell['price'])/2:($buy['price']??$sell['price']);
        if(!is_finite($price)||$price<=0)return null;
        return ['listing_unit_isk'=>round($price,2),'mode'=>$buy&&$sell?'midpoint':'one_sided',
            'bid_source'=>$buy,'ask_source'=>$sell,'target_units'=>$target,
            'value_isk_per_m3'=>$price/$m3];
    }
    private function resourceLeads(?array $jita,?array $venal,?array $fulcrum=null,?array $dodixie=null):array {
        $eligible=array_filter(self::venalMiningCatalog(null,$this->ref['types']??[]),
            static fn(array $r):bool=>$r['status']==='eligible');
        $markets=[10000002=>$jita,10000032=>$dodixie,10000015=>$venal,10001000=>$fulcrum];
        $leads=[];
        foreach($eligible as $id=>$resource){
            $price=self::fulcrumMiningPrice((int)$id,(float)$resource['m3'],$markets);
            if(!$price)continue;
            $unit=$price['listing_unit_isk'];$perM3=$unit/$resource['m3'];
            $name=$resource['name'];$target=$price['target_units'];
            $leads[]=self::card('mine','market_context','Mine '.$name.' in Venal',
                'MINE '.strtoupper($name).' IN VENAL → LIST AT THE FULCRUM',
                'SUGGESTED FULCRUM SELL ORDER '.number_format($unit,2).' ISK/unit',
                'VENAL SPAWN EVIDENCE: '.$resource['basis'].'. SOURCE: '.$resource['source'].'. SITE: '.$resource['site'].'. Suggested listing price is based on current retained market buy/sell orders at monitored hubs and Fulcrum; it is NOT a guaranteed sale, buyer bid or net mining profit. Pricing method: '.$price['mode'].'.',
                ['why_selected'=>'Highest modeled Fulcrum listing ISK/m³ among eligible Venal resources with retained market pricing; listings are not guaranteed sales.',
                 'resource_category'=>$resource['category'],'requires_skills'=>[(string)$resource['min_skill']=>1],
                 'metric_isk'=>$perM3,'metric_label'=>'SUGGESTED LIST VALUE / ISK/m³',
                 'evidence'=>['item_type_id'=>$id,'item_category'=>$resource['category'],'unit_m3'=>$resource['m3'],
                    'eligibility_status'=>'eligible','eligibility_source'=>$resource['source'],'eligibility_basis'=>$resource['basis'],
                    'eligible_site'=>$resource['site'],'suggested_sell_unit_isk'=>$unit,
                    'price_method'=>$price['mode'],'buy_reference'=>$price['bid_source'],'sell_reference'=>$price['ask_source'],
                    'gross_isk_per_m3'=>$perM3,'reference_lot_units'=>$target,'listing_destination'=>'The Fulcrum',
                    'live_deposit_verified'=>false],
                 'steps'=>['Mine '.$name.' in Venal from '.$resource['site'].'.',
                    'Create a sell order at The Fulcrum for approximately '.number_format($unit,2).' ISK per unit.',
                    'The listed price is an estimated asking price, not a guaranteed immediate sale.'],
                 'url'=>'/industry/']);
        }
        usort($leads,static fn($a,$b)=>($b['evidence']['gross_isk_per_m3']<=>$a['evidence']['gross_isk_per_m3'])
            ?:($a['evidence']['item_type_id']<=>$b['evidence']['item_type_id']));
        return $leads;
    }
    private function mineralLead(?array $jita,?array $venal=null,?array $fulcrum=null,?array $dodixie=null):array {
        $leads=$this->resourceLeads($jita,$venal,$fulcrum,$dodixie);
        if($leads){$best=$leads[0];$best['alternatives']=array_slice($leads,1);return $best;}
        $eligible=array_filter(self::venalMiningCatalog(null,$this->ref['types']??[]),static fn(array $r):bool=>$r['status']==='eligible');
        if(!$eligible)throw new RuntimeException('No eligible Venal mining resources in catalog');
        $resource=reset($eligible);
        return self::card('mine','supply_objective','Mine '.$resource['name'].' in Venal',
            'MINE '.strtoupper($resource['name']).' IN VENAL → LIST AT THE FULCRUM',
            'NO DEFENSIBLE LIST PRICE · '.strtoupper($resource['category']),
            'ELIGIBILITY: '.$resource['basis'].'. Source: '.$resource['source'].'. Site: '.$resource['site'].'. No usable market quotes currently exist to set a defensible Fulcrum asking price. This is a supply objective, not a proven best-value choice.',
            ['why_selected'=>'First eligible catalog resource as transparent fallback only; economic winner unavailable.',
             'resource_category'=>$resource['category'],'requires_skills'=>[(string)$resource['min_skill']=>1],
             'evidence'=>['item_type_id'=>$resource['type_id'],'eligibility_status'=>'eligible',
                'eligibility_source'=>$resource['source'],'eligible_site'=>$resource['site'],
                'live_deposit_verified'=>false],
             'steps'=>['Mine '.$resource['name'].' in Venal from '.$resource['site'].'.',
                'Supply The Fulcrum. The market evidence cannot currently support a specific suggested sale price.'],
             'url'=>'/industry/']);
    }
    /** Evaluate imported official SDE planetary schematics using actual posted
     *  Jita input asks and a physically deliverable destination bid.
     *  The result is a cash margin BEFORE customs, hauling and colony costs.
     */
    /** Rank comparable PI cycles by modeled contribution per factory-hour,
     *  not an arbitrary single cycle's nominal cash surplus. Taxes and
     *  transport beyond the assumed sales tax remain explicitly excluded.
     */
    public static function piHourlyScore(float $margin,int $seconds):?float {
        return $margin>0 && $seconds>0 ? $margin*3600.0/$seconds : null;
    }
    private function importedPiLead(?array $jita,?array $venal,?array $fulcrum):?array {
        if(!self::fresh($jita)||empty($this->ref['pi_schematics']))return null;
        $best=null;$evaluated=0;$qualified=0;$rejectedMissingPrices=0;$rejectedNoBuyer=0;
        foreach($this->ref['pi_schematics'] as $schematicId=>$recipe){
            $product=(int)($recipe['output']['typeID']??0);
            $qty=(int)($recipe['output']['quantity']??0);
            $cycle=(int)($recipe['cycle_seconds']??0);
            if($product<1||$qty<1||$cycle<1||empty($recipe['inputs']))continue;
            $evaluated++;
            $inputCost=0.0;$inputs=[];$fullyPriced=true;
            foreach($recipe['inputs'] as $ingredient){
                $inputId=(int)($ingredient['typeID']??0);
                $count=(int)($ingredient['quantity']??0);
                if($inputId<1||$count<1){$fullyPriced=false;break;}
                $quote=self::fills(self::orderRows($jita,$inputId,false,60003760),$count);
                if(!$quote){$fullyPriced=false;break;}
                $inputCost+=$quote['value'];
                $inputs[]=['type_id'=>$inputId,'name'=>$this->name($inputId),'quantity'=>$count,'cost_isk'=>$quote['value'],'order_ids'=>$quote['ids']];
            }
            if(!$fullyPriced){$rejectedMissingPrices++;continue;}
            $foundBuyer=false;
            // Fulcrum and Venal buyers are evaluated first, but economic ranking
            // still requires a qualified bid. Jita remains a benchmark/export.
            foreach([10001000=>[$fulcrum,'The Fulcrum',0],10000015=>[$venal,'Venal',0],10000002=>[$jita,'Jita',60003760]] as $destination){
                [$market,$place,$station]=$destination;
                if(!self::fresh($market))continue;
                foreach(self::orderRows($market,$product,true,$station) as $buyer){
                    if((int)$buyer['v']<$qty||(int)($buyer['m']??1)>$qty)continue;
                    $foundBuyer=true;
                    $gross=$qty*(float)$buyer['p'];
                    $margin=.925*$gross-$inputCost;
                    $hourly=self::piHourlyScore($margin,$cycle);
                    if($hourly===null)continue;
                    $qualified++;
                    if($best!==null && $hourly<=$best['hourly'])continue;
                    $best=['margin'=>$margin,'hourly'=>$hourly,'cycle'=>$cycle,
                        'schematic_id'=>(int)$schematicId,'recipe'=>$recipe,
                        'inputs'=>$inputs,'input_cost'=>$inputCost,'gross'=>$gross,
                        'product_id'=>$product,'qty'=>$qty,'buyer'=>$buyer,'destination'=>$place];
                }
            }
            if(!$foundBuyer)$rejectedNoBuyer++;
        }
        if($best===null)return null;
        $inputWords=[];
        foreach($best['inputs'] as $input)$inputWords[]=number_format($input['quantity']).' '.$input['name'];
        $steps=['Establish or use a Venal colony with a suitable factory for this schematic; confirm the chosen planet is accessible.',
            'Obtain '.implode(' + ',$inputWords).' (Jita input ask benchmark).',
            'Route inputs into the factory, run schematic #'.$best['schematic_id'].' for '.number_format($best['cycle']/60,1).' minutes, and collect '.number_format($best['qty']).' '.$this->name($best['product_id']).'.',
            'Calculate import/export customs, factory/colony costs, hauling and taxes before repeating.',
            'Verify destination buyer #'.(int)$best['buyer']['i'].' at station ID '.(int)$best['buyer']['l'].' before delivery.'];
        return self::card('pi','market_context','Produce '.$this->name($best['product_id']),
            'PRODUCE ON VENAL PLANET → '.($best['destination']==='Jita'?'BENCHMARK JITA BUYER':'SUPPLY '.$best['destination'].' BUYER'),
            'BUYER: '.number_format((float)$best['buyer']['p'],2).' ISK/unit · '.number_format($best['qty']).' units per '.number_format($best['cycle']/60,1).'-minute cycle',
            'SDE schematic #'.$best['schematic_id'].'. Quoted input cost '.number_format($best['input_cost']).' ISK at Jita; quoted gross revenue '.number_format($best['gross']).' ISK. Modeled pre-PI-cost margin '.number_format($best['margin']).' ISK/cycle, equivalent to '.number_format($best['hourly']).' ISK/factory-hour at continuous production. EXCLUDES customs, colony, hauling, installations, and practical downtime. A buyer order does not verify local planet extraction or recurring sales. NEXT STEPS: '.implode(' ',$steps),
            ['why_selected'=>'Highest positive modeled margin per factory-hour among SDE PI schematics with complete Jita input quotes and sufficient single-station buyer depth; compared '.number_format($evaluated).' recipes, '.number_format($qualified).' qualified buyer/recipe combinations. Not net ISK/hour.',
                'requires_skills'=>[], 'metric_isk'=>$best['margin'], 'metric_label'=>'BEFORE PI + HAUL COSTS',
                'evidence'=>['schematic_id'=>$best['schematic_id'],'input_cost_isk'=>$best['input_cost'],'inputs'=>$best['inputs'],
                    'output_type_id'=>$best['product_id'],'output_qty'=>$best['qty'],'cycle_seconds'=>$best['cycle'],
                    'buyer_order_id'=>(int)$best['buyer']['i'],'buyer_station_id'=>(int)$best['buyer']['l'],'buyer_region'=>$best['destination'],'buyer_unit_isk'=>(float)$best['buyer']['p'],
                    'modeled_margin_before_pi_and_haul'=>$best['margin'],'modeled_margin_per_factory_hour_before_pi_costs'=>$best['hourly'],
                    'pi_recipes_evaluated'=>$evaluated,'pi_buyer_candidates_qualified'=>$qualified,
                    'pi_missing_input_quote_rejections'=>$rejectedMissingPrices,'pi_no_buyer_rejections'=>$rejectedNoBuyer,
                    'total_imported_schematics'=>count($this->ref['pi_schematics'])],
                'steps'=>$steps,'url'=>'/industry/']);
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
                if((int)$bid['v']<3 || (int)($bid['m']??1)>3)continue;
                $gross=3*(float)$bid['p'];$surplus=.925*$gross-$unitCost;
                if($complete && $surplus>0 && (!$best||$surplus>$best['surplus']))$best=['surplus'=>$surplus,'name'=>$name,'bid'=>$bid,'gross'=>$gross];
            }
        }
        if($best){
            return self::card('pi','market_context','Produce Synthetic Synapses',
                'PRODUCE IN VENAL → EVALUATE FULCRUM SUPPLY',
                '10 Supertensile Plastics + 10 Test Cultures · 1-hour P3 cycle',
                'NEXT STEPS: obtain 10 Supertensile Plastics and 10 Test Cultures (priced at Jita asks); supply a Venal Advanced Industry Facility on a suitable colony; run one hourly schematic cycle to produce 3 Synthetic Synapses; compare customs taxes, imports, exports and hauling; then sell to buyer order #'.(int)$best['bid']['i'].'. The estimate of '.number_format($best['surplus'],0).' ISK is after modeled 7.5% sales tax, BEFORE all colony taxes, facility/cargo costs, hauling and installation. Not verified net profit or planet extraction yield.',
                ['requires_skills'=>[],'why_selected'=>'Synthetic Synapses is an input to a Guristas blueprint chain. A one-hour P3 schematic is documented, but the present market data cannot prove positive colony profit.','evidence'=>['item_type_id'=>$output,'inputs'=>$inputs,'output_qty'=>3,'cycle_hours'=>1,'buyer_order_id'=>(int)$best['bid']['i'],'buyer_station_id'=>(int)$best['bid']['l'],'buyer_region'=>$best['name'],'estimated_surplus_before_pi_costs'=>$best['surplus']],'metric_isk'=>$best['surplus'],'steps'=>['Establish or use a Venal colony with an Advanced Industry Facility; confirm you can access the planet.' , 'Buy or produce 10 Supertensile Plastics and 10 Test Cultures.','Confirm a Venal colony with an Advanced Industry Facility; set schematic 92 (1 hour).','Route both inputs into the factory and select Synthetic Synapses; collect 3 units after one hour; calculate customs/transport.','If still profitable, verify buyer order #'.(int)$best['bid']['i'].' at station ID '.(int)$best['bid']['l'].' before hauling.'],'url'=>'/industry/']);
        }
        $breakEven=$complete?round($unitCost/(3.0*(1.0-0.075)),2):null;
        return self::card('pi','supply_objective','Produce Synthetic Synapses','SET UP P3 FACTORY IN VENAL',
            '10 Supertensile Plastics + 10 Test Cultures → 3 Synthetic Synapses / hour',
            'NEXT STEPS: select a suitable Venal planet and confirm its Advanced Industry Facility and colony access; import or produce 10 Supertensile Plastics plus 10 Test Cultures; produce 3 Synthetic Synapses in a one-hour cycle. This is a verified P3 schematic and Guristas blueprint ingredient, NOT a verified profitable colony. No reliable buyer/input quote combination survived the cost check. '.($breakEven===null?'Input pricing incomplete.':'Minimum modeled sale price '.number_format($breakEven,2).' ISK per output unit covers purchased inputs and modeled 7.5% sale tax only, NOT colony charges or hauling.').' Check customs fees, extraction and transport before starting.',
            ['requires_skills'=>[],'why_selected'=>'Synthetic Synapses is an input to a Guristas blueprint chain. A one-hour P3 schematic is documented, but the present market data cannot prove positive colony profit.','evidence'=>['item_type_id'=>$output,'input_names'=>$ingredientNames,'input_quantities'=>[10,10],'output_qty'=>3,'cycle_hours'=>1,'priced_inputs_isk'=>$complete?$unitCost:null,'break_even_sale_unit_isk_before_pi_costs'=>$breakEven],'steps'=>['Choose an accessible planet in Venal; establish a colony with a command center and an Advanced Industry Facility (or use an existing one).','Import or produce 10 Supertensile Plastics and 10 Test Cultures.','Route both inputs into the Advanced Industry Facility, select Synthetic Synapses and run one one-hour cycle for 3 units.','Check current input/output prices, customs charges and hauling before repeating.'],'url'=>'/industry/']);
    }
    private function fallbackManufacture():array {
        $worm=$this->ref['blueprints']['17931']??null;
        if(!$worm)return self::card('manufacture','unavailable','Blueprint evidence unavailable','CHECK INDUSTRY DATA','Worm blueprint reference missing','Do not infer manufacturing inputs without a verified reference.');
        $items=[];foreach(array_slice($worm['materials']??[],0,3) as $m)$items[]=$this->name((int)$m['typeID']).' ×'.(int)$m['quantity'];
        return self::card('manufacture','supply_objective','Manufacture Worm at The Fulcrum','PREPARE WORM COMPONENTS',
            implode(' · ',$items),'Worm manufacturing recipe is from the site’s CCP SDE reference. No verified destination bid, manufacturing job cost, LP redemption economics, or executable profit is claimed.',
            ['requires_skills'=>['3380'=>1],'why_selected'=>'This blueprint has documented material requirements relevant to a Guristas ship, but its profit and local buyer are unverified.','steps'=>['Open the industry tool and verify the Worm blueprint and material requirements.','Check which components you can source or manufacture in Venal.','Confirm a usable manufacturing facility, LP redemption costs and a buyer before starting.'],'evidence'=>['blueprint_id'=>17931,'product_type_id'=>17930],'url'=>'/industry/?view=build&bp=17931']);
    }
    public function collect():array {
        $snap=[];foreach([10000002,10000015,10001000,10000032] as $rid){$s=$this->intel->read('region-'.$rid);$snap[$rid]=self::fresh($s)?$s:null;}
        $o=$this->intel->read('lp-offers');$ts=strtotime((string)($o['fetched_at']??''));
        $offers=$o && is_array($o['offers']??null) && $ts!==false && time()-$ts<=172800?$o['offers']:[];
        [$recipes,$catalogCoverage]=$this->manufacturingReference($offers);
        $manufacture=$snap[10000002]&&$offers?$this->bestManufacture($snap,$offers,$recipes):null;
        $pi=$this->importedPiLead($snap[10000002],$snap[10000015],$snap[10001000]) ?? $this->piLead($snap[10000002],$snap[10000015],$snap[10001000]);
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
        $cards=[$manufacture??$this->fallbackManufacture(),$this->mineralLead($snap[10000002],$snap[10000015],$snap[10001000],$snap[10000032]),$pi];
        return ['as_of'=>gmdate('c'),'status'=>'ready','orders'=>$cards,'coverage'=>['activity_count'=>3,'manufacturing_recipes'=>count($recipes),'manufacturing_catalog'=>$catalogCoverage,'imported_pi_schematics'=>count($this->ref['pi_schematics']??[]),'reprocessing_references'=>count($this->ref['reprocessing']??[]),'market_snapshot_regions'=>array_keys(array_filter($snap)),'fulcrum_location_mode'=>'manufacture_at_fulcrum;mine_in_venal_supply_fulcrum;pi_on_planet_supply_fulcrum','jita_sale_cards'=>count(array_filter($cards,static fn($c)=>strpos((string)($c['action']??''),'SELL JITA')!==false)), 'qualifying_manufacturing_opportunity'=>$manufacture!==null,'manufacturing_candidate_diagnostics'=>$this->manufacturingDiagnostics, 'fallback_count'=>count(array_filter($cards,static fn($c)=>$c['state']==='supply_objective')),'resource_alternatives'=>count($cards[1]['alternatives']??[]),'resource_benchmark_types'=>array_keys(array_filter(self::venalMiningCatalog(null,$this->ref['types']??[]),static fn(array $r):bool=>$r['status']==='eligible')),'venal_resource_catalog'=>$this->miningCatalogCoverage(),'pi_benchmark_types'=>[2312,2319,2346],'venal_planets_surveyed'=>count($catalog['planets']??[])],
            'note'=>'Only MARKET LEAD can display an estimated margin, before omitted costs. MARKET CONTEXT provides observed prices, not activity profit. SUPPLY OBJECTIVE is a verified input relationship without verified economics.','source'=>'CCP SDE site reference, current Commando Guri LP offers and collected ESI regional market orders.'];
    }
}
