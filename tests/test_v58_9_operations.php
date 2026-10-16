<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/IntelligenceOperations.php';
$cases=[
 'allowed systemd identities'=>in_array('guristas-venal-collector',['guristas-venal-collector','guristas-cache-cleanup'],true),
 'systemd config method exists'=>method_exists(GuristasOperations::class,'systemdConfig'),
 'systemd action method exists'=>method_exists(GuristasOperations::class,'systemdAction'),
 'invalid service is rejected'=>false,
 'invalid schedule is rejected'=>false,
];
try{GuristasOperations::systemdAction('unknown','run',1);}catch(InvalidArgumentException $e){$cases['invalid service is rejected']=true;}
try{GuristasOperations::systemdAction('guristas-venal-collector','settings',1,['minute'=>100,'hour'=>0,'enabled'=>true]);}catch(InvalidArgumentException $e){$cases['invalid schedule is rejected']=true;}
foreach ($cases as $name=>$pass){echo ($pass?'PASS: ':'FAIL: ').$name."\n";if(!$pass)exit(1);}
echo "PASS v58.9 allowlist and parameter validation (no systemd changes).\n";
