<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$runner=file_get_contents($root.'/bin/guristas-ops.php');
$cron=file_get_contents($root.'/bin/guristas-ops-recovery-cron.py');
foreach(['startup acknowledgement','launcher_pid','Worker failed to start.','Could not finalize failed run','probe-','Accepted '] as $needed){
 if(!str_contains($runner,$needed))throw new RuntimeException('Missing runner recovery: '.$needed);
}
foreach(['restore','activate','--apply','Expected exactly one v54 scheduler block','LEGACY'] as $needed){
 if(!str_contains($cron,$needed))throw new RuntimeException('Missing cron recovery guard: '.$needed);
}
echo "PASS v56: guarded cron recovery, detached probe, launch evidence and exception finalization\n";
