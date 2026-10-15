<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/IntelligenceOperations.php';
function check58(bool $value,string $message): void { if(!$value)throw new RuntimeException('FAIL: '.$message);echo 'PASS: '.$message."\n"; }
$runs=[
 ['status'=>'success','duration'=>10.0,'ended'=>50],
 ['status'=>'skipped','duration'=>0.1,'ended'=>45],
 ['status'=>'failed','duration'=>900,'ended'=>44],
 ['status'=>'success','duration'=>20.0,'ended'=>40],
 ['status'=>'running','duration'=>2.0],
 ['status'=>'success','duration'=>-2,'ended'=>10],
];
check58(GuristasOperations::successfulDurations($runs)===[10.0,20.0],'skips, failures, incomplete and invalid durations excluded');
check58(GuristasOperations::successfulDurations($runs,1)===[10.0],'sample limit uses newest valid completed run');
$tz=new DateTimeZone('America/Chicago');
$start=(new DateTimeImmutable('2026-10-14 20:29:10',$tz))->getTimestamp();
$s=['enabled'=>true,'minutes'=>[0,30],'hours'=>range(0,23)];
$next=GuristasOperations::nextScheduledAt($s,$start);
check58((new DateTimeImmutable('@'.$next))->setTimezone($tz)->format('Y-m-d H:i')==='2026-10-14 20:30','next eligible scheduled minute is computed in Central time');
$s['enabled']=false;
check58(GuristasOperations::nextScheduledAt($s,$start)===null,'disabled job has no next scheduled run');
check58(GuristasOperations::nextScheduledAt(['enabled'=>true,'minutes'=>[],'hours'=>[9]],$start)===null,'invalid schedule fails closed');
echo 'PASS v58 execution metrics and schedule display assertions'."\n";
