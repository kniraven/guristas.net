<?php
declare(strict_types=1);
require_once __DIR__.'/MarketIntelligence.php';

/** Offline order selection. Never makes ESI requests from the public API. */
final class GuristasTradeOrders {
    private GuristasMarketIntelligence $intel;
    private array $ref;
    private array $marketStationRegions=[];
    private int $excludedOutsidePirateEconomy=0;
    // CCP base sales tax for a character with Accounting 0. Personalization
    // requires an explicitly authorized character skill read, not public ESI.
    private const BASE_SALES_TAX=0.075;
    private const ASSUMED_ACCOUNTING_LEVEL=0;
    private const LP_SHADOW=1000.0;
    private const MAX_QTY=10000;
    // NPC station listing fee is an assumption until the pilot's Broker Relations
    // skill and unmodified NPC corporation/faction standings are known.
    private const ASSUMED_LISTING_BROKER_FEE=0.03;
    public function __construct(?GuristasMarketIntelligence $intel=null, ?array $reference=null){
        $this->intel=$intel??new GuristasMarketIntelligence();
        $this->ref=$reference??guri_industry_reference();
    }
    private function label(int $id):string{return (string)($this->ref['types'][(string)$id]['name']??'Item #'.$id);}
    private function money(float $v):string{return $v>=1000000000?number_format($v/1e9,2).'B ISK':($v>=1000000?number_format($v/1e6,2).'M ISK':number_format($v,0).' ISK');}
    private function age(?array $snap):int{return $snap&&isset($snap['fetched_at'])?(time()-(strtotime((string)$snap['fetched_at'])?:0)):PHP_INT_MAX;}
    private function npc(int $station):bool{return $station>=60000000&&$station<61000000;}
    private function normalizeOrders(array $snap):array{
        $buyers=[];$sellers=[];
        foreach($snap['orders']??[] as $o){
            if(!is_array($o)||!isset($o['t'],$o['l'],$o['p'],$o['v']))continue;
            $t=(int)$o['t'];$loc=(int)$o['l'];
            if($t<=0||$loc<=0||(int)$o['v']<=0||(float)$o['p']<=0)continue;
            if(!empty($o['b']))$buyers[$t][$loc][]=$o;else $sellers[$t][$loc][]=$o;
        }
        return [$buyers,$sellers];
    }
    /** Evaluate competing source stations rather than only the cheapest globally. */
    private function acquireOptions(array $stationOrders,int $qty,int $limit=6):array{
        $options=[];
        foreach($stationOrders as $station=>$orders){
            if(!$this->npc((int)$station))continue;
            usort($orders,static fn($a,$b)=>(float)$a['p']<=>(float)$b['p']);
            $need=$qty;$cost=0.0;
            foreach($orders as $row){
                $take=min($need,(int)$row['v']);$cost+=$take*(float)$row['p'];$need-=$take;
                if($need===0)break;
            }
            if($need===0)$options[]=['cost'=>$cost,'station'=>(int)$station];
        }
        usort($options,static fn($a,$b)=>$a['cost']<=>$b['cost']);
        return array_slice($options,0,$limit);
    }
    private function acquire(array $stationOrders,int $qty):?array{
        return $this->acquireOptions($stationOrders,$qty,1)[0]??null;
    }
    /** Sell to one bid only, honoring minimum buy quantity. No cross-station fill mixing. */
    private function bids(array $destinations,int $qty):array{
        $out=[];
        foreach($destinations as $station=>$orders){
            foreach($orders as $o){
                if((int)$o['v']<$qty||(int)($o['m']??1)>$qty)continue;
                $out[]=['station'=>(int)$station,'revenue'=>$qty*(float)$o['p'],'unit'=>(float)$o['p']];
            }
        }
        usort($out,static fn($a,$b)=>$b['revenue']<=>$a['revenue']);
        return array_slice($out,0,12);
    }
    private function costInputs(array $inputs,int $mult,array $allSellers):?array{
        $total=0.0;$missing=[];
        foreach($inputs as $r){
            $id=(int)($r['type_id']??$r['typeID']??0);
            $qty=(int)($r['quantity']??0)*$mult;
            if(!$id||$qty<1)continue;
            $best=$this->acquire($allSellers[$id]??[],$qty);
            if(!$best){$missing[]=$id;continue;}
            $total+=$best['cost'];
        }
        return $missing?null:['cost'=>$total];
    }
    /** A listing is indicative, not an executable bid. Never conflate the two. */
    private function listingQuotes(array $destSellers,array $destBuyers,int $qty):array{
        $quotes=[];
        foreach($destSellers as $station=>$asks){
            if(empty($destBuyers[$station]))continue; // Some real evidence of local buyer interest.
            $lowest=INF;
            foreach($asks as $ask) $lowest=min($lowest,(float)$ask['p']);
            if(!is_finite($lowest)||$lowest<=0)continue;
            $demand=0;$topBid=0.0;
            foreach($destBuyers[$station] as $bid){
                $demand+=(int)$bid['v'];$topBid=max($topBid,(float)$bid['p']);
            }
            if($demand<1||$topBid<=0)continue;
            // Quote at the prevailing lowest ask, without promising queue priority or fill.
            $quotes[]=['station'=>(int)$station,'unit'=>$lowest,'revenue'=>$qty*$lowest,
                'demand'=>$demand,'topBid'=>$topBid];
        }
        usort($quotes,static fn($a,$b)=>$b['revenue']<=>$a['revenue']);
        return array_slice($quotes,0,3);
    }
    /** Regional executed market history is evidence of activity, never of sales at one NPC station. */
    private function highestBid(array $bids):float{
        $max=0.0;foreach($bids as $bid)$max=max($max,(float)($bid['p']??0));return $max;
    }
    /** Regional history is evidence of activity, never a verified station sell-through rate. */
    private function historyEvidence(int $region,int $type,int $qty,float $ask,float $bid,int $stationBidVolume, int $competingAskUnits=0):array{
        $snapshot=$this->intel->marketHistory($region,$type);
        if(!$snapshot||empty($snapshot['verified'])||!is_array($snapshot['days']??null))return ['reject'=>'history_missing'];
        $days=[];$now=strtotime(gmdate('Y-m-d').' 00:00:00 UTC');
        foreach($snapshot['days'] as $row){
            $stamp=strtotime((string)($row['date']??''));
            if($stamp===false||$stamp>$now||$stamp<$now-29*86400)continue;
            $volume=(int)($row['volume']??0);$average=(float)($row['average']??0);
            if($volume>0&&$average>0)$days[]=['volume'=>$volume,'average'=>$average,'date'=>$stamp];
        }
        $total=array_sum(array_column($days,'volume'));
        $recent=array_values(array_filter($days,static fn($row)=>$row['date']>=$now-13*86400));
        $recentVolume=array_sum(array_column($recent,'volume'));
        if(count($recent)<8||count($days)<17)return ['reject'=>'history_inconsistent'];
        if($recentVolume<$qty*30||$total<$qty*60)return ['reject'=>'history_volume'];
        $weighted=0.0;foreach($recent as $day)$weighted+=$day['average']*$day['volume'];
        $average=$weighted/max(1,$recentVolume);
        // A genuine spread between bids and asks is normal. Reject absurd asks
        // using actual regional executions, not an arbitrary 5% bid/ask spread.
        if($ask>$average*1.15||$ask<$average*.65)return ['reject'=>'price_vs_trades'];
        $avgs=array_column($recent,'average');sort($avgs,SORT_NUMERIC);
        $mid=$avgs[intdiv(count($avgs),2)];
        if($mid<=0||$ask>$mid*1.20)return ['reject'=>'price_instability'];
        if($bid<=0||$stationBidVolume<$qty*3)return ['reject'=>'local_bid_depth'];
        // Quoted competition is not completed station sales. It still reduces
        // the chance that a new listing sells promptly.
        if($competingAskUnits>max(10,$recentVolume*0.5))return ['reject'=>'competing_supply'];
        return ['regional_14d_units'=>$recentVolume,'regional_14d_active_days'=>count($recent),
            'regional_14d_vwap_isk'=>round($average,2),'local_bid_units'=>$stationBidVolume,
            'local_best_bid_isk'=>$bid,'competing_ask_units'=>$competingAskUnits,
            'regional_14d_daily_units'=>round($recentVolume/14,2),'station_sales_verified'=>false,
            'history_collected_at'=>$snapshot['collected_at']??null];
    }
    /** Round the proposed ask down to a legal four-significant-figure price. */
    private function validAsk(float $price):float{
        if($price<=0||!is_finite($price))return 0.0;
        $step=pow(10,max(-2,(int)floor(log10($price))-3));
        return max(0.01,floor($price/$step+1e-9)*$step);
    }
    /** Only physically verified Venal stations or The Fulcrum qualify as a trade endpoint. */
    private function isPirateMarketEndpoint(int $station):bool{
        if($station<=0)return false; // Unknown LP redemption location is not evidence of an origin.
        if($station===(int)$this->ref['hubs']['fulcrum']['station_id'])return true;
        return ($this->marketStationRegions[$station]??0)===10000015;
    }
    private function candidate(int $type,int $qty,float $cost,float $lp,float $revenue,int $srcStation,int $destStation,string $kind,string $goal,string $note,bool $conditional=false,bool $listing=false,int $depth=0):?array{
        if(!str_starts_with($kind,'LP ') && !$this->isPirateMarketEndpoint($srcStation)&&!$this->isPirateMarketEndpoint($destStation)){
            $this->excludedOutsidePirateEconomy++;
            return null;
        }
        $salesTaxRate=self::BASE_SALES_TAX*(1-0.11*self::ASSUMED_ACCOUNTING_LEVEL);
        $salesTax=$revenue*$salesTaxRate;
        $brokerFee=$listing?$revenue*self::ASSUMED_LISTING_BROKER_FEE:0.0;
        $net=$revenue-$salesTax-$brokerFee-$cost-$lp*self::LP_SHADOW;
        $capital=$cost+$lp*self::LP_SHADOW;
        $roi=$net/max(1,$capital);
        if($net<1000000||$roi<.05)return null;
        // Temper very large speculative returns and excessive capital commitments.
        $capitalEfficiency=0.45+0.55*min(1.0,$roi/0.35);
        $liquidity=$depth>0?min(1.0,$depth/max(1,$qty)):0.45;
        $depthFactor=0.70+0.30*$liquidity;
        $score=$net*$capitalEfficiency*$depthFactor; // The pirate destinations are eligibility requirements, not bonus points.
        if($conditional)$score*=.65;
        if($listing)$score*=.42; // Unsold inventory cannot compete as confirmed revenue.
        return compact('type','qty','cost','lp','revenue','srcStation','destStation','kind','goal','note','conditional','net','score','salesTax','brokerFee','listing','depth','roi');
    }
    private function hold(string $label,string $explanation):array{
        return ['status'=>'standby','category'=>$label,'title'=>'NO VERIFIED ORDER','item'=>'AWAIT FRESH INTELLIGENCE','route'=>$explanation,'margin'=>'NET // NOT VERIFIED','quantity'=>'QTY // NONE','reason'=>'No profit claim is made. Check EVE market orders before traveling.'];
    }
    /** Select three distinct items while keeping financial quality independent of coverage. */
    private static function selectPortfolio(array $candidates):array{
        $groups=[];
        foreach($candidates as $candidate){
            $mask=(int)($candidate['coverage_mask']??0);
            if($mask>0)$groups[$mask][]=$candidate;
        }
        $shortlist=[];
        foreach($groups as $group){
            usort($group,static fn($a,$b)=>$b['score']<=>$a['score']);
            $seen=[];
            foreach($group as $candidate){
                if(isset($seen[$candidate['type']]))continue;
                $seen[$candidate['type']]=true;
                $shortlist[]=$candidate;
                if(count($seen)>=12)break;
            }
        }
        usort($shortlist,static fn($a,$b)=>$b['score']<=>$a['score']);
        $benchmark=[];$seen=[];
        foreach($candidates as $candidate){
            $type=(int)$candidate['type'];
            if(!isset($seen[$type])||(float)$candidate['score']>(float)$seen[$type]['score'])$seen[$type]=$candidate;
        }
        usort($seen,static fn($a,$b)=>$b['score']<=>$a['score']);
        $benchmark=array_slice($seen,0,3);
        $benchmarkScore=array_sum(array_column($benchmark,'score'));
        $describe=static function(array $combination):array{
            return array_map(static fn($x)=>[
                'type_id'=>(int)$x['type'],'kind'=>(string)$x['kind'],
                'goal'=>(string)$x['goal'],'coverage_mask'=>(int)($x['coverage_mask']??0),
                'source_id'=>(int)$x['srcStation'],'destination_id'=>(int)$x['destStation'],
                'profit_isk'=>round((float)$x['net'],2),'score'=>round((float)$x['score'],2),
                'new_order'=>!empty($x['buy_order'])||!empty($x['listing']),
            ],$combination);
        };
        $best=[];$bestMask=0;$bestEconomic=0.0;$bestVariety=0;
        $bestCovered=-1;$bestAdjusted=-INF;$top=[];$checked=0;
        $n=count($shortlist);
        for($i=0;$i<$n;$i++)for($j=$i+1;$j<$n;$j++){
            $a=$shortlist[$i];$b=$shortlist[$j];
            if($a['type']===$b['type'])continue;
            $pairMask=$a['coverage_mask']|$b['coverage_mask'];
            for($k=$j+1;$k<$n;$k++){
                $c=$shortlist[$k];
                if($c['type']===$a['type']||$c['type']===$b['type'])continue;
                $trio=[$a,$b,$c];$mask=$pairMask|$c['coverage_mask'];
                $covered=substr_count(decbin($mask),'1');
                $economic=(float)$a['score']+(float)$b['score']+(float)$c['score'];
                $strategies=[];
                foreach($trio as $item){
                    $strategy=!empty($item['buy_order'])?'CREATE BUY':(!empty($item['listing'])?'CREATE SELL':(str_starts_with($item['kind'],'LP ')?'LP':'IMMEDIATE'));
                    $strategies[$strategy]=true;
                }
                $variety=count($strategies);
                $adjusted=$economic*(1+0.0075*max(0,$variety-1));
                $checked++;
                if($covered>$bestCovered||($covered===$bestCovered&&$adjusted>$bestAdjusted)){
                    $best=$trio;$bestMask=$mask;$bestEconomic=$economic;$bestVariety=$variety;
                    $bestCovered=$covered;$bestAdjusted=$adjusted;
                }
                // Keep audit bounded. Prefer five-requirement finalists; among equal
                // coverage, keep the best adjusted economic scores.
                $record=['mask'=>$mask,'covered'=>$covered,'score'=>$economic,'adjusted'=>$adjusted,'variety'=>$variety,'items'=>$describe($trio)];
                if(count($top)<6||$covered>$top[count($top)-1]['covered']||($covered===$top[count($top)-1]['covered']&&$adjusted>$top[count($top)-1]['adjusted'])){
                    $top[]=$record;
                    usort($top,static fn($x,$y)=>($y['covered']<=>$x['covered'])?:($y['adjusted']<=>$x['adjusted']));
                    if(count($top)>6)array_pop($top);
                }
            }
        }
        if(!$best){
            $used=[];
            foreach($shortlist as $c){
                if(isset($used[$c['type']]))continue;
                $used[$c['type']]=true;$best[]=$c;$bestMask|=$c['coverage_mask'];
                if(count($best)>=3)break;
            }
            $bestEconomic=array_sum(array_column($best,'score'));
        }
        $gap=max(0.0,$benchmarkScore-$bestEconomic);
        $byRequirement=[];
        foreach(['FULCRUM'=>1,'VENAL'=>2,'GURISTAS_LP'=>4,'NEW_ORDER'=>8,'IMMEDIATE_TRADE'=>16] as $name=>$bit){
            $byRequirement[$name]=[];
            foreach($shortlist as $c){
                if(($c['coverage_mask']&$bit)===0)continue;
                $byRequirement[$name][]=$describe([$c])[0];
                if(count($byRequirement[$name])>=5)break;
            }
        }
        $audit=[
            'eligible_combinations_checked'=>$checked,
            'shortlisted_candidates'=>count($shortlist),
            'unrestricted_top_three'=>$describe($benchmark),
            'unrestricted_combined_score'=>round($benchmarkScore,2),
            'selected_combined_score'=>round($bestEconomic,2),
            'opportunity_score_difference'=>round($gap,2),
            'opportunity_score_difference_pct'=>$benchmarkScore>0?round(100*$gap/$benchmarkScore,2):null,
            'winning_combination'=>$describe($best),
            'leading_alternatives'=>array_slice($top,1,5),
            'best_by_requirement'=>$byRequirement,
            'note'=>'Quality scores are ranking units, not ISK. Difference compares the best three distinct items unrestricted versus the best supported set meeting the most requirements. Only fresh collected markets were considered.',
        ];
        return [$best,$bestMask,$bestEconomic,$bestVariety,$audit];
    }
    public function collect():array{
        $this->marketStationRegions=[];
        $this->excludedOutsidePirateEconomy=0;
        $allBuy=[];$allSell=[];$destBuy=['FULCRUM'=>[],'VENAL'=>[]];$coverage=[];$oldest=time();$stationRegions=[];
        $regions=$this->intel->regionIds();
        foreach(array_unique(array_merge([10001000,10000015],$regions)) as $region){
            $s=$this->intel->read('region-'.$region);
            if(!$s||empty($s['complete']))continue;
            $age=$this->age($s);
            $coverage[$region]=['fetched_at'=>$s['fetched_at'],'age_seconds'=>$age,'pages'=>$s['pages']??0,'orders'=>count($s['orders']??[])];
            if($age<0||$age>10800)continue; // No recommendations from snapshots over two hours old.
            $oldest=min($oldest,strtotime($s['fetched_at']));
            [$buys,$sells]=$this->normalizeOrders($s);
            foreach($sells as $t=>$stations)foreach($stations as $station=>$list)if($this->npc((int)$station)){
                $stationRegions[(int)$station]=(int)$region;
                $allSell[$t][$station]=array_merge($allSell[$t][$station]??[],$list);
            }
            foreach($buys as $t=>$stations)foreach($stations as $station=>$list)if($this->npc((int)$station)){
                $stationRegions[(int)$station]=(int)$region;
                $allBuy[$t][$station]=array_merge($allBuy[$t][$station]??[],$list);
                if($region===10001000 && (int)$station===(int)$this->ref['hubs']['fulcrum']['station_id'])$destBuy['FULCRUM'][$t][$station]=$list;
                if($region===10000015)$destBuy['VENAL'][$t][$station]=$list;
            }
        }
        $this->marketStationRegions=$stationRegions;
        $offers=$this->intel->read('lp-offers');
        $validOffers=$offers&&($this->age($offers)<86400)&&is_array($offers['offers']??null);
        $byReward=[];
        if($validOffers)foreach($offers['offers'] as $offer){
            if(!is_array($offer)||(int)($offer['ak_cost']??0)>0||(int)($offer['lp_cost']??0)<1||(int)($offer['quantity']??0)<1)continue;
            $byReward[(int)$offer['type_id']][]=$offer;
        }
        $pool=[];$listingPool=[];$demandCount=count(array_unique(array_merge(array_keys($destBuy['FULCRUM']),array_keys($destBuy['VENAL']))));
        $destinations=['FULCRUM'=>$destBuy['FULCRUM'],'VENAL'=>$destBuy['VENAL'],'EXPORT'=>$allBuy];
        foreach($destinations as $goal=>$types){
            foreach($types as $type=>$stations){
                if($goal==='EXPORT' && !isset($byReward[$type]))continue;
                $type=(int)$type;
                foreach([1,2,5,10,20,100,1000,10000] as $qty){
                    $bids=$this->bids($stations,$qty);
                    if(!$bids)continue;
                    if($goal!=='EXPORT')foreach($bids as $b){
                        foreach($this->acquireOptions($allSell[$type]??[],$qty) as $acq){
                            if($acq['station']===$b['station'])continue;
                            $c=$this->candidate($type,$qty,$acq['cost'],0,$b['revenue'],$acq['station'],$b['station'],'MARKET',$goal,'Buy at source sell orders; immediately fulfill the destination station buy order. Both ends are based on observed order depth.',false,false,$qty);
                            if($c)$pool[]=$c;
                        }
                    }
                    foreach($byReward[$type]??[] as $offer){
                        $yield=(int)$offer['quantity'];
                        if($qty%$yield)continue;
                        $redemptions=intdiv($qty,$yield);
                        $required=$this->costInputs($offer['required_items']??[],$redemptions,$allSell);
                        if(!$required)continue;
                        $lp=(float)$offer['lp_cost']*$redemptions;
                        $cost=(float)$offer['isk_cost']*$redemptions+$required['cost'];
                        $bp=$this->ref['blueprints'][(string)$type]??null;
                        if($bp){
                            // Conservative one run per copy; actual LP-issued BPC runs/facility must be confirmed in EVE.
                            $product=(int)($bp['product']['typeID']??0);$outQty=(int)($bp['product']['quantity']??0);
                            if(!$product||$outQty<1)continue;
                            $material=$this->costInputs($bp['materials']??[],$qty,$allSell);
                            if(!$material)continue;
                            $cost+=$material['cost']+max(100000.0,$material['cost']*.03); // Estimated facility/job allowance.
                            foreach($this->bids($destinations[$goal][$product]??($goal==='EXPORT'?($allBuy[$product]??[]):[]),$qty*$outQty) as $finishedBid){
                                $c=$this->candidate($product,$qty*$outQty,$cost,$lp,$finishedBid['revenue'],0,$finishedBid['station'],'LP BUILD',$goal,'Conditional: assumes one manufacturing run per LP blueprint copy, no ME reduction; includes 3% estimated job allowance. Confirm BPC runs, facility access, inputs and haul route.',true);
                                if($c)$pool[]=$c;
                            }
                        }else foreach($bids as $b){
                            $c=$this->candidate($type,$qty,$cost,$lp,$b['revenue'],0,$b['station'],'LP REDEEM',$goal,'Redeem Commando Guri LP with required items priced at available NPC market sell orders. LP assigned 1,000 ISK shadow value.',false,false,$qty);
                            if($c)$pool[]=$c;
                        }
                    }
                }
            }
        }
        // CREATE BUY ORDER: post a competitive bid at a source with active asks,
        // then (only if that buy order fills) deliver to a firm pirate-market bid.
        // This is NOT an immediate acquisition or guaranteed fulfillment.
        $buyOrderPool=[];
        foreach(['FULCRUM'=>$destBuy['FULCRUM'],'VENAL'=>$destBuy['VENAL']] as $goal=>$types){
            foreach($types as $type=>$destinationStations){
                if(empty($allSell[$type])||empty($allBuy[$type]))continue;
                foreach([1,2,5,10,20,100,1000] as $qty){
                    foreach($this->bids($destinationStations,$qty) as $exit){
                        foreach($allSell[$type] as $sourceStation=>$asks){
                            $sourceStation=(int)$sourceStation;
                            if($sourceStation===$exit['station']||empty($allBuy[$type][$sourceStation]))continue;
                            $lowestAsk=INF;$askVolume=0;
                            foreach($asks as $ask){
                                $price=(float)$ask['p'];
                                if($price<$lowestAsk){$lowestAsk=$price;$askVolume=(int)$ask['v'];}
                                elseif($price===$lowestAsk)$askVolume+=(int)$ask['v'];
                            }
                            $highestBid=$this->highestBid($allBuy[$type][$sourceStation]);
                            if(!is_finite($lowestAsk)||$lowestAsk<=0||$highestBid<=0||$askVolume<$qty)continue;
                            // Compete with current bids while keeping an attractive
                            // discount to actual listed supply. Never pretend an ask
                            // means the proposed bid will be fulfilled.
                            $tick=pow(10,max(-2,(int)floor(log10($highestBid))-3));
                            $proposed=$this->validAsk($highestBid+$tick);
                            if($proposed<=$highestBid)$proposed+=$tick;
                            if($proposed>$lowestAsk*.97)continue;
                            $buyCost=$proposed*$qty;
                            $fee=$buyCost*self::ASSUMED_LISTING_BROKER_FEE;
                            $c=$this->candidate((int)$type,$qty,$buyCost+$fee,0,$exit['revenue'],$sourceStation,$exit['station'],
                                'CREATE BUY ORDER',$goal,'Post a competitive buy order at the source. No seller has agreed to fill it. Only after it fills can you haul the goods to the observed destination bid. Existing ask stock and competing bids support pricing, not a future fill.',
                                true,false,$qty);
                            if(!$c)continue;
                            $c['buy_order']=true;
                            $c['buy_order_unit_price']=$proposed;
                            $c['buy_order_broker_fee']=$fee;
                            $c['source_lowest_ask']=$lowestAsk;
                            $c['source_ask_units']=$askVolume;
                            // Conditional bid: observed competing orders establish a
                            // price, not a fill probability. Reward deeper visible
                            // supply only within a bounded ranking adjustment.
                            $askCoverage=min(3.0,$askVolume/max(1,$qty));
                            $bidCoverage=min(3.0,count($allBuy[$type][$sourceStation]));
                            $c['order_evidence']=['source_ask_coverage'=>$askCoverage,'source_bid_count'=>(int)$bidCoverage,'destination_bid_units'=>$qty];
                            $c['score']*=.62*(0.85+0.10*min(1.0,$askCoverage/3.0)+0.05*min(1.0,$bidCoverage/3.0));
                            $buyOrderPool[]=$c;
                        }
                    }
                }
            }
        }
        usort($buyOrderPool,static fn($a,$b)=>$b['score']<=>$a['score']);
        // Conservative listing research: two independent observations required.
        // 1. CCP completed-trade history establishes REGION-wide executions.
        // 2. Local station bids/asks establish current price and nearby liquidity.
        // Neither establishes completed sales at this exact station.
        // Budget history fetches to keep cron predictable on a small EC2 host.
        $listingDestinations=['FULCRUM'=>$destBuy['FULCRUM'],'VENAL'=>$destBuy['VENAL'],'EXPORT'=>$allBuy];
        // Broader candidate discovery reuses cached major-hub order books; it does
        // not start additional ESI scans. Limit external items to pirate demand.
        $wanted=array_fill_keys(array_unique(array_merge(array_keys($destBuy['FULCRUM']),array_keys($destBuy['VENAL']))),true);
        $hubRegions=[10000002,10000032,10000043,10000042,10000030];
        foreach($listingDestinations as $goal=>$types){
            foreach($types as $type=>$stations){
                if($goal==='EXPORT'&&!isset($wanted[$type]))continue;
                if(empty($allSell[$type]))continue;
                $type=(int)$type;
                foreach([1,2,5,10,20,100] as $qty){
                    // Compare cheap sources with pirate-market sources independently.
                    // An outside destination qualifies ONLY when stock is bought in
                    // Venal or The Fulcrum. The global cheapest station can be elsewhere.
                    $acquisitions=$this->acquireOptions($allSell[$type],$qty,6);
                    if($goal==='EXPORT'){
                        $pirateSources=[];
                        foreach($allSell[$type] as $sourceStation=>$sourceRows){
                            if(!$this->isPirateMarketEndpoint((int)$sourceStation))continue;
                            $found=$this->acquireOptions([(int)$sourceStation=>$sourceRows],$qty,1);
                            if($found)$pirateSources[]=$found[0];
                        }
                        usort($pirateSources,static fn($a,$b)=>$a['cost']<=>$b['cost']);
                        $acquisitions=array_slice($pirateSources,0,6);
                    }
                    if(!$acquisitions)continue;
                    foreach($stations as $station=>$stationBids){
                        if($goal==='EXPORT'&&!in_array($stationRegions[(int)$station]??0,$hubRegions,true))continue;
                        if(empty($allSell[$type][$station]))continue;
                        $lowest=INF;$bidMax=0.0;$bidVolume=0;
                        foreach($allSell[$type][$station] as $ask)$lowest=min($lowest,(float)$ask['p']);
                        foreach($stationBids as $bid){$bidMax=max($bidMax,(float)$bid['p']);$bidVolume+=(int)$bid['v'];}
                        if(!is_finite($lowest)||$bidMax<=0||$bidVolume<$qty*3)continue;
                        $lowest=$this->validAsk($lowest);
                        $revenue=$qty*$lowest;
                        foreach($acquisitions as $acq){
                            if($station===$acq['station'])continue;
                            $c=$this->candidate($type,$qty,$acq['cost'],0,$revenue,$acq['station'],(int)$station,
                                'LIST FOR SALE',$goal,'Conditional listing: completed trades are verified region-wide, not at this station. Local bids support pricing but do not guarantee an eventual buyer.',
                                true,true,$bidVolume);
                            if($c)$listingPool[]=$c;
                        }
                    }
                }
            }
        }
        // Warm a small historical-data cache even if zero listings survive the
        // initial live-order screen. CCP history is regional, never station-level.
        // Max six new lookups per publication, including later listing checks.
        $historyBudget=6;
        $warm=[];
        foreach($listingDestinations as $goal=>$types){
            foreach($types as $type=>$stations){
                if($goal==='EXPORT'&&!isset($wanted[$type]))continue;
                if(empty($allSell[$type]))continue;
                $volume=0;$bestBid=0.0;$warmRegion=0;
                foreach($stations as $station=>$bids){
                    $region=$stationRegions[(int)$station]??0;
                    if($goal==='EXPORT'&&!in_array($region,$hubRegions,true))continue;
                    if(empty($allSell[$type][$station]))continue;
                    foreach($bids as $bid){$volume+=(int)$bid['v'];$bestBid=max($bestBid,(float)$bid['p']);$warmRegion=$region;}
                }
                if($volume<1||$bestBid<=0||$warmRegion<=0)continue;
                $warm[]=['region'=>$warmRegion,'type'=>(int)$type,'priority'=>log(1+$volume)*log(1+$bestBid)];
            }
        }
        usort($warm,static fn($a,$b)=>$b['priority']<=>$a['priority']);
        $warmed=0;
        foreach($warm as $entry){
            if($warmed>=$historyBudget)break;
            $key='history-'.$entry['region'].'-'.$entry['type'];
            $cached=$this->intel->read($key);
            if($cached && (time()-(strtotime((string)($cached['collected_at']??''))?:0))<86400)continue;
            $this->intel->marketHistory($entry['region'],$entry['type']);
            $warmed++;
        }
        usort($listingPool,static fn($a,$b)=>$b['score']<=>$a['score']);
        $validatedListings=[];$rejects=['history_missing'=>0,'history_inconsistent'=>0,'history_volume'=>0,'price_vs_trades'=>0,'price_instability'=>0,'local_bid_depth'=>0,'competing_supply'=>0,'history_budget'=>0];
        // Limit ESI history lookups per publish. Cached history is reused for a day.
        $lookups=$warmed;$seenHistory=[];
        foreach($listingPool as $c){
            $region=$stationRegions[$c['destStation']]??0;
            if($region<=0)continue;
            $key=$region.'-'.$c['type'];
            if(!array_key_exists($key,$seenHistory)){
                $cached=$this->intel->read('history-'.$key);
                $cacheFresh=$cached&&(time()-(strtotime((string)($cached['collected_at']??''))?:0)<86400);
                if(!$cacheFresh&&$lookups>=6){$rejects['history_budget']++;continue;}
                if(!$cacheFresh)$lookups++;
                $seenHistory[$key]=true;
            }
            $bidDepth=$c['depth'];
            $localAsks=$allSell[$c['type']][$c['destStation']]??[];
            $competingAskUnits=0;
            foreach($localAsks as $askRow){if((float)$askRow['p']<=$c['revenue']/$c['qty']*1.01)$competingAskUnits+=(int)$askRow['v'];}
            $evidence=$this->historyEvidence($region,$c['type'],$c['qty'],$c['revenue']/$c['qty'],
                $this->highestBid($listingDestinations[$c['goal']][$c['type']][$c['destStation']]??[]),$bidDepth,$competingAskUnits);
            if(isset($evidence['reject'])){$rejects[$evidence['reject']]++;continue;}
            $c['evidence']=$evidence;
            // Regional history is evidence of real trading, not proof that
            // this station will fill the listing. Use small bounded scoring
            // adjustments for volume and visible local competition only.
            $historyCoverage=min(1.0,((float)($evidence['regional_14d_units']??0))/max(1,$c['qty']*30));
            $supplyPressure=min(1.0,$competingAskUnits/max(1,$bidDepth));
            $c['score']*=0.90+0.15*$historyCoverage-0.05*$supplyPressure;
            $c['note'].=' CCP regional 14-day completed volume '.number_format($evidence['regional_14d_units']).
                ' units across '.$evidence['regional_14d_active_days'].' active days; 14-day volume-weighted average '.
                $this->money($evidence['regional_14d_vwap_isk']).'/unit. Destination local bid depth '.
                number_format($bidDepth).' units. Station-specific executions and time-to-sale are not publicly verified.';
            $validatedListings[]=$c;
        }
        // Existing buy-order opportunities remain independent of the new-order card.
        foreach($validatedListings as $candidate)$pool[]=$candidate;
        // Guristas officer/deadspace specialty goods: compare actual market buy depth
        // with publicly offered sell inventory. These are loot/arbitrage, not LP redemption.
        $special=[];
        foreach($this->ref['types'] as $id=>$item){
            $name=(string)($item['name']??'');
            if(preg_match('/(?:Estamel|Vepas|Thon|Kaikka|Pith [ABCX]-Type|Reptile Pit)/i',$name))$special[(int)$id]=true;
        }
        foreach(array_keys($special) as $type){
            if(empty($allBuy[$type])||empty($allSell[$type]))continue;
            foreach([1,2,5,10] as $qty){
                foreach($this->bids($allBuy[$type],$qty) as $bid){
                    foreach($this->acquireOptions($allSell[$type],$qty) as $acq){
                        if($acq['station']===$bid['station'])continue;
                        $c=$this->candidate($type,$qty,$acq['cost'],0,$bid['revenue'],$acq['station'],$bid['station'],'SPECIALTY MARKET','EXPORT','Rare Guristas loot: ordinary buy-low/sell-high calculation, not an LP-store reward.',false,false,$qty);
                        if($c)$pool[]=$c;
                    }
                }
            }
        }
        // Full product BPC matching needs product-side demand, even when the blueprint itself has no buy orders.
        if($validOffers)foreach($offers['offers'] as $offer){
            $bp=$this->ref['blueprints'][(string)($offer['type_id']??0)]??null;
            if(!$bp||!empty($offer['ak_cost']))continue;
            $product=(int)($bp['product']['typeID']??0);$output=(int)($bp['product']['quantity']??1);
            $copies=(int)($offer['quantity']??0);if($copies<1||!$product||$output<1)continue;
            $requirements=$this->costInputs($offer['required_items']??[],1,$allSell);
            $materials=$this->costInputs($bp['materials']??[],$copies,$allSell);
            if(!$requirements||!$materials)continue;
            $cost=(float)($offer['isk_cost']??0)+$requirements['cost']+$materials['cost']+max(100000.0,$materials['cost']*.03);
            foreach($destinations as $goal=>$set){
                foreach($this->bids($set[$product]??[],$copies*$output) as $b){
                    $c=$this->candidate($product,$copies*$output,$cost,(float)$offer['lp_cost'],$b['revenue'],0,$b['station'],'LP BUILD',$goal,'Conditional 1-run-per-copy BPC manufacture; verify actual BPC runs, material efficiency, facility access and industry fees.',true);
                    if($c)$pool[]=$c;
                }
            }
        }
        // Pick three distinct items as a GROUP, not as three independent slots.
        // Required coverage: Fulcrum endpoint, Venal endpoint, Guristas LP and
        // at least one proposed new buy/sell order. Coverage changes the choice,
        // NEVER the monetary profit/ROI reported for an individual candidate.
        $proposedOrders=array_merge($validatedListings,$buyOrderPool);
        $allCandidates=array_merge($pool,$proposedOrders);
        $hub=(int)$this->ref['hubs']['fulcrum']['station_id'];
        $maskFor=function(array $c)use($hub):int{
            $source=(int)$c['srcStation'];$destination=(int)$c['destStation'];
            $mask=0;
            if($source===$hub||$destination===$hub)$mask|=1;
            if(($this->marketStationRegions[$source]??0)===10000015||($this->marketStationRegions[$destination]??0)===10000015)$mask|=2;
            if(str_starts_with($c['kind'],'LP '))$mask|=4;
            if(!empty($c['buy_order'])||!empty($c['listing']))$mask|=8;
            // An actual buy from existing asks followed by a sale to an
            // existing bid; LP redemption is NOT an immediate market purchase.
            if(in_array($c['kind'],['MARKET','SPECIALTY MARKET'],true)&&empty($c['buy_order'])&&empty($c['listing']))$mask|=16;
            return $mask;
        };
        foreach($allCandidates as &$c){$c['coverage_mask']=$maskFor($c);}
        unset($c);
        [$picked,$bestMask,$bestEconomic,$bestVariety,$selectionAudit]=self::selectPortfolio($allCandidates);
        usort($picked,static function($a,$b){
            $rank=static function($c){
                $mask=$c['coverage_mask'];
                if(($mask&1)&&!($mask&4))return 0;
                if(($mask&2)&&!($mask&4))return 1;
                if($mask&4)return 2;
                return 3;
            };
            return $rank($a)<=>$rank($b);
        });
        $orders=[];
        foreach($picked as $p){
            $lp=str_starts_with($p['kind'],'LP ');$conditional=$p['conditional'];
            $orders[]=['status'=>$conditional?'conditional':'actionable','category'=>$p['goal'].' // '.$p['kind'],
                'title'=>!empty($p['buy_order'])?'POST BID // WAIT FOR FILL':($p['listing']?'BUY // LIST // UNCONFIRMED':($conditional?'BUILD // VERIFY // SELL':($lp?'REDEEM // DELIVER // SELL':'BUY // DELIVER // SELL'))),
                'item'=>$this->label($p['type']),
                'route'=>($lp?'USE LP + INPUTS':'BUY AT NPC STATION #'.$p['srcStation']).' → SELL '.$p['qty'].' TO NPC STATION #'.$p['destStation'],
                'margin'=>(!empty($p['buy_order'])?'POTENTIAL / BUY ORDER NOT FILLED':($p['listing']?'UNREALIZED / STATION SALES UNKNOWN':($conditional?'POTENTIAL PRE-HAUL':'PRE-HAUL'))).' // +'.$this->money($p['net']),
                'quantity'=>'QTY // '.$p['qty'].' · ROI '.number_format($p['roi']*100,1).'%', 'listing'=>$p['listing'], 'buy_order'=>!empty($p['buy_order']), 'buy_order_unit_price'=>$p['buy_order_unit_price']??null, 'source_lowest_ask'=>$p['source_lowest_ask']??null, 'source_ask_units'=>$p['source_ask_units']??null, 'market_depth_units'=>$p['depth'], 'market_evidence'=>$p['evidence']??null, 'confidence'=>!empty($p['buy_order'])?'PROPOSED BUY ORDER / NOT FILLED':(!empty($p['listing'])?'REGIONAL SALES / STATION SALES UNKNOWN':(!empty($p['conditional'])?'CONDITIONAL':'FIRM BID SNAPSHOT')),
                'source_id'=>$p['srcStation'],'destination_id'=>$p['destStation'],'type_id'=>$p['type'],
                'roi_ratio'=>$p['roi'],'cost_isk'=>$p['cost'],'revenue_isk'=>$p['revenue'],'lp_required'=>$p['lp'],
                'sales_tax_isk'=>$p['salesTax'],'sales_tax_rate'=>self::BASE_SALES_TAX,'assumed_accounting_level'=>self::ASSUMED_ACCOUNTING_LEVEL,'broker_fee_isk'=>$p['brokerFee']+($p['buy_order_broker_fee']??0),'hauling_risk_estimated'=>false,
                'reason'=>$p['note'].' Total source ISK: '.$this->money($p['cost']).'; LP: '.number_format($p['lp']).'; target gross sale: '.$this->money($p['revenue']).'. Includes '.number_format(self::BASE_SALES_TAX*100,1).'% base sales tax (assumed Accounting 0) and '.(!empty($p['buy_order'])?'3% assumed NPC broker fee on the proposed purchase order; that order is NOT filled':($p['listing']?'3% assumed NPC broker fee for listing; sale is NOT confirmed':'no new broker fee for fulfilling an existing buy order')).'. LP valued at an assumed 1,000 ISK each. Hauling costs, route safety, cargo loss and future market movement are not modeled. '.(!empty($p['buy_order'])?'Potential profit only: the proposed buy order has NOT filled. Existing source asks do not establish that a seller will accept this bid.':($p['listing']?'Unrealized profit only: competing sell prices and current local bids do not guarantee a buyer.':'Pre-haul profit, not risk-adjusted profit.')).' Verify current orders and route before committing ISK.'];
        }
        while(count($orders)<3){
            $missing=array_values(array_keys(array_filter(['FULCRUM'=>(bool)(~$bestMask&1),'VENAL'=>(bool)(~$bestMask&2),'GURISTAS LP'=>(bool)(~$bestMask&4),'NEW MARKET ORDER'=>(bool)(~$bestMask&8),'IMMEDIATE MARKET TRADE'=>(bool)(~$bestMask&16)])));
            $orders[]=$this->hold('REQUIREMENT NOT VERIFIED',
                'Insufficient market evidence for: '.implode(', ',$missing).'. We will not invent an order or promise a fill.');
        }
        return ['orders'=>$orders,'as_of'=>$coverage?gmdate('c',$oldest):null,'stale'=>false,
            'coverage'=>['selection_mask'=>$bestMask,'selection_combined_score'=>round($bestEconomic,2),'selection_distinct_strategies'=>$bestVariety,'selection_audit'=>$selectionAudit,'selection_requirements_met'=>($bestMask&31)===31,'missing_requirements'=>array_values(array_keys(array_filter(['FULCRUM'=>(bool)(~$bestMask&1),'VENAL'=>(bool)(~$bestMask&2),'GURISTAS_LP'=>(bool)(~$bestMask&4),'NEW_ORDER'=>(bool)(~$bestMask&8),'IMMEDIATE_TRADE'=>(bool)(~$bestMask&16)]))),'complete_regions'=>array_keys($coverage),'region_count'=>count($coverage),'fresh_regions'=>count(array_filter($coverage,static fn($x)=>$x['age_seconds']>=0&&$x['age_seconds']<=10800)),'candidate_items'=>$demandCount,'items'=>count($allBuy),'history_supported_listings'=>count($validatedListings),'qualified_buy_order_proposals'=>count($buyOrderPool),'listing_candidates'=>count($listingPool),'listing_rejections'=>$rejects,'excluded_outside_pirate_economy'=>$this->excludedOutsidePirateEconomy,'lp_offers'=>$validOffers?count($offers['offers']):0],
            'disclaimer'=>'Three distinct trade candidates are selected as a group. Full coverage of Fulcrum, Venal, Guristas LP, one newly created buy/sell order, and one market buy-from-ask/sell-to-bid trade is an eligibility condition, not an inflated score; when unsupported, maximum verified coverage is selected and gaps reported. Economic ranking uses pre-haul profit, ROI, order depth and bounded supporting evidence; Fulcrum/Venal have NO category bonus in the score; a maximum 1.5% near-tie preference favors distinct strategies. Gaps are explicitly reported. Market trades must start or end at a verified station in Venal or The Fulcrum. Commando Guri LP redemptions and their associated blueprint production are exempt because they directly use pirate LP, even where the redemption station is unspecified. Sell listings require corroborating regional completed-trade history and station order book evidence, including selected outside trade hubs; station sales velocity and actual fill time remain unverified. At least one of the three selected trades should create a buy or sell order when credible evidence exists; neither kind is guaranteed to fill. Estimates assume Accounting 0 (7.5% sales tax); instant fulfillment of existing buy orders incurs no new broker fee. Conditional listings assume 3% NPC broker fee and are NOT guaranteed sales. Ranking blends pre-haul ISK profit, ROI, and available order quantities; explores several acquisition stations and firm bid prices, but cannot guarantee global highest profit across unscanned/stale regions. Route security remains unmeasured. No hauling expense, route loss probability or risk buffer is deducted. Pre-haul estimates are not risk-adjusted. LP shadow value assumes 1,000 ISK/LP. Scheduled public NPC-market snapshots only; regions scanned over time; snapshots older than 3h excluded. LP BPC manufacturing is conditional; confirm runs and facilities. No guaranteed fill.' ];
    }
}
