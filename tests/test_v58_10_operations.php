<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/services/IntelligenceOperations.php';
$fixture = [
 'guristas-venal-collector'=>[
  'timer'=>['LoadState'=>'loaded','ActiveState'=>'active','LastTriggerUSec'=>'Thu 2026-10-15 23:00:01 CDT','NextElapseUSecRealtime'=>'Fri 2026-10-16 00:00:00 CDT'],
  'service'=>['LoadState'=>'loaded','ActiveState'=>'inactive','Result'=>'success','ExecMainStatus'=>'0','ExecMainStartTimestamp'=>'Thu 2026-10-15 23:05:43 CDT','ExecMainExitTimestamp'=>'Thu 2026-10-15 23:05:44 CDT'],
  'schedule'=>['ok'=>true,'minute'=>0,'hour'=>0,'enabled'=>true,'mode'=>'hourly'],
  'journal'=>"{\n  \"ok\": true,\n  \"collector\": \"Venal hourly activity\",\n  \"result\": {\n    \"status\": \"recorded\",\n    \"bucket\": \"2026-10-16T04:00:00+00:00\",\n    \"path\": \"/var/www/sites/guristas.net/storage/history/venal/2026101604.json\"\n  }\n}"
 ],
];
$rows=GuristasOperations::systemTimers($fixture, strtotime('2026-10-16 04:06:00 UTC'));
$venal=$rows[0];
function check10(bool $ok,string $name):void{if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}echo "PASS: $name\n";}
check10(isset($venal['last_start_at']) && $venal['last_start_at'] === strtotime('2026-10-16 04:05:43 UTC'),'manual service start time exposed');
check10(($venal['last_exit_at']??null)===strtotime('2026-10-16 04:05:44 UTC'),'service completion time exposed');
check10($venal['last_start_at']!==$venal['last_trigger_at'],'manual service timestamp independent of timer trigger');
check10($venal['health']==='healthy','systemd health semantics unchanged');
$js=file_get_contents(__DIR__.'/../public/assets/js/intelligence-operations.js');
check10(str_contains($js,'Last service execution:') && str_contains($js,'Last scheduled timer trigger:'),'separate service and timer times rendered');
check10(str_contains($js,'systemdAuditDiff') && str_contains($js,'systemd_settings_changed'), 'systemd audit human labels and differences rendered');
echo "PASS v58.10 presentation and independent timestamps.\n";
