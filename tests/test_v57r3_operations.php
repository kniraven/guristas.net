<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/services/IntelligenceOperations.php';
function assertR3(bool $condition,string $name): void {if(!$condition)throw new RuntimeException('FAIL: '.$name);echo 'PASS: '.$name."\n";}
$jobs=GuristasOperations::jobs();
$legacy=[];
foreach($jobs as $job){$cmd=$job['cmd'];$legacy[]='* * * * * cd '.GuristasOperations::root().' && '.implode(' ',$cmd);}
$held="# BEGIN GURISTAS.NET RAID VALIDATION V57R3\n";
foreach($legacy as $line)$held.='# V57R3 HELD '.$line."\n";
$held.="# END GURISTAS.NET RAID VALIDATION V57R3\n";
assertR3(GuristasOperations::cronMode($held)['mode']==='raid_validation','exact isolated RAID validation recognized');
assertR3(GuristasOperations::cronMode(str_replace('# V57R3 HELD ','','# V57R3 HELD '.$legacy[0])."\n".$held)['mode']==='conflict','active legacy worker cron during validation is rejected');
assertR3(GuristasOperations::cronMode(str_replace('# V57R3 HELD ','# V57R3 LOST ', $held))['mode']==='conflict','missing held records refuse validation');
assertR3(GuristasOperations::cronMode($held."\n* * * * * cd ".GuristasOperations::root()." && /usr/bin/php bin/guristas-ops.php tick 2>&1 | logger -t guristas-ops\n")['mode']==='conflict','active dispatcher during validation rejected');
assertR3(count($jobs)===11,'all eleven job definitions retained');
echo "PASS v57r3 scheduler isolation assertions\n";
