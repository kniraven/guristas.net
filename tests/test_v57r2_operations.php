<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/services/IntelligenceOperations.php';
function verify58(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
$service=file_get_contents(dirname(__DIR__).'/app/services/IntelligenceOperations.php');
verify58(str_contains($service,"'guristas-venal-collector'=>'Venal collection'"),'correct Venal systemd timer unit');
$dir=sys_get_temp_dir().'/guri-freshness-'.bin2hex(random_bytes(7));
if(!mkdir($dir))throw new RuntimeException('Could not create isolated test directory');
try{
 $now=1792022400;
 verify58(GuristasOperations::datasetFreshness('forge',$now,$dir)['status']==='missing','missing snapshot is not fresh');
 file_put_contents($dir.'/region-10000002.json',json_encode(['fetched_at'=>gmdate('c',$now-60),'complete'=>true,'orders'=>[]]));
 verify58(GuristasOperations::datasetFreshness('forge',$now,$dir)['status']==='fresh','complete recent region snapshot is fresh');
 file_put_contents($dir.'/region-10000002.json',json_encode(['fetched_at'=>gmdate('c',$now-20000),'complete'=>true,'orders'=>[]]));
 verify58(GuristasOperations::datasetFreshness('forge',$now,$dir)['status']==='stale','expired region snapshot is stale');
 file_put_contents($dir.'/region-10000002.json',json_encode(['fetched_at'=>gmdate('c',$now-60),'complete'=>false]));
 verify58(GuristasOperations::datasetFreshness('forge',$now,$dir)['status']==='unverified','incomplete region snapshot is unverified');
 verify58(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='unverified','SDE status is not fabricated');
 verify58(GuristasOperations::datasetFreshness('domain',$now,$dir)['status']==='unverified','Domain snapshot is not inferred from another market');
 echo "PASS v57r2 read-only freshness and timer regression tests\n";
} finally {foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
