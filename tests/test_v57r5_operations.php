<?php
/** Regression assertions for v57r5 deferred publication requests. */
declare(strict_types=1);
require dirname(__DIR__).'/bin/guristas-ops.php';
function checkR5(bool $ok,string $name): void {if(!$ok)throw new RuntimeException('FAIL: '.$name);echo "PASS: $name\n";}
$now=1000;
[$ready,$future]=ops_due_requests([['job'=>'trade','trigger'=>'retry','not_before'=>1090,'retry_attempt'=>1]],$now);
checkR5(!$ready&&count($future)===1,'future retry stays queued');
[$ready,$future]=ops_due_requests([['job'=>'trade','trigger'=>'retry','not_before'=>1090,'retry_attempt'=>1]],1100);
checkR5(isset($ready['trade'])&&!$future,'retry becomes due after delay');
[$ready,$future]=ops_due_requests([['job'=>'trade','trigger'=>'retry','not_before'=>900],['job'=>'trade','trigger'=>'manual']],1100);
checkR5($ready['trade']['trigger']==='manual','manual request retains precedence');
[$ready,$future]=ops_due_requests([['job'=>'trade','trigger'=>'retry','not_before'=>1900],['job'=>'raid','trigger'=>'manual']],1100);
checkR5(isset($ready['raid'])&&!isset($ready['trade'])&&count($future)===1,'future publication does not block other jobs');
checkR5(GuristasOperations::collector('trade')===false,'existing collector-lock classification unchanged');
echo "PASS v57r5 deferred retry assertions\n";
