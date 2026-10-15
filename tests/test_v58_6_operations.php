<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/services/IntelligenceOperations.php';
function check586(bool $ok,string $label): void {if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";}
$now=strtotime('2026-10-15T23:45:00+00:00');
$dir=sys_get_temp_dir().'/ops586-'.bin2hex(random_bytes(6));mkdir($dir);
try {
    $sample=['complete'=>true,'fetched_at'=>gmdate('c',$now-30),'region_id'=>10000043,'orders'=>[]];
    file_put_contents($dir.'/region-10000043.json',json_encode($sample));
    $r=GuristasOperations::datasetFreshness('domain',$now,$dir);
    check586($r['status']==='fresh','Domain complete region snapshot checked independently');
    $sample['complete']=false;file_put_contents($dir.'/region-10000043.json',json_encode($sample));
    check586(GuristasOperations::datasetFreshness('domain',$now,$dir)['status']==='unverified','incomplete Domain snapshot not called fresh');
    $sample['complete']=true;$sample['fetched_at']=gmdate('c',$now-20000);file_put_contents($dir.'/region-10000043.json',json_encode($sample));
    check586(GuristasOperations::datasetFreshness('domain',$now,$dir)['status']==='stale','stale Domain snapshot identified');
    $planet=['region_id'=>10000015,'planets'=>[],'complete'=>false,'updated_at'=>gmdate('c',$now-35)];
    file_put_contents($dir.'/build-venal-planets.json',json_encode($planet));
    check586(GuristasOperations::datasetFreshness('planets',$now,$dir)['status']==='collecting','updated partial planetary catalog not called complete');
    $planet['complete']=true;$planet['collected_at']=gmdate('c',$now-86400);$planet['updated_at']=gmdate('c',$now-86400);
    file_put_contents($dir.'/build-venal-planets.json',json_encode($planet));
    check586(GuristasOperations::datasetFreshness('planets',$now,$dir)['status']==='fresh','completed planet reference remains fresh despite unchanged hourly runs');
    $planet['updated_at']=gmdate('c',$now-2800000);file_put_contents($dir.'/build-venal-planets.json',json_encode($planet));
    check586(GuristasOperations::datasetFreshness('planets',$now,$dir)['status']==='stale','outdated planet reference detected');
    $state=['queue'=>[['job'=>'forge','trigger'=>'schedule','requested'=>$now-400],['job'=>'trade','trigger'=>'retry','requested'=>$now-200,'not_before'=>$now+120],['job'=>'offers','trigger'=>'schedule','requested'=>$now-100]]];
    $q=GuristasOperations::queueTelemetry($state,$now);
    check586(count($q)===3&&$q[0]['job']==='offers'&&$q[0]['position']===1,'market pending entries provide approximate priority order');
    check586($q[0]['age_seconds']===100&&$q[1]['age_seconds']===200,'queue waiting age retained');
    $settings=['enabled'=>true,'hours'=>[9],'minutes'=>[10]];
    $scheduled=strtotime('2026-10-15T14:10:00+00:00');
    check586(GuristasOperations::scheduleEvidence($settings,[['trigger'=>'schedule','requested'=>$scheduled,'status'=>'success']])['matches_current_schedule']===true,'saved timetable matches a scheduled request in Chicago timezone');
    $settings['minutes']=[11];
    check586(GuristasOperations::scheduleEvidence($settings,[['trigger'=>'schedule','requested'=>$scheduled,'status'=>'success']])['matches_current_schedule']===false,'schedule evidence reveals historical run outside newly edited timetable');
    echo "PASS v58.6 independent freshness, queue telemetry, and schedule evidence (isolated).\n";
}finally {foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
