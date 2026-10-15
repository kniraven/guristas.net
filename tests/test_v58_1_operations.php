<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/IntelligenceOperations.php';
function check58(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$s=['settings'=>[],'queue'=>[],'runs'=>[]];
GuristasOperations::auditEvent($s,'manual_queued','raid',2121661474);
check58(count($s['audit'])===1&&$s['audit'][0]['actor']===2121661474,'manual request is attributed to pilot');
GuristasOperations::auditEvent($s,'settings_changed','raid',2121661474,['before'=>['enabled'=>true],'after'=>['enabled'=>false]]);
check58($s['audit'][0]['action']==='settings_changed'&&$s['audit'][0]['details']['after']['enabled']===false,'settings changes preserve before and after');
for($i=0;$i<220;$i++)GuristasOperations::auditEvent($s,'manual_queued','raid',2121661474);
check58(count($s['audit'])===200,'audit history has a bounded retention');
foreach([['bad','raid',1],['manual_queued','absent',1],['manual_queued','raid',0]] as $x){$failed=false;try{GuristasOperations::auditEvent($s,$x[0],$x[1],$x[2]);}catch(InvalidArgumentException $e){$failed=true;}check58($failed,'invalid audit action, job or actor rejected');}
check58(method_exists(GuristasOperations::class,'saveJobSettings'),'atomic settings save entry point exists');
echo "PASS v58.1 audit invariants\n";
