<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/IntelligenceOperations.php';
date_default_timezone_set('America/Chicago');
$command=$argv[1]??'';
try {
    if($command==='tick'){
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
        foreach($batch as [$id,$token]){
            $line='nohup /usr/bin/php '.escapeshellarg(__FILE__).' run '.escapeshellarg($id).' '.escapeshellarg($token).' </dev/null >/dev/null 2>&1 &';
            $proc=proc_open(['/bin/sh','-c',$line],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,GuristasOperations::root());
            if(is_resource($proc)){foreach($pipes as $p)fclose($p);proc_close($proc);}else fwrite(STDERR,"Unable to start $id\n");
        }
        echo 'Dispatched '.count($batch)." operation(s).\n";
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
} catch(Throwable $e){fwrite(STDERR,'Operations error: '.$e->getMessage()."\n");exit(1);}
