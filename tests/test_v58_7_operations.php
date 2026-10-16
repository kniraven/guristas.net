<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/IntelligenceOperations.php';
function assertSde(bool $value,string $msg):void {if(!$value){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$dir=sys_get_temp_dir().'/guristas-v587-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
$now=1792108800;$build=3607705;
$check=['ok'=>true,'status'=>'unchanged','build'=>$build,'checked_at'=>gmdate('c',$now-300)];
$ref=['sde_build'=>$build,'blueprints'=>array_fill(0,55,1),'pi_schematics'=>array_fill(0,68,1),'reprocessing'=>array_fill(0,8092,1),'types'=>array_fill(0,550,1)];
$write=static function($name,$val)use($dir){file_put_contents($dir.'/'.$name,json_encode($val));};
try {
 assertSde(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='missing','missing check remains missing');
 $write('sde-sync-status.json',$check);
 assertSde(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='missing','missing active reference not called fresh');
 $write('industry-reference-live.json',$ref);file_put_contents($dir.'/manufacturing-full.sqlite','fixture');
 assertSde(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='fresh','unchanged revision and complete active reference are fresh');
 $check['status']='activated';$write('sde-sync-status.json',$check);
 assertSde(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='fresh','activated revision is fresh');
 $check['status']='already_running';$write('sde-sync-status.json',$check);
 assertSde(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='unverified','lock-skipped daily check is not fresh');
 $check['status']='retained_previous_reference';$check['ok']=false;$write('sde-sync-status.json',$check);
 assertSde(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='unverified','failed revision check is not fresh');
 $check['status']='unchanged';$check['ok']=true;$check['checked_at']=gmdate('c',$now-150000);$write('sde-sync-status.json',$check);
 assertSde(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='stale','old revision check is stale');
 $check['checked_at']=gmdate('c',$now-300);$write('sde-sync-status.json',$check);
 $ref['sde_build']=$build-1;$write('industry-reference-live.json',$ref);
 assertSde(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='unverified','mismatched revision is unverified');
 $ref['sde_build']=$build;$ref['blueprints']=[];$write('industry-reference-live.json',$ref);
 assertSde(GuristasOperations::datasetFreshness('sde',$now,$dir)['status']==='unverified','partial active reference is unverified');
 echo "PASS v58.7 read-only SDE freshness classification (synthetic fixtures).\n";
}finally {foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
