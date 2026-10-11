<?php
declare(strict_types=1);
require_once __DIR__.'/MarketIntelligence.php';

/** Offline order selection. Never makes ESI requests from the public API. */
final class GuristasTradeOrders {
    private GuristasMarketIntelligence $intel;
    private array $ref;
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
    /** Best executable acquisition; preserve depth and actual station rather than mixing stations. */
    private function acquire(array $stationOrders,int $qty):?array{
        $best=null;
        foreach($stationOrders as $station=>$orders){
            if(!$this->npc((int)$station))continue;
            usort($orders,static fn($a,$b)=>(float)$a['p']<=>(float)$b['p']);
            $need=$qty;$value=0.0;
            foreach($orders as $row){$take=min($need,(int)$row['v']);$value+=$take*(float)$row['p'];$need-=$take;if(!$need)break;}
            if($need)continue;
            if(!$best||$value<$best['cost'])$best=['cost'=>$value,'station'=>(int)$station];
        }
        return $best;
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
        return array_slice($out,0,3);
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
    private function candidate(int $type,int $qty,float $cost,float $lp,float $revenue,int $srcStation,int $destStation,string $kind,string $goal,string $note,bool $conditional=false,bool $listing=false,int $depth=0):?array{
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
        $score=$net*$capitalEfficiency*$depthFactor*(($goal==='FULCRUM')?1.20:($goal==='VENAL'?1.12:1.0));
        if($conditional)$score*=.65;
        if($listing)$score*=.42; // Unsold inventory cannot compete as confirmed revenue.
        return compact('type','qty','cost','lp','revenue','srcStation','destStation','kind','goal','note','conditional','net','score','salesTax','brokerFee','listing','depth','roi');
    }
    private function hold(string $label,string $explanation):array{
        return ['status'=>'standby','category'=>$label,'title'=>'NO VERIFIED ORDER','item'=>'AWAIT FRESH INTELLIGENCE','route'=>$explanation,'margin'=>'NET // NOT VERIFIED','quantity'=>'QTY // NONE','reason'=>'No profit claim is made. Check EVE market orders before traveling.'];
    }
    public function collect():array{
        $allBuy=[];$allSell=[];$destBuy=['FULCRUM'=>[],'VENAL'=>[]];$coverage=[];$oldest=time();
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
                $allSell[$t][$station]=array_merge($allSell[$t][$station]??[],$list);
            }
            foreach($buys as $t=>$stations)foreach($stations as $station=>$list)if($this->npc((int)$station)){
                $allBuy[$t][$station]=array_merge($allBuy[$t][$station]??[],$list);
                if($region===10001000 && (int)$station===(int)$this->ref['hubs']['fulcrum']['station_id'])$destBuy['FULCRUM'][$t][$station]=$list;
                if($region===10000015)$destBuy['VENAL'][$t][$station]=$list;
            }
        }
        $offers=$this->intel->read('lp-offers');
        $validOffers=$offers&&($this->age($offers)<86400)&&is_array($offers['offers']??null);
        $byReward=[];
        if($validOffers)foreach($offers['offers'] as $offer){
            if(!is_array($offer)||(int)($offer['ak_cost']??0)>0||(int)($offer['lp_cost']??0)<1||(int)($offer['quantity']??0)<1)continue;
            $byReward[(int)$offer['type_id']][]=$offer;
        }
        $pool=[];$demandCount=count(array_unique(array_merge(array_keys($destBuy['FULCRUM']),array_keys($destBuy['VENAL']))));
        $destinations=['FULCRUM'=>$destBuy['FULCRUM'],'VENAL'=>$destBuy['VENAL'],'EXPORT'=>$allBuy];
        foreach($destinations as $goal=>$types){
            foreach($types as $type=>$stations){
                if($goal==='EXPORT' && !isset($byReward[$type]))continue;
                $type=(int)$type;
                foreach([1,2,5,10,20,100,1000,10000] as $qty){
                    $bids=$this->bids($stations,$qty);
                    if(!$bids)continue;
                    $acq=$this->acquire($allSell[$type]??[],$qty);
                    if($acq && $goal!=='EXPORT')foreach($bids as $b){
                        if($acq['station']===$b['station'])continue;
                        $c=$this->candidate($type,$qty,$acq['cost'],0,$b['revenue'],$acq['station'],$b['station'],'MARKET',$goal,'Buy at source sell order; immediately fulfill the destination station buy order.',false,false,$qty);
                        if($c)$pool[]=$c;
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
        // Do not publish speculative sell listings from asking prices and current bids.
        // These are NOT completed trades; neither order-book depth nor a narrow
        // bid/ask spread demonstrates sell-through at the proposed listing price.
        // Listing candidates must remain disabled until we collect and validate
        // historical executions (volume, time horizon and station relevance),
        // competition, order age and feasible purchase quantity. Never infer
        // realized profit from a competing ask alone.
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
                $acq=$this->acquire($allSell[$type],$qty);
                if(!$acq)continue;
                foreach($this->bids($allBuy[$type],$qty) as $bid){
                    if($acq['station']===$bid['station'])continue;
                    $c=$this->candidate($type,$qty,$acq['cost'],0,$bid['revenue'],$acq['station'],$bid['station'],'SPECIALTY MARKET','EXPORT','Rare Guristas loot: ordinary buy-low/sell-high calculation, not an LP-store reward.',false,false,$qty);
                    if($c)$pool[]=$c;
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
        usort($pool,static fn($a,$b)=>$b['score']<=>$a['score']);
        $picked=[];$used=[];
        // Prefer executable existing bids; conditional manufacturing is a
        // fallback, not a substitute for observed demand at a firm bid.
        foreach(['FULCRUM','VENAL','ANY'] as $goal){
            foreach([false,true] as $allowConditional){
                foreach($pool as $p){
                    if($goal!=='ANY' && $p['goal']!==$goal)continue;
                    if(!empty($p['listing']))continue;
                    if(!$allowConditional && !empty($p['conditional']))continue;
                    $key=(string)$p['type'];
                    if(isset($used[$key]))continue;
                    $used[$key]=true;$picked[]=$p;
                    continue 3;
                }
            }
        }
        $orders=[];
        foreach($picked as $p){
            $lp=$p['kind']!=='MARKET';$conditional=$p['conditional'];
            $orders[]=['status'=>$conditional?'conditional':'actionable','category'=>$p['goal'].' // '.$p['kind'],
                'title'=>$p['listing']?'BUY // LIST // WAIT':($conditional?'BUILD // VERIFY // SELL':($lp?'REDEEM // DELIVER // SELL':'BUY // DELIVER // SELL')),
                'item'=>$this->label($p['type']),
                'route'=>($lp?'USE LP + INPUTS':'BUY AT NPC STATION #'.$p['srcStation']).' → SELL '.$p['qty'].' TO NPC STATION #'.$p['destStation'],
                'margin'=>($p['listing']?'UNREALIZED IF SOLD':($conditional?'POTENTIAL PRE-HAUL':'PRE-HAUL')).' // +'.$this->money($p['net']),
                'quantity'=>'QTY // '.$p['qty'].' · ROI '.number_format($p['roi']*100,1).'%', 'listing'=>$p['listing'], 'market_depth_units'=>$p['depth'],
                'source_id'=>$p['srcStation'],'destination_id'=>$p['destStation'],'type_id'=>$p['type'],
                'roi_ratio'=>$p['roi'],'cost_isk'=>$p['cost'],'revenue_isk'=>$p['revenue'],'lp_required'=>$p['lp'],
                'sales_tax_isk'=>$p['salesTax'],'sales_tax_rate'=>self::BASE_SALES_TAX,'assumed_accounting_level'=>self::ASSUMED_ACCOUNTING_LEVEL,'broker_fee_isk'=>$p['brokerFee'],'hauling_risk_estimated'=>false,
                'reason'=>$p['note'].' Total source ISK: '.$this->money($p['cost']).'; LP: '.number_format($p['lp']).'; target gross sale: '.$this->money($p['revenue']).'. Includes '.number_format(self::BASE_SALES_TAX*100,1).'% base sales tax (assumed Accounting 0) and '.($p['listing']?'3% assumed NPC broker fee for listing; sale is NOT confirmed':'no new broker fee for fulfilling an existing buy order').'. LP valued at an assumed 1,000 ISK each. Hauling costs, route safety, cargo loss and future market movement are not modeled. '.($p['listing']?'Unrealized profit only: competing sell prices and current local bids do not guarantee a buyer.':'Pre-haul profit, not risk-adjusted profit.').' Verify current orders and route before committing ISK.'];
        }
        while(count($orders)<3)$orders[]=$this->hold('ECONOMIC INTELLIGENCE','No independent qualifying order from sufficiently fresh covered public markets.');
        return ['orders'=>$orders,'as_of'=>$coverage?gmdate('c',$oldest):null,'stale'=>false,
            'coverage'=>['complete_regions'=>array_keys($coverage),'region_count'=>count($coverage),'fresh_regions'=>count(array_filter($coverage,static fn($x)=>$x['age_seconds']>=0&&$x['age_seconds']<=10800)),'candidate_items'=>$demandCount,'items'=>count($allBuy),'lp_offers'=>$validOffers?count($offers['offers']):0],
            'disclaimer'=>'Sell-order listing suggestions are withheld until verified completed-trade volume and defensible sell-through evidence are available; competing asks are not transactions. Estimates assume Accounting 0 (7.5% sales tax); instant fulfillment of existing buy orders incurs no new broker fee. Conditional sell listings include an assumed 3% NPC broker fee, use competing ASK prices only and are NOT guaranteed sales. Scoring balances ISK profit, ROI and available buy volume, not actual route security. No hauling expense, route loss probability or risk buffer is deducted. Pre-haul estimates are not risk-adjusted. LP shadow value assumes 1,000 ISK/LP. Scheduled public NPC-market snapshots only; regions scanned over time; snapshots older than 3h excluded. LP BPC manufacturing is conditional; confirm runs and facilities. No guaranteed fill.' ];
    }
}
