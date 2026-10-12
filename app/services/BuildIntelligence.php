<?php
declare(strict_types=1);
require_once __DIR__.'/MarketIntelligence.php';
/** Build candidates from already-collected ESI order snapshots; no network calls. */
final class GuristasBuildIntelligence {
    private GuristasMarketIntelligence $intel;
    private array $reference;
    public function __construct(?GuristasMarketIntelligence $intel=null, ?array $reference=null) {
        $this->intel=$intel ?? new GuristasMarketIntelligence();
        $this->reference=$reference ?? guri_industry_reference();
    }
    private static function fresh(?array $snapshot):bool {
        if(!$snapshot || empty($snapshot['complete']) || !is_array($snapshot['orders']??null))return false;
        $when=strtotime((string)($snapshot['fetched_at']??''));
        return $when!==false && $when<=time()+60 && time()-$when<=10800;
    }
    private static function priceOfAsk(array $snapshot,int $type,int $station,int $quantity):?array {
        $rows=[];
        foreach($snapshot['orders'] as $row) {
            if((int)($row['t']??0)!==$type || (int)($row['l']??0)!==$station || !empty($row['b']))continue;
            if((int)($row['v']??0)<1 || (float)($row['p']??0)<=0 || (int)($row['i']??0)<=0)continue;
            $rows[]=$row;
        }
        usort($rows,static fn($a,$b)=>(float)$a['p']<=>(float)$b['p']);
        $remaining=$quantity;$cost=0.0;$used=[];
        foreach($rows as $row) {
            $take=min($remaining,(int)$row['v']);
            if($take===0)break;
            $cost+=$take*(float)$row['p'];$remaining-=$take;
            $used[]=['id'=>(int)$row['i'],'quantity'=>$take,'price'=>(float)$row['p']];
        }
        return $remaining===0?['cost'=>$cost,'orders'=>$used]:null;
    }
    private static function bidAt(array $snapshot,int $type):array {
        $rows=[];
        foreach($snapshot['orders'] as $row) {
            if((int)($row['t']??0)!==$type || empty($row['b']) || (int)($row['v']??0)<1 || (float)($row['p']??0)<=0 || (int)($row['i']??0)<=0)continue;
            // Destination market orders must be strictly station-range; source stations are provided in the record.
            if((string)($row['r']??'')!=='station' || (int)($row['m']??1)>1)continue;
            $rows[]=$row;
        }
        usort($rows,static fn($a,$b)=>(float)$b['p']<=>(float)$a['p']);
        return $rows;
    }
    public function collect():array {
        $market=[];
        foreach([10000002,10000015,10001000] as $region) {
            $snapshot=$this->intel->read('region-'.$region);
            if(!self::fresh($snapshot))return $this->empty('Required Jita, Venal or Fulcrum order snapshot is missing or stale.');
            $market[$region]=$snapshot;
        }
        $offers=$this->intel->read('lp-offers');
        if(!$offers || !is_array($offers['offers']??null) || (time()-(strtotime((string)($offers['fetched_at']??''))?:0))>172800)return $this->empty('Commando Guri blueprint offer evidence is missing or older than 48 hours.');
        $bpOffers=[];
        foreach($offers['offers'] as $offer) {
            if(!is_array($offer) || (int)($offer['lp_cost']??0)<1 || (int)($offer['ak_cost']??0)!==0 || !empty($offer['required_items']))continue;
            $id=(int)($offer['type_id']??0);
            if(!isset($this->reference['blueprints'][(string)$id]))continue;
            $price=(float)($offer['isk_cost']??0)+1000.0*(int)$offer['lp_cost'];
            if(!isset($bpOffers[$id]) || $price<$bpOffers[$id]['cost'])$bpOffers[$id]=['cost'=>$price,'lp'=>(int)$offer['lp_cost'],'isk'=>(float)($offer['isk_cost']??0),'offer_id'=>(int)($offer['offer_id']??0)];
        }
        $candidate=[];$rejected=['missing_material_quotes'=>0,'no_destination_bid'=>0,'no_blueprint_offer'=>0,'not_positive_before_costs'=>0];
        foreach($this->reference['blueprints'] as $bpId=>$bp) {
            $id=(int)$bpId;$offer=$bpOffers[$id]??null;
            if(!$offer){$rejected['no_blueprint_offer']++;continue;}
            $type=(int)($bp['product']['typeID']??0);$yield=(int)($bp['product']['quantity']??0);
            if($type<1||$yield<1 || !is_array($bp['materials']??null))continue;
            $ingredients=[];$materialCost=0.0;$complete=true;
            // Base ME=0, one blueprint run. No unverified facility or skill bonus.
            foreach($bp['materials'] as $material) {
                $quantity=(int)($material['quantity']??0);$inputType=(int)($material['typeID']??0);
                if($quantity<1||$inputType<1){$complete=false;break;}
                $quote=self::priceOfAsk($market[10000002],$inputType,60003760,$quantity);
                if(!$quote){$complete=false;break;}
                $materialCost+=$quote['cost'];
                $ingredients[]=['type_id'=>$inputType,'quantity'=>$quantity,'cost_isk'=>$quote['cost'],'order_ids'=>array_column($quote['orders'],'id')];
            }
            if(!$complete){$rejected['missing_material_quotes']++;continue;}
            $buyers=[];
            foreach([10000015=>'Venal',10001000=>'The Fulcrum'] as $region=>$name) {
                foreach(self::bidAt($market[$region],$type) as $bid) {
                    if((int)$bid['v']<$yield)continue;
                    $buyers[]=['region'=>$name,'station_id'=>(int)$bid['l'],'order_id'=>(int)$bid['i'],'quantity'=>(int)$bid['v'],'price'=>(float)$bid['p'],'revenue'=>(float)$bid['p']*$yield];
                }
            }
            if(!$buyers){$rejected['no_destination_bid']++;continue;}
            usort($buyers,static fn($a,$b)=>$b['revenue']<=>$a['revenue']);$buyer=$buyers[0];
            $spread=$buyer['revenue']-$materialCost;
            // Conservative: charge the entire LP blueprint offer to one production run,
            // even if the actual BPC may have more runs. Facility and hauling still excluded.
            $room=$buyer['revenue']*0.925-$materialCost-$offer['cost'];
            if($room<=0){$rejected['not_positive_before_costs']++;continue;}
            $candidate[]=['blueprint_id'=>$id,'product_type_id'=>$type,'product'=>$this->reference['types'][(string)$type]['name']??$bp['name'],'quantity'=>$yield,'materials_cost_isk'=>$materialCost,'bid_revenue_isk'=>$buyer['revenue'],'gross_material_spread_isk'=>$spread,'room_before_job_and_haul_isk'=>$room,'destination'=>$buyer,'blueprint_redemption'=>$offer,'inputs'=>$ingredients,'assumptions'=>'One run at ME 0. Material costs use Jita 4-4 asks. Selling uses an existing station-range buy order. The remaining margin charges the ENTIRE LP blueprint offer at 1,000 ISK/LP and models 7.5% sales tax. Production job fees, hauling, facility access, BPC runs and risk are NOT verified. Not guaranteed manufacturing profit.'];
        }
        usort($candidate,static fn($a,$b)=>$b['room_before_job_and_haul_isk']<=>$a['room_before_job_and_haul_isk']);
        $selected=array_slice($candidate,0,3);
        return ['as_of'=>gmdate('c'),'status'=>$selected?'leads':'pending','orders'=>$selected,'coverage'=>['blueprints_checked'=>count($this->reference['blueprints']),'qualified_leads'=>count($candidate),'rejected'=>$rejected],'note'=>'These are research leads, not executable profit predictions. Confirm BPC runs, facility accessibility, installation fees, material availability, job costs, taxes and shipping in EVE before producing.','source'=>'CCP SDE recipes, Commando Guri LP offers and published CCP ESI regional order snapshots'];
    }
    private function empty(string $why):array {return ['as_of'=>gmdate('c'),'status'=>'pending','orders'=>[],'coverage'=>['qualified_leads'=>0],'note'=>$why,'source'=>'CCP SDE and ESI'];}
}
