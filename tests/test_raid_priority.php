<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/services/RaidPriority.php';
function s(int $id,int $c,int $sup,float $percent=0):array{return ['id'=>$id,'name'=>'System '.$id,'corruption_stage'=>$c,'corruption_percent'=>$percent,'suppression_stage'=>$sup,'highsec_adjacent'=>true];}
function pick(array $systems):array{return GuristasRaidPriority::recommend(['campaigns'=>[['state'=>'ACTIVE','ended_at'=>null]],'systems'=>$systems,'edges'=>[]]);}
function expectId(array $systems,int $id):void{$r=pick($systems);if(($r['system_id']??0)!==$id)throw new RuntimeException("Expected $id got ".json_encode($r));}
expectId([s(1,4,1,20),s(2,3,4,90)],2); // rescue a competitive endangered system
expectId([s(1,4,1,95),s(2,3,4,90)],1); // finish fast victory
expectId([s(1,4,5,99),s(2,2,3,20)],2); // blocked stage 5 not selectable
expectId([s(1,3,2,30),s(2,1,4,70)],1); // don't chase hopeless system
expectId([s(1,4,4,20),s(2,4,1,30)],1); // tied at 4/4 is a race
expectId([s(1,2,3,35),s(2,2,1,50)],1); // benefit from turnaround bonus
$r=pick([s(1,4,5,20)]);if(($r['title']??'')!=='HOLD POSITION')throw new RuntimeException('No-blocked-target test failed');
$r=GuristasRaidPriority::recommend(['campaigns'=>[['state'=>'FORECAST']],'systems'=>[s(1,3,4)],'edges'=>[]]);if(($r['title']??'')!=='HOLD POSITION')throw new RuntimeException('Forecast test failed');
echo "RAID priority: 8 scenarios passed.\n";
