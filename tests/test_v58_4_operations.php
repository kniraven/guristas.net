<?php
declare(strict_types=1);
require dirname(__DIR__).'/bin/guristas-ops.php';
function check584(bool $ok,string $label): void {if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);} echo "PASS: $label\n";}
$src=file_get_contents(dirname(__DIR__).'/bin/guristas-ops.php');
check584(function_exists('ops_domain_retry'),'Domain retry is implemented');
check584(str_contains($src,"if(\$id==='domain'&&\$result==='skipped'&&\$diag['kind']==='overlap')ops_domain_retry(\$token)"),'only confirmed Domain workload overlap invokes retries');
check584(str_contains($src,"if(\$id==='domain')ops_domain_retry(\$token)"),'early execution-lock collision also invokes Domain retry');
check584(str_contains($src,'if ($attempt>=4) return;'),'Domain retry chain bounded to four attempts');
check584(str_contains($src,"'not_before'=>time()+90"),'Domain retries delayed by 90 seconds');
check584(str_contains($src,"foreach (\$s['queue'] as \$q) if ((\$q['job']??'')==='domain') return;"),'Domain queued requests deduplicated');
check584(str_contains($src,"['market-collectors']"),'shared collector locking retained');
$due=ops_due_requests([
 ['job'=>'domain','trigger'=>'retry','actor'=>0,'requested'=>100,'not_before'=>290,'retry_attempt'=>2],
 ['job'=>'raid','trigger'=>'manual','actor'=>1,'requested'=>100]
],200);
check584(!isset($due[0]['domain'])&&count($due[1])===1,'future Domain retry deferred without blocking manual work');
$due2=ops_due_requests($due[1],300);
check584(isset($due2[0]['domain'])&&$due2[0]['domain']['retry_attempt']===2,'Domain retry eligible after its delay');
echo "PASS v58.4 bounded Domain retry invariants (isolated; no production state touched)\n";
