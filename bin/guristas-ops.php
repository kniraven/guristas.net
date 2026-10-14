<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/IntelligenceOperations.php';
date_default_timezone_set('America/Chicago');
$command=$argv[1]??'';
try {
    if($command==='probe'){
        $token=$argv[2]??'';
        if(!preg_match('/^[a-f0-9]{24}$/',$token))exit(2);
        $dir=GuristasOperations::dir();
        if(!is_dir($dir))exit(3);
        $written=file_put_contents($dir.'/probe-'.$token.'.json',json_encode(['pid'=>getmypid(),'time'=>time(),'user'=>get_current_user()],JSON_THROW_ON_ERROR));
        exit($written===false?4:0);
    } elseif($command==='tick'){
        $batch=GuristasOperations::withLock(static function(){
            $state=GuristasOperations::state();$minute=date('Y-m-d H:i');
            $due=[];
            if(($state['last_tick']??null)!==$minute){
                foreach(GuristasOperations::jobs() as $id=>$job)if(GuristasOperations::due(GuristasOperations::settings($id,$state),time()))$due[$id]=['job'=>$id,'trigger'=>'schedule','actor'=>0,'requested'=>time()];
                $state['last_tick']=$minute;
            }
            foreach($state['queue'] as $request)$due[$request['job']]=$request;
            $state['queue']=[];$spawn=[];
            foreach($due as $id=>$request){
                $previous=$state['runs'][$id][0]??null;$limit=GuristasOperations::settings($id,$state)['timeout'];
                if($previous && $previous['status']==='running' && time()-$previous['started']<=$limit+90){$state['queue'][]=$request;continue;}
                if($previous && $previous['status']==='running'){
                    $state['runs'][$id][0]['status']='crashed';
                    $state['runs'][$id][0]['summary']='Previous run stopped reporting without completing.';
                    $state['runs'][$id][0]['cause']='Process terminated, timed out, or failed to update its completion record.';
                }
                $token=bin2hex(random_bytes(12));
                if(!isset($state['runs'][$id]))$state['runs'][$id]=[];
                array_unshift($state['runs'][$id],['token'=>$token,'status'=>'running','trigger'=>$request['trigger'],'actor'=>$request['actor'],'started'=>time(),'ended'=>null]);
                $state['runs'][$id]=array_slice($state['runs'][$id],0,100);
                $spawn[]=[$id,$token];
            }
            GuristasOperations::save($state);return $spawn;
        });
        $launched=0;
        foreach($batch as [$id,$token]){
            $log=GuristasOperations::dir().'/worker-launch.log';
            $line='nohup /usr/bin/php '.escapeshellarg(__FILE__).' run '.escapeshellarg($id).' '.escapeshellarg($token)
                .' </dev/null >>'.escapeshellarg($log).' 2>&1 & echo $!';
            $proc=proc_open(['/bin/sh','-c',$line],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,GuristasOperations::root());
            $pid=0;
            if(is_resource($proc)){
                fclose($pipes[0]);$raw=stream_get_contents($pipes[1]);fclose($pipes[1]);
                $error=stream_get_contents($pipes[2]);fclose($pipes[2]);
                $code=proc_close($proc);$pid=ctype_digit(trim((string)$raw))?(int)trim($raw):0;
                if($code!==0||$pid<=0)error_log('Unable to launch operations worker '.$id.': '.trim((string)$error));
            }else error_log('Unable to create worker launcher for '.$id);
            GuristasOperations::withLock(static function()use($id,$token,$pid){
                $state=GuristasOperations::state();foreach($state['runs'][$id]??[] as &$run){
                    if(($run['token']??null)!==$token)continue;
                    $run['launcher_pid']=$pid?:null;
                    if($pid<=0){$run['status']='failed';$run['ended']=time();$run['exit_code']=127;$run['summary']='Worker failed to start.';$run['cause']='The shell launcher returned no process ID. Check worker-launch.log and PHP restrictions.';}
                    break;
                }unset($run);GuristasOperations::save($state);
            });
            if($pid>0)$launched++;
        }
        echo 'Accepted '.count($batch).' operation(s); launcher returned '.$launched." process ID(s).\n";
    } elseif($command==='run'){
        $id=$argv[2]??'';$token=$argv[3]??'';$jobs=GuristasOperations::jobs();if(!isset($jobs[$id])||!preg_match('/^[a-f0-9]{24}$/',$token))exit(2);
        $job=$jobs[$id];$current=GuristasOperations::state();$limit=GuristasOperations::settings($id,$current)['timeout'];
        $started=microtime(true);$output='';$exit=1;$timedOut=false;
        $proc=proc_open($job['cmd'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,GuristasOperations::root());
        if(!is_resource($proc)){$output='Failed to start the configured executable.';}
        else {
            fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$lastExit=null;
            while(true){
                $status=proc_get_status($proc);if(!$status['running']){$lastExit=$status['exitcode'];break;}
                foreach([1,2] as $n){$chunk=stream_get_contents($pipes[$n]);if($chunk!==false && $chunk!=='')$output=substr($output.$chunk,-30000);}
                if(microtime(true)-$started>$limit){$timedOut=true;proc_terminate($proc,15);usleep(250000);if(proc_get_status($proc)['running'])proc_terminate($proc,9);break;}
                usleep(150000);
            }
            foreach([1,2] as $n){$chunk=stream_get_contents($pipes[$n]);if($chunk!==false)$output=substr($output.$chunk,-30000);fclose($pipes[$n]);}
            $closed=proc_close($proc);$exit=$timedOut?124:($lastExit!==null&&$lastExit>=0?$lastExit:$closed);
        }
        $diag=GuristasOperations::digest($output,$exit);
        GuristasOperations::withLock(static function()use($id,$token,$exit,$started,$output,$diag){
            $state=GuristasOperations::state();foreach($state['runs'][$id]??[] as &$run){if($run['token']!==$token)continue;
                $run['ended']=time();$run['duration']=round(microtime(true)-$started,2);$run['exit_code']=$exit;
                $run['status']=$exit===0?'success':($exit===124?'timeout':'failed');$run['summary']=$diag['summary'];$run['cause']=$diag['cause'];$run['kind']=$diag['kind'];$run['output']=substr($output,-12000);break;
            }unset($run);GuristasOperations::save($state);
        });
        echo $diag['summary']."\n";exit($exit===0?0:1);
    } else {fwrite(STDERR,"Usage: php bin/guristas-ops.php tick|run <id> <token>\n");exit(2);}
 } catch(Throwable $e){
    $message='Operations error: '.$e->getMessage();fwrite(STDERR,$message."\n");
    if($command==='run' && isset($id,$token)){
        try{GuristasOperations::withLock(static function()use($id,$token,$message){
            $state=GuristasOperations::state();foreach($state['runs'][$id]??[] as &$run){
                if(($run['token']??null)!==$token)continue;
                $run['status']='failed';$run['ended']=time();$run['duration']=max(0,time()-(int)$run['started']);$run['exit_code']=1;
                $run['summary']='Worker encountered an unexpected exception.';$run['cause']=$message;break;
            }unset($run);GuristasOperations::save($state);
        });}catch(Throwable $ignored){fwrite(STDERR,'Could not finalize failed run: '.$ignored->getMessage()."\n");}
    }
    exit(1);
}
