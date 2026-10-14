<?php
declare(strict_types=1);
// All execution happens in a fresh OS temporary directory with synthetic workloads.
// No production state, cron, market data, network APIs or real jobs are touched.
$source=dirname(__DIR__);
$temp=sys_get_temp_dir().'/guristas-ops-test-'.bin2hex(random_bytes(8));
function check57(bool $ok,string $message,string $details=''): void {if(!$ok)throw new RuntimeException($message.($details!==''?"\n".$details:''));echo 'PASS: '.$message."\n";}
function clean57(string $path): void {
    if(!is_dir($path))return;
    foreach(new FilesystemIterator($path) as $entry){if($entry->isDir()&&!$entry->isLink())clean57($entry->getPathname());else unlink($entry->getPathname());}
    rmdir($path);
}
function seed57(string $scenario,int $timeout=5): string {
    global $temp;
    file_put_contents($temp.'/scenario.txt',$scenario);
    $token=bin2hex(random_bytes(12));
    GuristasOperations::withLock(static function()use($token,$timeout){
        $s=GuristasOperations::state();$s['settings']['raid']['timeout']=$timeout;
        $s['runs']['raid']=[['version'=>57,'token'=>$token,'status'=>'starting','trigger'=>'manual','actor'=>0,'requested'=>time(),'heartbeat'=>time(),'started'=>null,'ended'=>null]];
        $s['queue']=[];$s['last_tick_at']=time();GuristasOperations::save($s);
    });return $token;
}
function run57(array $args,int $timeout=12): array {
    global $temp;$log=$temp.'/command-'.bin2hex(random_bytes(5)).'.log';
    $p=proc_open(array_merge([PHP_BINARY],$args),[0=>['pipe','r'],1=>['file',$log,'w'],2=>['file',$log,'a']],$pipes,$temp);
    if(!is_resource($p))throw new RuntimeException('Unable to launch test process');fclose($pipes[0]);$start=microtime(true);$code=-1;
    while(true){$s=proc_get_status($p);if(!$s['running']){$code=$s['exitcode'];break;}if(microtime(true)-$start>$timeout){proc_terminate($p,9);throw new RuntimeException('Synthetic test timed out');}usleep(20000);}
    $closed=proc_close($p);$output=(string)file_get_contents($log);unlink($log);return [$code>=0?$code:$closed,$output];
}
try{
    mkdir($temp.'/app/services',0770,true);mkdir($temp.'/bin',0770,true);mkdir($temp.'/storage/operations',0770,true);
    $service=(string)file_get_contents($source.'/app/services/IntelligenceOperations.php');
    $service=str_replace("'/usr/bin/php'",var_export(PHP_BINARY,true),$service);
    // Test-only adaptation of the copied class. Production has no environment override.
    $needle="public static function scheduler(): array {";
    if(substr_count($service,$needle)!==1)throw new RuntimeException('Test adaptation point changed');
    $service=str_replace($needle,$needle."\n        return self::cronMode((string)file_get_contents(self::root().'/test-cron.txt'));",$service);
    file_put_contents($temp.'/app/services/IntelligenceOperations.php',$service);
    copy($source.'/bin/guristas-ops.php',$temp.'/bin/guristas-ops.php');
    require $temp.'/bin/guristas-ops.php';
    // Use the same canonical root as the parser, including native Windows separators.
    $managed='* * * * * cd '.GuristasOperations::root().' && /usr/bin/php bin/guristas-ops.php tick 2>&1 | logger -t guristas-ops';
    file_put_contents($temp.'/test-cron.txt',$managed);
    file_put_contents($temp.'/bin/guristas-intelligence.php', <<<'FIXTURE'
<?php
$mode=trim(file_get_contents(dirname(__DIR__).'/scenario.txt'));
switch($mode){
case 'success':fwrite(STDOUT,"synthetic stdout\n");fwrite(STDERR,"synthetic stderr\n");exit(0);
case 'failure':fwrite(STDERR,"synthetic failure\n");exit(7);
case 'exception':throw new RuntimeException('synthetic exception');
case 'signal':posix_kill(getmypid(),15);sleep(1);exit(99);
case 'semantic':echo '{"ok": false, "error": "synthetic"}';exit(0);
case 'overlap':echo 'Collector already running.';exit(3);
case 'timeout':sleep(5);exit(0);
case 'long':sleep(20);exit(0);
case 'noise':for($i=0;$i<10000;$i++){echo str_repeat('x',1024);usleep(100);}exit(0);
default:exit(42);
}
FIXTURE
    );
    check57(GuristasOperations::scheduler()['mode']==='managed','synthetic cron root matches the canonical application root');
    $nativeProc=PHP_OS_FAMILY==='Linux'&&ops_identity(getmypid())!==null;
    if(in_array('--require-linux',$argv,true)&&!$nativeProc)throw new RuntimeException('Native Linux process identity is required for the EC2 verification gate');
    check57(GuristasOperations::jobs()['offers']['hours']===[9]&&GuristasOperations::jobs()['sde']['hours']===[9],'LP and SDE default to 9 AM Central');
    $original=['runs'=>['raid'=>[['status'=>'running']]]];foreach($original['runs']['raid']??[] as &$r)$r['status']='success';unset($r);
    check57($original['runs']['raid'][0]['status']==='running','reproduces original temporary-array persistence defect');
    $scenarios=[['success','success',0],['failure','failed',7],['exception','failed',255],['semantic','failed',0],['overlap','skipped',3],['timeout','timeout',124],['noise','failed',125]];
    if(PHP_OS_FAMILY==='Linux'&&function_exists('posix_kill'))$scenarios[]=['signal','failed',null];
    foreach($scenarios as [$scenario,$expected,$exit]){
        $token=seed57($scenario,$scenario==='timeout'?1:5);
        [$code,$output]=run57([$temp.'/bin/guristas-ops.php','run','raid',$token]);
        $r=GuristasOperations::state()['runs']['raid'][0];
        check57($r['status']===$expected&&$r['exit_code']===$exit&&isset($r['ended'],$r['duration'],$r['acknowledged']),$scenario.' persists status, exact exit, acknowledgement, end and duration',json_encode($r)."\n".$output);
        if($scenario==='success')check57(str_contains($r['stdout'],'synthetic stdout')&&str_contains($r['stderr'],'synthetic stderr'),'captures stdout and stderr separately');
        if($scenario==='timeout'&&$nativeProc)check57(ops_identity((int)$r['child_pid'])===null,'timeout removes workload process');
        [$again]=run57([$temp.'/bin/guristas-ops.php','run','raid',$token]);
        check57($again!==0&&GuristasOperations::state()['runs']['raid'][0]===$r,'completed token cannot run twice: '.$scenario);
    }
    $token=seed57('success');
    $bad=str_replace(var_export(PHP_BINARY,true),var_export($temp.'/missing-executable',true),$service);
    file_put_contents($temp.'/app/services/IntelligenceOperations.php',$bad);
    run57([$temp.'/bin/guristas-ops.php','run','raid',$token]);
    file_put_contents($temp.'/app/services/IntelligenceOperations.php',$service);
    $r=GuristasOperations::state()['runs']['raid'][0];check57($r['status']==='failed'&&str_contains($r['cause'],'executable'),'missing executable becomes a persisted worker failure');
    $token=seed57('success');$lock=fopen($temp.'/storage/operations/job-raid.lock','c');flock($lock,LOCK_EX);
    run57([$temp.'/bin/guristas-ops.php','run','raid',$token]);flock($lock,LOCK_UN);fclose($lock);
    check57(GuristasOperations::state()['runs']['raid'][0]['status']==='skipped','per-job lock prevents duplicate workload');
    $token=bin2hex(random_bytes(12));GuristasOperations::withLock(static function()use($token){$s=GuristasOperations::state();$s['runs']['priority']=[['version'=>57,'token'=>$token,'status'=>'starting','trigger'=>'manual','requested'=>time()]];GuristasOperations::save($s);});
    $lock=fopen($temp.'/storage/operations/market-collectors.lock','c');flock($lock,LOCK_EX);
    run57([$temp.'/bin/guristas-ops.php','run','priority',$token]);flock($lock,LOCK_UN);fclose($lock);
    check57(GuristasOperations::state()['runs']['priority'][0]['status']==='skipped','shared collector lock prevents overlapping market workloads');
    $request=GuristasOperations::addRequest('raid','manual',1);$duplicate=GuristasOperations::addRequest('raid','manual',1);
    check57($request['queued']&&!$duplicate['queued'],'duplicate manual requests are rejected');
    GuristasOperations::withLock(static function(){$s=GuristasOperations::state();$s['runs']['build']=[['token'=>'historical','status'=>'running','started'=>time()-86400]];GuristasOperations::save($s);});
    check57(GuristasOperations::addRequest('build','manual',1)['queued'],'expired historical records do not indefinitely block manual requests');
    check57(GuristasOperations::state()['runs']['build'][0]['status']==='running','historical status is preserved without inventing completion');
    $token=seed57('success');
    check57(GuristasOperations::updateRun('raid',$token,['status'=>'success','ended'=>time(),'duration'=>1.25]),'state update finds execution token');
    check57(GuristasOperations::state()['runs']['raid'][0]['duration']===1.25,'completion fields survive a fresh state read');
    // Concurrent mutations, with completion metadata already present.
    file_put_contents($temp.'/increment.php',"<?php require __DIR__.'/app/services/IntelligenceOperations.php'; for(\$i=0;\$i<20;\$i++){GuristasOperations::withLock(static function(){\$s=GuristasOperations::state();\$s['test_counter']=(\$s['test_counter']??0)+1;GuristasOperations::save(\$s);});}");
    $children=[];
    for($i=0;$i<5;$i++){$p=proc_open([PHP_BINARY,$temp.'/increment.php'],[0=>['pipe','r'],1=>['file',$temp.'/increment-'.$i.'.log','w'],2=>['file',$temp.'/increment-'.$i.'.log','a']],$pipes);fclose($pipes[0]);$children[]=$p;}
    foreach($children as $p)check57(proc_close($p)===0,'concurrent writer exited successfully');
    $s=GuristasOperations::state();check57($s['test_counter']===100&&$s['runs']['raid'][0]['duration']===1.25,'concurrent updates preserve all mutations and completion fields');
    check57(GuristasOperations::cronMode($managed)['mode']==='managed','recognizes exact managed dispatcher');
    check57(GuristasOperations::cronMode($managed."\n".$managed)['mode']==='conflict','duplicate dispatchers are rejected');
    check57(GuristasOperations::cronMode($managed."\n*/5 * * * * php bin/guristas-intelligence.php raid")['mode']==='conflict','mixed legacy and managed cron is rejected');
    check57(GuristasOperations::cronMode('CRON_TZ=UTC'."\n".$managed)['mode']==='conflict','unexpected cron timezone prevents dispatch');
    $legacy=[];foreach(GuristasOperations::jobs() as $j)$legacy[]='*/5 * * * * cd '.$temp.' && '.implode(' ',$j['cmd']);
    check57(GuristasOperations::cronMode(implode("\n",$legacy))['mode']==='legacy','recognizes all eleven distinct legacy jobs');
    $duplicates=array_fill(0,11,$legacy[0]);check57(GuristasOperations::cronMode(implode("\n",$duplicates))['mode']!=='legacy','eleven duplicated cron entries do not count as a complete legacy schedule');
    file_put_contents($temp.'/test-cron.txt',implode("\n",$legacy));
    $before=file_get_contents($temp.'/storage/operations/state.json');$view=GuristasOperations::view();
    check57($view['mode']==='legacy'&&count(array_filter($view['jobs'],static fn($j)=>$j['health']==='unverified'))===11,'legacy dashboard does not infer current health from managed history');
    check57(file_get_contents($temp.'/storage/operations/state.json')===$before,'dashboard preserves stored diagnostic history');
    file_put_contents($temp.'/test-cron.txt','');
    [$code]=run57([$temp.'/bin/guristas-ops.php','tick']);check57($code!==0,'tick cannot execute jobs in an unverified scheduler mode');
    $rejected=false;try{GuristasOperations::addRequest('raid','manual',1);}catch(InvalidArgumentException $e){$rejected=true;}
    check57($rejected,'manual requests are refused when managed scheduler is inactive');
    $originalState=(string)file_get_contents($temp.'/storage/operations/state.json');
    file_put_contents($temp.'/storage/operations/state.json','{broken');$rejected=false;
    try{GuristasOperations::state();}catch(Throwable $e){$rejected=true;}
    check57($rejected&&file_get_contents($temp.'/storage/operations/state.json')==='{broken','corrupt state fails closed without clearing diagnostic history');
    file_put_contents($temp.'/storage/operations/state.json',$originalState);
    file_put_contents($temp.'/test-cron.txt',$managed);
    if(PHP_OS_FAMILY==='Linux'){
        $token=seed57('success');$runner=(string)file_get_contents($temp.'/bin/guristas-ops.php');
        $badRunner=str_replace("$"."command=$"."argv[1]??'';", "$"."command=$"."argv[1]??''; if($"."command==='run'){fwrite(STDERR,'synthetic startup failure');exit(23);}",$runner);
        file_put_contents($temp.'/bin/guristas-ops.php',$badRunner);
        check57(!ops_launch('raid',$token),'worker startup failure is not acknowledged as a successful launch');
        file_put_contents($temp.'/bin/guristas-ops.php',$runner);
        $r=GuristasOperations::state()['runs']['raid'][0];check57($r['status']==='failed'&&str_contains($r['output'],'synthetic startup failure'),'startup failure captures diagnostics and finalizes record');
        $token=seed57('success');check57(ops_launch('raid',$token),'detached worker startup is acknowledged');
        $deadline=microtime(true)+6;do{$r=GuristasOperations::state()['runs']['raid'][0];if(!GuristasOperations::active($r))break;usleep(20000);}while(microtime(true)<$deadline);
        check57($r['status']==='success'&&isset($r['launcher_pid'],$r['ended'],$r['duration']),'detached worker completes and retains launcher and result metadata');
        if($nativeProc){
        $token=seed57('long',30);check57(ops_launch('raid',$token),'long-running synthetic worker acknowledged');
        $deadline=microtime(true)+4;do{$r=GuristasOperations::state()['runs']['raid'][0];if(isset($r['child_identity']))break;usleep(20000);}while(microtime(true)<$deadline);
        check57(isset($r['child_identity']),'workload process identity recorded');
        posix_kill($r['worker_pid'],9);usleep(200000);
        GuristasOperations::updateRun('raid',$token,['heartbeat'=>time()-40]);ops_reconcile();
        $r=GuristasOperations::state()['runs']['raid'][0];check57($r['status']==='crashed'&&isset($r['ended'])&&$r['exit_code']===null,'killed worker is reconciled with unknown exit code preserved');
        check57(ops_identity((int)$r['child_pid'])===null,'orphan workload is terminated during reconciliation');
        seed57('success');GuristasOperations::withLock(static function(){
            $s=GuristasOperations::state();$s['runs']=[];$s['queue']=[];$s['last_tick_minute']=null;
            foreach(GuristasOperations::jobs() as $id=>$j)$s['settings'][$id]=['enabled'=>$id==='raid','minutes'=>range(0,59),'hours'=>range(0,23),'timeout'=>5];
            GuristasOperations::save($s);
        });
        check57(ops_tick()===0&&ops_tick()===0,'repeated ticks execute without duplicate dispatch');
        check57(count(GuristasOperations::state()['runs']['raid'])===1,'only one execution is reserved for a due minute');
        $deadline=microtime(true)+6;do{$r=GuristasOperations::state()['runs']['raid'][0];if(!GuristasOperations::active($r))break;usleep(20000);}while(microtime(true)<$deadline);
        check57($r['status']==='success','scheduled synthetic worker records completion');
        }else echo "SKIP: native /proc PID identity is unavailable in this runtime. EC2 must pass killed-worker recovery, process-group cleanup and managed tick tests before activation.\n";
    }else echo "SKIP: Linux detached-launch, process-group cleanup, reconciliation and cron tick integration. Run this suite on EC2 before activation.\n";
    echo "PASS v57 isolated worker tests. No real Guristas workloads or production state were used.\n";
}finally{
    if(PHP_OS_FAMILY==='Linux'&&class_exists('GuristasOperations',false)){
        try{foreach(GuristasOperations::state()['runs'] as $runs)foreach($runs as $r){ops_stop_group((int)($r['child_pid']??0),$r['child_identity']??null);if(isset($r['worker_identity'])&&ops_identity((int)$r['worker_pid'])===$r['worker_identity'])posix_kill((int)$r['worker_pid'],9);}}catch(Throwable $ignored){}
    }
    clean57($temp);
}
