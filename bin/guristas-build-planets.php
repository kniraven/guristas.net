<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/MarketIntelligence.php';
$intel=new GuristasMarketIntelligence();$dir=$intel->dir();
if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cache unavailable');
$lock=fopen($dir.'/build-planets.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Collector busy\n");exit(3);}
try {
    $state=$intel->read('build-venal-planets');
    $age=time()-(strtotime((string)($state['collected_at']??''))?:0);
    if(!empty($state['complete'])&&$age<2592000){echo json_encode(['ok'=>true,'status'=>'current','planets'=>count($state['planets']??[])],JSON_THROW_ON_ERROR)."\n";}
    else {
        $cfg=require dirname(__DIR__).'/config/esi.php';
        $esi=new GuristasEsiClient($cfg,new GuristasEsiCache(dirname(__DIR__).'/storage/cache/public-industry'));
        if(!$state || !empty($state['complete']))$state=['region_id'=>10000015,'constellations'=>[],'systems'=>[],'pending_planets'=>[],'planets'=>[],'complete'=>false];
        $calls=0;$limit=15;
        $get=function(string $url)use($esi,&$calls):array{
            $res=$esi->getJson($url,[],86400);$calls++;
            if(!is_array($res['data']??null)||!empty($res['meta']['stale']))throw new RuntimeException('ESI universe data unavailable');
            return $res['data'];
        };
        if(!isset($state['region_loaded'])){$r=$get('/universe/regions/10000015');$state['constellations']=array_map('intval',$r['constellations']??[]);$state['region_loaded']=true;}
        while($calls<$limit&&$state['constellations']){$id=(int)$state['constellations'][0];$r=$get('/universe/constellations/'.$id);foreach($r['systems']??[] as $sid)$state['systems'][]=(int)$sid;array_shift($state['constellations']);}
        while($calls<$limit&&!$state['constellations']&&$state['systems']){$id=(int)$state['systems'][0];$r=$get('/universe/systems/'.$id);foreach($r['planets']??[] as $p){$pid=(int)($p['planet_id']??0);if($pid>0)$state['pending_planets'][]=['id'=>$pid,'system_id'=>$id];}array_shift($state['systems']);}
        while($calls<$limit&&!$state['constellations']&&!$state['systems']&&$state['pending_planets']){$item=$state['pending_planets'][0];$r=$get('/universe/planets/'.$item['id']);$type=(int)($r['type_id']??0);if($type>0)$state['planets'][]=['id'=>$item['id'],'system_id'=>$item['system_id'],'type_id'=>$type];array_shift($state['pending_planets']);}
        if(!$state['constellations']&&!$state['systems']&&!$state['pending_planets']){$state['complete']=true;$state['collected_at']=gmdate('c');}
        $state['updated_at']=gmdate('c');$state['source']='CCP ESI public planet type inventory, NOT live extraction yields';
        $intel->publish('build-venal-planets',$state);
        echo json_encode(['ok'=>true,'complete'=>$state['complete'],'calls'=>$calls,'planets'=>count($state['planets']),'pending_systems'=>count($state['systems']),'pending_planets'=>count($state['pending_planets'])],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    }
} catch(Throwable $error){fwrite(STDERR,"Planet collector paused: ".$error->getMessage()."\n");exit(1);}
finally{flock($lock,LOCK_UN);fclose($lock);}
