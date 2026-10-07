<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/services/CommunityNetwork.php';
$dir=sys_get_temp_dir().'/guri-lock-'.bin2hex(random_bytes(8));$store=new CommunityNetwork($dir);
try {
    $data=$store->apply(['action'=>'save','revision'=>0,'type'=>'supply','status'=>'published','title'=>'Limited order','body'=>'Confirm the contract first.','location'=>'Station','contact'=>'Officer','item'=>'Worm','quantity'=>5,'reward'=>'0','terms'=>'Voluntary donation'],1,'Officer',true);
    $id=array_key_first($data['entries']);$children=[];
    foreach([2,3] as $actor){
        $code='require '.var_export(dirname(__DIR__).'/app/services/CommunityNetwork.php',true).'; try { (new CommunityNetwork('.var_export($dir,true).'))->apply('.var_export(['action'=>'reserve','revision'=>1,'id'=>$id,'quantity'=>5],true).','.$actor.',"Pilot",false);echo "reserved";} catch(Throwable $e){echo "rejected";}';
        $pipes=[];$process=proc_open([PHP_BINARY,'-n','-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot run concurrency check');$children[]=[$process,$pipes];
    }
    $results=[];foreach($children as [$process,$pipes]){$results[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0 || $error!=='')throw new RuntimeException('Child check failed');}
    sort($results);if($results!==['rejected','reserved'] || CommunityNetwork::progress($store->read(),$id)['held']!==5)throw new RuntimeException('Concurrent reservations overbooked the order');
    echo "Concurrent reservations cannot overbook a supply target.\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);if(is_dir($dir))rmdir($dir);}
