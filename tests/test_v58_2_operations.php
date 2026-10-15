<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/IntelligenceOperations.php';
function check582(bool $passed,string $message):void{if(!$passed)throw new RuntimeException($message);echo "PASS: $message\n";}
$original=GuristasOperations::settings('raid',['settings'=>[]]);
$reordered=['minutes'=>$original['minutes'],'hours'=>$original['hours'],'timeout'=>$original['timeout'],'notify'=>$original['notify'],'enabled'=>$original['enabled']];
check582(GuristasOperations::settingsEqual($original,$reordered),'key order does not create a settings change');
$unsorted=$reordered; $unsorted['minutes']=array_reverse($unsorted['minutes']);
check582(GuristasOperations::settingsEqual($original,$unsorted),'order of minute selections does not create a settings change');
foreach(['enabled'=>false,'timeout'=>121,'notify'=>'all'] as $key=>$value){$changed=$reordered;$changed[$key]=$value;check582(!GuristasOperations::settingsEqual($original,$changed),$key.' change detected');}
$changed=$reordered;$changed['minutes']=[0,15,30,45];check582(!GuristasOperations::settingsEqual($original,$changed),'real minute change detected');
$changed=$reordered;$changed['hours']=[8,9];check582(!GuristasOperations::settingsEqual($original,$changed),'real hour change detected');
$invalid=$reordered;$invalid['minutes']=[0,0];check582(!GuristasOperations::settingsEqual($original,$invalid),'invalid duplicate minutes cannot be treated as unchanged');
$state=['settings'=>[],'queue'=>[],'runs'=>[]];GuristasOperations::auditEvent($state,'manual_queued','raid',2121661474);
check582($state['audit'][0]['actor']===2121661474,'manual run attribution remains intact');
echo "PASS v58.2 audit accuracy checks\n";
