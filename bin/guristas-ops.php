<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/IntelligenceOperations.php';
date_default_timezone_set('America/Chicago');

function ops_identity(int $pid): ?string {
    if($pid<1||PHP_OS_FAMILY!=='Linux')return null;
    $self=@file_get_contents('/proc/self/stat');
    if($self===false||(int)strtok($self,' ')!==getmypid())return null;
    $raw=@file_get_contents('/proc/'.$pid.'/stat');if($raw===false)return null;
    $parts=explode(' ',substr($raw,strrpos($raw,')')+2));
    if(($parts[0]??'')==='Z')return null;
    return isset($parts[19])?$pid.':'.$parts[19]:null;
}
function ops_tail(string $file,int $limit=4000): string {
    if(!is_file($file))return '';$f=fopen($file,'rb');if(!$f)return '';
    try{$size=fstat($f)['size'];if($size>$limit)fseek($f,-$limit,SEEK_END);return (string)stream_get_contents($f);}finally{fclose($f);}
}
function ops_stop_group(int $pid,?string $identity): void {
    if($identity===null||ops_identity($pid)!==$identity)return;
    if(function_exists('posix_kill')){
        posix_kill(-$pid,15);usleep(250000);
        // Group ID is reserved by the leader until it is reaped by this worker.
        posix_kill(-$pid,9);
    }
}
function ops_finish(string $id,string $token,array $fields): void {
    if(!GuristasOperations::updateRun($id,$token,$fields))throw new RuntimeException('Execution token is missing; completion was not recorded');
}
function ops_reconcile(): void {
    $snapshot=GuristasOperations::state();
    foreach($snapshot['runs'] as $id=>$runs)foreach($runs as $run){
        if(!GuristasOperations::active($run))continue;
        $age=time()-(int)($run['heartbeat']??$run['requested']??$run['started']??0);
        if($age<30)continue;
        // Old v54-v56 records have no trustworthy process identity. Preserve them.
        if(($run['version']??0)!==57)continue;
        $workerAlive=isset($run['worker_identity'])&&ops_identity((int)($run['worker_pid']??0))===$run['worker_identity'];
        if($workerAlive){
            $limit=GuristasOperations::settings($id,$snapshot)['timeout'];
            if(time()-(int)($run['started']??time())<=$limit+60)continue;
            ops_stop_group((int)($run['child_pid']??0),$run['child_identity']??null);
            if(function_exists('posix_kill'))posix_kill((int)$run['worker_pid'],9);
            else continue;
        }
        ops_stop_group((int)($run['child_pid']??0),$run['child_identity']??null);
        $token=$run['token'];$log=GuristasOperations::dir().'/worker-'.$token.'.log';
        $out=ops_tail(GuristasOperations::dir().'/stdout-'.$token.'.log');$err=ops_tail(GuristasOperations::dir().'/stderr-'.$token.'.log');
        $fields=['stdout'=>$out,'stderr'=>$err,'status'=>'crashed','ended'=>time(),'duration'=>isset($run['started'])?max(0,time()-$run['started']):null,'exit_code'=>null,'summary'=>'Worker stopped reporting before completion.','cause'=>'Worker process no longer exists. The historical exit code is unknown.','output'=>ops_tail($log)."\n".$out."\n".$err];
        // Check the current record again under lock: never overwrite a completion.
        GuristasOperations::withLock(static function()use($id,$token,$fields){
            $s=GuristasOperations::state();
            foreach(array_keys($s['runs'][$id]??[]) as $i)if(($s['runs'][$id][$i]['token']??'')===$token&&GuristasOperations::active($s['runs'][$id][$i])){
                $s['runs'][$id][$i]=array_replace($s['runs'][$id][$i],$fields);GuristasOperations::recordAlert($s,$id,$s['runs'][$id][$i]);GuristasOperations::save($s);break;
            }
        });
        foreach([$log,GuristasOperations::dir().'/stdout-'.$token.'.log',GuristasOperations::dir().'/stderr-'.$token.'.log'] as $file)if(is_file($file))unlink($file);
    }
}
// Retry only known TRADE publication lock contention. Persist under the shared state lock.
function ops_trade_retry(string $token): void {
    GuristasOperations::withLock(static function() use ($token) {
        $s=GuristasOperations::state();
        foreach ($s['runs']['trade']??[] as $r) {
            if (($r['token']??'')!==$token) continue;
            if (($r['status']??'')!=='skipped'||($r['kind']??'')!=='overlap') return;
            $attempt=(int)($r['retry_attempt']??0);
            if ($attempt>=4) return;
            foreach ($s['queue'] as $q) if (($q['job']??'')==='trade') return;
            $s['queue'][]=['job'=>'trade','trigger'=>'retry','actor'=>0,'requested'=>time(),
                'not_before'=>time()+90,'retry_attempt'=>$attempt+1,'retry_parent'=>$token];
            GuristasOperations::save($s);
            return;
        }
    });
}
// Domain's scheduled slot often intersects the long-running Jita collector.
// Only retry a confirmed lock skip; never bypass the market collector lock.
function ops_domain_retry(string $token): void {
    GuristasOperations::withLock(static function() use ($token) {
        $s=GuristasOperations::state();
        foreach ($s['runs']['domain']??[] as $r) {
            if (($r['token']??'')!==$token) continue;
            if (($r['status']??'')!=='skipped'||($r['kind']??'')!=='overlap') return;
            $attempt=(int)($r['retry_attempt']??0);
            if ($attempt>=4) return;
            foreach ($s['queue'] as $q) if (($q['job']??'')==='domain') return;
            $s['queue'][]=['job'=>'domain','trigger'=>'retry','actor'=>0,'requested'=>time(),
                'not_before'=>time()+90,'retry_attempt'=>$attempt+1,'retry_parent'=>$token];
            GuristasOperations::save($s);
            return;
        }
    });
}
function ops_due_requests(array $queue,int $now): array {
    $due=[];$future=[];
    foreach($queue as $request){
        if(!isset(GuristasOperations::jobs()[$request['job']??'']))continue;
        if(($request['not_before']??0)>$now){$future[]=$request;continue;}
        $id=$request['job'];
        // A queued manual request takes precedence; retries supersede due schedules.
        if(!isset($due[$id])||($request['trigger']??'')==='manual')$due[$id]=$request;
    }
    return [$due,$future];
}
function ops_work(string $id,string $token): int {
    $jobs=GuristasOperations::jobs();
    if(!isset($jobs[$id])||!preg_match('/^[a-f0-9]{24}$/',$token))throw new InvalidArgumentException('Invalid worker job or token');
    $dir=GuristasOperations::dir();$locks=[];$proc=null;$childPid=0;$childIdentity=null;$started=microtime(true);$finished=false;$claimed=false;
    $stdout=$dir.'/stdout-'.$token.'.log';$stderr=$dir.'/stderr-'.$token.'.log';
    $reserve=str_repeat('x',65536);
    register_shutdown_function(static function()use(&$reserve,&$claimed,&$finished,&$childPid,&$childIdentity,$id,$token,$started,$stdout,$stderr){
        if(!$claimed||$finished)return;
        $reserve='';$error=error_get_last();
        try{
            ops_stop_group($childPid,$childIdentity);
            ops_finish($id,$token,['status'=>'failed','ended'=>time(),'duration'=>round(microtime(true)-$started,2),'exit_code'=>null,'summary'=>'Worker terminated before normal completion.','cause'=>$error['message']??'Unexpected worker shutdown.','kind'=>'worker_fatal','output'=>ops_tail($stdout)."\n".ops_tail($stderr)]);
        }catch(Throwable $e){fwrite(STDERR,'Could not finalize failed run: '.$e->getMessage()."\n");}
    });
    try{
        $claimed=GuristasOperations::withLock(static function()use($id,$token){
            $s=GuristasOperations::state();
            foreach(array_keys($s['runs'][$id]??[]) as $i){
                $r=&$s['runs'][$id][$i];
                if(($r['token']??'')!==$token)continue;
                if(($r['status']??'')!=='starting')return false;
                $r=array_replace($r,['status'=>'running','started'=>time(),'heartbeat'=>time(),'worker_pid'=>getmypid(),'worker_identity'=>ops_identity(getmypid()),'acknowledged'=>time()]);
                unset($r);GuristasOperations::save($s);return true;
            }return false;
        });
        if(!$claimed)throw new RuntimeException('Worker token is not awaiting startup; refusing duplicate or untracked execution');
        foreach(array_merge(['job-'.$id],GuristasOperations::collector($id)?['market-collectors']:[]) as $key){
            $lock=fopen($dir.'/'.$key.'.lock','c');
            if(!$lock)throw new RuntimeException('Cannot open execution lock');
            if(!flock($lock,LOCK_EX|LOCK_NB)){
                fclose($lock);ops_finish($id,$token,['status'=>'skipped','ended'=>time(),'duration'=>round(microtime(true)-$started,2),'exit_code'=>null,'summary'=>'Execution skipped because another job holds the execution lock.','cause'=>'Duplicate job or overlapping market collection. No refresh was performed.','kind'=>'overlap']);
                if($id==='domain')ops_domain_retry($token);
                $finished=true;return 0;
            }$locks[]=$lock;
        }
        $s=GuristasOperations::state();$limit=GuristasOperations::settings($id,$s)['timeout'];$cmd=$jobs[$id]['cmd'];
        if(!is_file($cmd[0])||!is_executable($cmd[0]))throw new RuntimeException('Configured executable does not exist or is not executable: '.$cmd[0]);
        if(PHP_OS_FAMILY==='Linux'){
            if(!function_exists('posix_kill')||!is_executable('/usr/bin/setsid'))throw new RuntimeException('Linux worker requires POSIX process signaling and /usr/bin/setsid for process-group cleanup');
            $cmd=array_merge(['/usr/bin/setsid'], $cmd);
        }
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['file',$stdout,'w'],2=>['file',$stderr,'w']],$pipes,GuristasOperations::root());
        if(!is_resource($proc))throw new RuntimeException('Failed to start configured workload');
        fclose($pipes[0]);$status=proc_get_status($proc);$childPid=(int)$status['pid'];$childIdentity=ops_identity($childPid);
        ops_finish($id,$token,['child_pid'=>$childPid,'child_identity'=>$childIdentity]);
        $lastHeartbeat=0;$exit=-1;$forced=null;$signal=null;
        while(true){
            if(!$status['running']){$exit=(int)$status['exitcode'];if($status['signaled']??false)$signal=(int)$status['termsig'];break;}
            if(time()-$lastHeartbeat>=2){ops_finish($id,$token,['heartbeat'=>time()]);$lastHeartbeat=time();}
            clearstatcache(true,$stdout);clearstatcache(true,$stderr);
            if((is_file($stdout)?filesize($stdout):0)+(is_file($stderr)?filesize($stderr):0)>2097152){$forced=125;break;}
            if(microtime(true)-$started>$limit){$forced=124;break;}
            usleep(100000);$status=proc_get_status($proc);
        }
        if($forced!==null){
            if(PHP_OS_FAMILY==='Linux')ops_stop_group($childPid,$childIdentity);
            else {proc_terminate($proc);usleep(200000);}
            if(proc_get_status($proc)['running'])proc_terminate($proc,9);
        }
        $closed=proc_close($proc);$proc=null;$exit=$forced??($exit>=0?$exit:$closed);
        $out=ops_tail($stdout);$err=ops_tail($stderr);$output=$out.($err!==''?"\n[stderr]\n".$err:'');$diag=GuristasOperations::digest($output,$exit);
        if($exit<0)$diag=['summary'=>$signal?'Workload terminated by signal '.$signal.'.':'Workload ended without an available exit code.','cause'=>'The process did not report a normal exit status.','kind'=>'signal'];
        if($exit===125)$diag=['summary'=>'Output exceeded the 2 MiB capture limit.','cause'=>'The worker stopped a noisy process to bound diagnostic storage.','kind'=>'output_limit'];
        $result=$diag['kind']==='overlap'?'skipped':($exit===124?'timeout':($exit===0&&$diag['kind']==='ok'?'success':'failed'));
        ops_finish($id,$token,['status'=>$result,'ended'=>time(),'duration'=>round(microtime(true)-$started,2),'exit_code'=>$exit>=0?$exit:null,'signal'=>$signal,'summary'=>$diag['summary'],'cause'=>$diag['cause'],'kind'=>$diag['kind'],'stdout'=>$out,'stderr'=>$err,'output'=>$output,'heartbeat'=>time()]);
        if($id==='trade'&&$result==='success')GuristasOperations::withLock(static function(){
            $s=GuristasOperations::state();$s['queue']=array_values(array_filter($s['queue'],static fn($q)=>!(($q['job']??'')==='trade'&&($q['trigger']??'')==='retry')));GuristasOperations::save($s);
        });
        if($id==='trade'&&$result==='skipped'&&$diag['kind']==='overlap')ops_trade_retry($token);
        if($id==='domain'&&$result==='skipped'&&$diag['kind']==='overlap')ops_domain_retry($token);
        $finished=true;return in_array($result,['success','skipped'],true)?0:1;
    }catch(Throwable $e){
        if(is_resource($proc)){
            ops_stop_group($childPid,$childIdentity);proc_terminate($proc,9);proc_close($proc);$proc=null;
        }
        if($claimed){
            ops_finish($id,$token,['status'=>'failed','ended'=>time(),'duration'=>round(microtime(true)-$started,2),'exit_code'=>null,'summary'=>'Worker encountered an unexpected exception.','cause'=>$e->getMessage(),'kind'=>'worker','stdout'=>ops_tail($stdout),'stderr'=>ops_tail($stderr),'output'=>ops_tail($stdout)."\n".ops_tail($stderr)]);
            $finished=true;
        }
        fwrite(STDERR,'Operations error: '.$e->getMessage()."\n");return 1;
    }finally{
        foreach($locks as $lock){flock($lock,LOCK_UN);fclose($lock);}
        if($finished)foreach([$stdout,$stderr,$dir.'/worker-'.$token.'.log'] as $file)if(is_file($file))unlink($file);
    }
}
function ops_launch(string $id,string $token): bool {
    $log=GuristasOperations::dir().'/worker-'.$token.'.log';
    $line='nohup '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' run '.escapeshellarg($id).' '.escapeshellarg($token).' </dev/null >'.escapeshellarg($log).' 2>&1 & echo $!';
    $p=proc_open(['/bin/sh','-c',$line],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,GuristasOperations::root());$pid=0;
    if(is_resource($p)){fclose($pipes[0]);$raw=trim((string)stream_get_contents($pipes[1]));fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);if($code===0&&preg_match('/^[0-9]+$/',$raw))$pid=(int)$raw;}
    // A launcher_pid is evidence of launch request only, never completion or acknowledgement.
    ops_finish($id,$token,['launcher_pid'=>$pid?:null]);
    $deadline=microtime(true)+5;
    do{
        $state=GuristasOperations::state();foreach($state['runs'][$id]??[] as $r)if(($r['token']??'')===$token&&isset($r['acknowledged']))return true;
        if(!$pid)break;usleep(50000);
    }while(microtime(true)<$deadline);
    // Revoke an unacknowledged token atomically so a delayed worker cannot begin later.
    $ack=GuristasOperations::withLock(static function()use($id,$token,$log){
        $s=GuristasOperations::state();foreach(array_keys($s['runs'][$id]??[]) as $i){
            $r=&$s['runs'][$id][$i];if(($r['token']??'')!==$token)continue;
            if(isset($r['acknowledged']))return true;
            if(($r['status']??'')==='starting')$r=array_replace($r,['status'=>'failed','ended'=>time(),'exit_code'=>null,'summary'=>'Worker failed to start.','cause'=>'No startup acknowledgement within five seconds. Inspect captured startup output.','kind'=>'launch','output'=>ops_tail($log)]);
            unset($r);GuristasOperations::save($s);break;
        }return false;
    });
    if(is_file($log))unlink($log);return $ack;
}
function ops_tick(): int {
    if(PHP_OS_FAMILY!=='Linux'||ops_identity(getmypid())===null||!function_exists('posix_kill')||!is_executable('/usr/bin/setsid')){fwrite(STDERR,"Dispatcher requires Linux process identity, POSIX signals and setsid. No jobs dispatched.\n");return 1;}
    $mode=GuristasOperations::scheduler();
    if($mode['mode']!=='managed'){fwrite(STDERR,'Dispatcher refused: scheduler mode is '.$mode['mode'].". Legacy cron was not changed.\n");return 1;}
    ops_reconcile();
    $batch=GuristasOperations::withLock(static function(){
        $state=GuristasOperations::state();$minute=(int)floor(time()/60);$due=[];
        if(($state['last_tick_minute']??null)!==$minute){
            foreach(GuristasOperations::jobs() as $id=>$job)if(GuristasOperations::due(GuristasOperations::settings($id,$state),time()))$due[$id]=['job'=>$id,'trigger'=>'schedule','actor'=>0,'requested'=>time()];
            $state['last_tick_minute']=$minute;
        }
        $state['last_tick']=date('Y-m-d h:i:s A T');$state['last_tick_at']=time();
        [$ready,$future]=ops_due_requests($state['queue'],time());
        foreach($ready as $id=>$request)$due[$id]=$request;
        $state['queue']=$future;$spawn=[];
        foreach($due as $id=>$request){
            $blocked=false;foreach($state['runs'][$id]??[] as $run)if(GuristasOperations::active($run)&&(($run['version']??0)===57||time()-(int)($run['started']??0)<=GuristasOperations::settings($id,$state)['timeout']+90)){$blocked=true;break;}
            if($blocked){if(in_array($request['trigger'],['manual','retry'],true))$state['queue'][]=$request;continue;}
            $token=bin2hex(random_bytes(12));if(!isset($state['runs'][$id]))$state['runs'][$id]=[];
            array_unshift($state['runs'][$id],['version'=>57,'token'=>$token,'status'=>'starting','trigger'=>$request['trigger'],'actor'=>$request['actor'],'requested'=>$request['requested'],'retry_attempt'=>$request['retry_attempt']??0,'retry_parent'=>$request['retry_parent']??null,'started'=>null,'ended'=>null,'heartbeat'=>time()]);
            $state['runs'][$id]=array_slice($state['runs'][$id],0,100);$spawn[]=[$id,$token];
        }
        GuristasOperations::save($state);return $spawn;
    });
    $ack=0;foreach($batch as [$id,$token])if(ops_launch($id,$token))$ack++;
    echo 'Accepted '.count($batch).' operation(s); '.$ack." worker startup(s) acknowledged.\n";return $ack===count($batch)?0:1;
}
// Importing this file from isolated tests defines functions without executing jobs.
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
$command=$argv[1]??'';
try{
    if($command==='tick')exit(ops_tick());
    if($command==='run'){
        $mode=GuristasOperations::scheduler()['mode'];
        if($mode!=='managed'&& !($mode==='raid_validation'&&($argv[2]??'')==='raid'))throw new RuntimeException('Managed scheduling is inactive; worker refused.');
        exit(ops_work($argv[2]??'',$argv[3]??''));
    }
    if($command==='validate-raid'){
        if(GuristasOperations::scheduler()['mode']!=='raid_validation')throw new RuntimeException('Requires isolated RAID validation cron mode.');
        ops_reconcile();
        $token=GuristasOperations::withLock(static function(){
            $s=GuristasOperations::state();
            foreach($s['runs']['raid']??[] as $r)if(($r['version']??0)===57&&GuristasOperations::active($r))throw new RuntimeException('Managed RAID already active');
            $token=bin2hex(random_bytes(12));
            if(!isset($s['runs']['raid']))$s['runs']['raid']=[];
            array_unshift($s['runs']['raid'],['version'=>57,'token'=>$token,'status'=>'starting','trigger'=>'validation','actor'=>0,'requested'=>time(),'started'=>null,'ended'=>null,'heartbeat'=>time()]);
            $s['runs']['raid']=array_slice($s['runs']['raid'],0,100);GuristasOperations::save($s);return $token;
        });
        $ack=ops_launch('raid',$token);echo 'RAID validation token: '.$token.' acknowledgement: '.($ack?'yes':'no')."\n";
        if(!$ack)exit(1);
        $deadline=microtime(true)+180;
        do{
            $state=GuristasOperations::state();foreach($state['runs']['raid']??[] as $r)if(($r['token']??'')===$token){
                if(!GuristasOperations::active($r)){echo json_encode($r,JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE)."\n";exit(($r['status']??'')==='success'?0:1);}
            }
            usleep(200000);
        }while(microtime(true)<$deadline);
        fwrite(STDERR,'RAID validation did not complete before monitoring deadline.' . "\n");exit(1);
    }
    if($command==='inspect'){echo json_encode(GuristasOperations::view(),JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),"\n";exit(0);}
    if($command==='probe'){
        $token=$argv[2]??'';if(!preg_match('/^[a-f0-9]{24}$/',$token))exit(2);
        $dir=GuristasOperations::dir();if(!is_dir($dir))exit(3);
        exit(file_put_contents($dir.'/probe-'.$token.'.json',json_encode(['pid'=>getmypid(),'time'=>time()],JSON_THROW_ON_ERROR))===false?4:0);
    }
    fwrite(STDERR,"Usage: php bin/guristas-ops.php tick|run <id> <token>|inspect|probe <token>\n");exit(2);
}catch(Throwable $e){fwrite(STDERR,'Could not finalize failed run or execute operations command: '.$e->getMessage()."\n");exit(1);}
