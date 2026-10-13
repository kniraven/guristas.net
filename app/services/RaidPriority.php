<?php
declare(strict_types=1);

/** Deterministic insurgency deployment priorities; no ESI calls. */
final class GuristasRaidPriority {
    private static function progress(array $s): float {
        $stage=$s['corruption_stage'];
        $percent=$s['corruption_percent']??null;
        return $stage*100.0+(is_numeric($percent)&&is_finite((float)$percent)?max(0.0,min(100.0,(float)$percent)):0.0);
    }
    public static function assessment(array $s): array {
        $c=(int)$s['corruption_stage'];
        $supp=$s['suppression_stage']??null;
        $validSupp=is_int($supp)&&$supp>=0&&$supp<=5;
        $ahead=$validSupp&&$supp>$c;
        $contested=$validSupp&&$supp<5&&$ahead;
        $close=$contested&&$c>=2&&$supp-$c<=1;
        $score=self::progress($s);
        if($contested)$score+=40.0;
        if($contested&&$supp===4)$score+=20.0;
        if($close)$score+=15.0;
        if($validSupp&&$supp===4&&$c===4)$score+=25.0;
        // Do not gamble an almost completed Stage 5 objective to rescue a distant system.
        if($c===4&&is_numeric($s['corruption_percent']??null)&&(float)$s['corruption_percent']>=80)$score+=100.0;
        return ['score'=>$score,'contested'=>$contested,'suppression_known'=>$validSupp];
    }
    public static function recommend(array $data): array {
        $campaigns=$data['campaigns']??[];
        if(!is_array($campaigns)||count($campaigns)!==1||($campaigns[0]['state']??'')!=='ACTIVE'||!empty($campaigns[0]['ended_at']))
            return ['title'=>'HOLD POSITION','detail'=>'No single confirmed active insurgency.'];
        $systems=is_array($data['systems']??null)?$data['systems']:[];
        $eligible=array_values(array_filter($systems,static fn($s)=>is_array($s)&&isset($s['id'],$s['corruption_stage'])&&is_int($s['corruption_stage'])&&$s['corruption_stage']>=0&&$s['corruption_stage']<5
            && (!isset($s['suppression_stage']) || !is_int($s['suppression_stage']) || $s['suppression_stage']<5)));
        if(!$eligible)return ['title'=>'HOLD POSITION','detail'=>'No verified Corruption target remains eligible; Suppression 5 blocks further advancement to Corruption 5.'];
        $adj=[];$dist=[];$queue=[];
        foreach($systems as $s){$id=(int)($s['id']??0);if($id<1)continue;$adj[$id]=[];
            if(($s['highsec_adjacent']??false)===true){$dist[$id]=0;$queue[]=$id;}
        }
        foreach($data['edges']??[] as $edge){$a=(int)($edge['a']??0);$b=(int)($edge['b']??0);
            if(isset($adj[$a],$adj[$b])){$adj[$a][]=$b;$adj[$b][]=$a;}
        }
        for($i=0;$i<count($queue);$i++){
            $id=$queue[$i];foreach($adj[$id]??[] as $next)if(!isset($dist[$next])){$dist[$next]=$dist[$id]+1;$queue[]=$next;}
        }
        usort($eligible,static function($a,$b)use($dist){
            $x=self::assessment($a)['score'];$y=self::assessment($b)['score'];
            return $y<=>$x ?: ($dist[(int)$a['id']]??PHP_INT_MAX)<=>($dist[(int)$b['id']]??PHP_INT_MAX)
                ?: strcmp((string)($a['name']??''),(string)($b['name']??''));
        });
        $w=$eligible[0];$id=(int)$w['id'];$distance=$dist[$id]??null;
        $near=$distance===0?'Direct high-sec gate':($distance!==null?$distance.' insurgency jumps to a high-sec gate':'High-sec proximity not verified');
        $pct=is_numeric($w['corruption_percent']??null)?' · '.number_format((float)$w['corruption_percent'],1).'%':'';
        $supp=is_int($w['suppression_stage']??null)?' Suppression Stage '.$w['suppression_stage'].'/5.':' Suppression unverified.';
        $a=self::assessment($w);
        $turn=$a['contested'];
        $reason=$turn?'TURN THE TIDE: Empire Suppression is ahead. Contest complexes to deny further enemy captures while advancing Corruption.':'SECURE CORRUPTION: Advance the strongest viable unfinished objective.';
        if($w['corruption_stage']===4&&is_numeric($w['corruption_percent']??null)&&(float)$w['corruption_percent']>=80) $reason='FINISH THE JOB: Corruption 5 is close; secure the objective before diverting pilots.';
        return ['title'=>'DEPLOY TO '.($w['name']??'SYSTEM '.$id).' // '.($turn?'TURN THE TIDE':'RAISE CORRUPTION'),
            'detail'=>'Corruption Stage '.$w['corruption_stage'].'/5'.$pct.'.'.$supp.' '.$reason.' '.$near.'.',
            'strategy'=>$turn?'turn_the_tide':'secure_corruption','system_id'=>$id,
            'evidence'=>['priority_score'=>$a['score'],'candidate_systems'=>count($eligible),
                'highsec_distance_jumps'=>$distance,'corruption_stage'=>(int)$w['corruption_stage'],
                'suppression_stage'=>$w['suppression_stage']??null,
                'suppression_known'=>$a['suppression_known']]];
    }
}
