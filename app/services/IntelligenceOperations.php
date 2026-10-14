<?php
declare(strict_types=1);
final class GuristasOperations {
    public static function root(): string {return dirname(__DIR__,2);}
    public static function dir(): string {return self::root().'/storage/operations';}
    public static function jobs(): array {
        return [
            'raid'=>['source'=>'CCP EVE ESI insurgency/campaign information','pulls'=>'Guristas insurgency state, active campaigns and warfare context','purpose'=>'RAID homepage intelligence and insurgency status','name'=>'RAID insurgency','group'=>'RAID','cmd'=>['/usr/bin/php','bin/guristas-intelligence.php','raid'],'minutes'=>range(0,59,5),'hours'=>range(0,23),'timeout'=>120,'hint'=>'5 min. Insurgency status changes rapidly.'],
            'priority'=>['source'=>'CCP ESI regional market-order endpoints, using configured priority regions and items','pulls'=>'Priority buy/sell market-order snapshots','purpose'=>'TRADE and BUILD pricing inputs','name'=>'Priority markets','group'=>'TRADE','cmd'=>['/usr/bin/php','-d','memory_limit=256M','bin/guristas-intelligence.php','collect-priority'],'minutes'=>range(0,59,10),'hours'=>range(0,23),'timeout'=>240,'hint'=>'10 min, contingent on collector runtime and ESI caching.'],
            'rotate'=>['source'=>'CCP ESI regional market-order endpoints, using rotating region coverage','pulls'=>'A rotating slice of market-order snapshots','purpose'=>'Broader regional TRADE and BUILD price comparison','name'=>'Rotating markets','group'=>'TRADE','cmd'=>['/usr/bin/php','-d','memory_limit=256M','bin/guristas-intelligence.php','collect-rotate'],'minutes'=>[5,15,25,35,45,55],'hours'=>range(0,23),'timeout'=>240,'hint'=>'10 min offset. Avoid collisions with other market collectors.'],
            'forge'=>['source'=>'CCP ESI market orders for The Forge (region 10000002)','pulls'=>'Jita-region market buy and sell orders','purpose'=>'Reference prices and liquidity for TRADE/BUILD','name'=>'The Forge / Jita','group'=>'TRADE','cmd'=>['/usr/bin/php','-d','memory_limit=256M','bin/guristas-intelligence.php','collect-region','10000002'],'minutes'=>[2,32],'hours'=>range(0,23),'timeout'=>360,'hint'=>'30 min. Large collection; check p95 runtime and ESI freshness.'],
            'domain'=>['source'=>'CCP ESI market orders for Domain (region 10000043)','pulls'=>'Amarr-region buy and sell orders','purpose'=>'Alternative hub reference prices for TRADE','name'=>'Domain / Amarr','group'=>'TRADE','cmd'=>['/usr/bin/php','-d','memory_limit=256M','bin/guristas-intelligence.php','collect-region','10000043'],'minutes'=>[4,34],'hours'=>range(0,23),'timeout'=>300,'hint'=>'30 min starting point, subject to measured demand.'],
            'sinq'=>['source'=>'CCP ESI market orders for Sinq Laison (region 10000032)','pulls'=>'Dodixie-region buy and sell orders','purpose'=>'Alternative hub reference prices for TRADE/BUILD','name'=>'Sinq Laison / Dodixie','group'=>'TRADE','cmd'=>['/usr/bin/php','-d','memory_limit=256M','bin/guristas-intelligence.php','collect-region','10000032'],'minutes'=>[7,37],'hours'=>range(0,23),'timeout'=>300,'hint'=>'30 min starting point; consider staggering with other collection.'],
            'build'=>['source'=>'Local collected market snapshots, SDE/reference data, Venal resource catalog and industry data','pulls'=>'Manufacturing, mining and planetary recommendation cards','purpose'=>'Publish the three BUILD homepage recommendations','name'=>'BUILD cards publication','group'=>'BUILD','cmd'=>['/usr/bin/php','bin/guristas-build.php','publish'],'minutes'=>[8,18,28,38,48,58],'hours'=>range(0,23),'timeout'=>300,'hint'=>'10 min to maintain card freshness; consider no-change detection.'],
            'planets'=>['source'=>'EVE static universe/planetary reference data and local Venal datasets','pulls'=>'Venal planetary resource intelligence','purpose'=>'BUILD planetary-industry opportunities','name'=>'BUILD planet data','group'=>'BUILD','cmd'=>['/usr/bin/php','bin/guristas-build-planets.php'],'minutes'=>[49],'hours'=>range(0,23),'timeout'=>300,'hint'=>'Hourly; verify data changes and downstream consumer needs.'],
            'offers'=>['source'=>'Configured loyalty-point store offer import sources','pulls'=>'LP offer and cost reference data','purpose'=>'LP profitability and TRADE/BUILD blueprint opportunities','name'=>'LP store offers','group'=>'TRADE','cmd'=>['/usr/bin/php','-d','memory_limit=256M','bin/guristas-intelligence.php','offers'],'minutes'=>[10],'hours'=>[9],'timeout'=>360,'hint'=>'Daily, with refresh on expiry; revisit if ESI offer changes require it.'],
            'sde'=>['source'=>'EVE static data source configured in guristas-sde-sync.py','pulls'=>'Static items, recipes and universe reference data','purpose'=>'Reference database used by manufacturing and other intelligence','name'=>'EVE SDE synchronization','group'=>'SYSTEM','cmd'=>['/usr/bin/python3','bin/guristas-sde-sync.py'],'minutes'=>[25],'hours'=>[9],'timeout'=>900,'hint'=>'Daily revision check; only update static reference on upstream changes.'],
            'trade'=>['source'=>'Local region market snapshots, priority item data and LP reference data','pulls'=>'Calculated regional trading and LP intelligence','purpose'=>'Publish TRADE recommendations','name'=>'TRADE cards publication','group'=>'TRADE','cmd'=>['/usr/bin/php','-d','memory_limit=512M','bin/guristas-intelligence.php','publish'],'minutes'=>[0,30],'hours'=>range(0,23),'timeout'=>600,'hint'=>'30 min, or after snapshot changes; preserve 512M memory.'],
        ];
    }

    public static function withLock(callable $callback) {
        $dir=self::dir();
        if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Operations directory unavailable');
        $lock=fopen($dir.'/state.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Operations lock unavailable');
        try{return $callback($dir);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function state(): array {
        $p=self::dir().'/state.json';
        if(!is_file($p))return ['settings'=>[],'queue'=>[],'runs'=>[],'last_tick'=>null];
        $raw=file_get_contents($p);
        if($raw===false)throw new RuntimeException('Cannot read operations state');
        $state=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($state))throw new RuntimeException('Invalid operations state');
        foreach(['settings','queue','runs'] as $key)if(!isset($state[$key])||!is_array($state[$key]))throw new RuntimeException('Invalid state field: '.$key);
        return $state;
    }
    public static function save(array $state): void {
        $dir=self::dir();$json=json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        $tmp=tempnam($dir,'.ops');if($tmp===false)throw new RuntimeException('Unable to create operations state');
        try{
            if(file_put_contents($tmp,$json)!==strlen($json))throw new RuntimeException('Incomplete operations state write');
            if(!chmod($tmp,0660)||!rename($tmp,$dir.'/state.json'))throw new RuntimeException('Unable to commit operations state');
        }finally{if(is_file($tmp))unlink($tmp);}
    }
    public static function settings(string $id,array $state): array {
        $j=self::jobs()[$id];$v=$state['settings'][$id]??[];
        return ['enabled'=>(bool)($v['enabled']??true),'minutes'=>$v['minutes']??$j['minutes'],'hours'=>$v['hours']??$j['hours'],'timeout'=>(int)($v['timeout']??$j['timeout']),'notify'=>$v['notify']??'recovery'];
    }
    public static function validTimeList($v,int $max): bool {return is_array($v)&&count($v)>=1&&count($v)<=($max+1)&&count($v)===count(array_unique($v))&&array_reduce($v,static fn($ok,$n)=>$ok&&is_int($n)&&$n>=0&&$n<=$max,true);}
    public static function due(array $s,int $ts): bool {
        $dt=(new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('America/Chicago'));
        return $s['enabled']&&in_array((int)$dt->format('i'),$s['minutes'],true)&&in_array((int)$dt->format('G'),$s['hours'],true);
    }
    public static function active(array $run): bool {return in_array($run['status']??'', ['starting','running'],true);}
    public static function collector(string $id): bool {return in_array($id,['priority','rotate','forge','domain','sinq'],true);}
    // Always update the actual array, never a null-coalescing temporary expression.
    public static function updateRun(string $id,string $token,array $fields): bool {
        return self::withLock(static function()use($id,$token,$fields){
            $state=self::state();
            foreach(array_keys($state['runs'][$id]??[]) as $i){
                if(($state['runs'][$id][$i]['token']??'')!==$token)continue;
                $state['runs'][$id][$i]=array_replace($state['runs'][$id][$i],$fields);
                self::save($state);return true;
            }
            return false;
        });
    }
    public static function cronMode(string $text): array {
        $managed=0;$legacy=0;$other=0;$seen=[];
        foreach(preg_split('/\R/',$text) as $line){
            $line=trim($line);if($line===''||$line[0]==='#')continue;
            if(preg_match('/^(?:CRON_TZ|TZ)\s*=/', $line)){$other++;continue;}
            if(!str_contains($line,'guristas-'))continue;
            if(str_contains($line,'guristas-ops.php')){
                if(preg_match('/^\* \* \* \* \* cd '.preg_quote(self::root(),'/').' && \/usr\/bin\/php bin\/guristas-ops\.php tick 2>&1 \| logger -t guristas-ops$/',$line))$managed++;
                else $other++;
            }elseif(preg_match('/bin\/guristas-(?:intelligence\.php|build\.php|build-planets\.php|sde-sync\.py)/',$line)){
                $legacy++;$matches=[];
                foreach(self::jobs() as $id=>$job){
                    $parts=$job['cmd'];foreach($parts as $i=>$part)if(str_starts_with($part,'bin/')){$command=implode(' ',array_slice($parts,$i));break;}
                    if(preg_match('/(?<![\w-])'.preg_quote($command,'/').'(?:\s|$)/',$line))$matches[]=$id;
                }
                if(count($matches)!==1)$other++;else $seen[$matches[0]]=($seen[$matches[0]]??0)+1;
            }
        }
        $completeLegacy=count($seen)===11&&max($seen)===1;
        $mode=$other||$managed>1||($managed&&$legacy)?'conflict':($managed===1?'managed':($legacy===11&&$completeLegacy?'legacy':($legacy?'partial_legacy':'unverified')));
        return ['mode'=>$mode,'managed_entries'=>$managed,'legacy_entries'=>$legacy,'checked_at'=>time()];
    }
    public static function scheduler(): array {
        if(PHP_OS_FAMILY==='Windows')return ['mode'=>'unverified','reason'=>'Cron inspection is available on EC2 only.'];
        $cmd=['/usr/bin/crontab','-l'];
        if(function_exists('posix_geteuid')&&posix_geteuid()===0)$cmd=['/usr/bin/crontab','-u','apache','-l'];
        $result=self::inspectCommand($cmd);
        if($result['exit']!==0)return ['mode'=>'unverified','reason'=>'Cannot read Apache crontab. No scheduler mode inferred from old records.'];
        return self::cronMode($result['output']);
    }
    // Small, read-only OS inspections. Bound time and output; never invoke a shell.
    public static function inspectCommand(array $cmd): array {
        if(!function_exists('proc_open'))return ['exit'=>127,'output'=>'proc_open unavailable'];
        $p=@proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))return ['exit'=>127,'output'=>'Unable to start inspection'];
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $start=microtime(true);$out='';$exit=-1;
        do{
            $out=substr($out.stream_get_contents($pipes[1]),-65536);stream_get_contents($pipes[2]);
            $s=proc_get_status($p);
            if(!$s['running']){$exit=$s['exitcode'];break;}
            if(microtime(true)-$start>2){proc_terminate($p,9);$exit=124;break;}
            usleep(20000);
        }while(true);
        $out=substr($out.stream_get_contents($pipes[1]),-65536);
        fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($p);
        return ['exit'=>$exit>=0?$exit:$closed,'output'=>$out];
    }
    public static function addRequest(string $id,string $trigger,int $actor=0): array {
        if(self::scheduler()['mode']!=='managed')throw new InvalidArgumentException('Manual execution requires the verified managed scheduler. Legacy cron remains in control.');
        return self::withLock(static function()use($id,$trigger,$actor){
            $state=self::state();if(!isset(self::jobs()[$id]))throw new InvalidArgumentException('Unknown job');
            if(time()-(int)($state['last_tick_at']??0)>180)throw new InvalidArgumentException('The managed dispatcher has not reported recently. Inspect EC2 before queueing work.');
            foreach($state['queue'] as $q)if($q['job']===$id)return ['queued'=>false,'reason'=>'Already queued'];
            foreach($state['runs'][$id]??[] as $run)if(self::active($run)&&(($run['version']??0)===57||time()-(int)($run['started']??0)<=self::settings($id,$state)['timeout']+90))return ['queued'=>false,'reason'=>'Job is already running or awaiting reconciliation'];
            $state['queue'][]=['job'=>$id,'trigger'=>$trigger,'actor'=>$actor,'requested'=>time()];self::save($state);return ['queued'=>true];
        });
    }
    public static function digest(string $output,int $exit): array {
        $text=trim($output);$lower=strtolower($text);
        if($exit===124)return ['summary'=>'The job exceeded its execution timeout.','cause'=>'Slow API, high workload, or a stalled operation.','kind'=>'timeout'];
        if(str_contains($lower,'collector already running'))return ['summary'=>'Collection skipped because another collector holds the lock.','cause'=>'Overlapping market collections; review durations and stagger schedules.','kind'=>'overlap'];
        if(str_contains($lower,'allowed memory size')||str_contains($lower,'out of memory'))return ['summary'=>'The job exhausted its memory allowance.','cause'=>'Data processing exceeded the configured memory limit.','kind'=>'memory'];
        if(str_contains($lower,'fatal error')||str_contains($lower,'uncaught'))return ['summary'=>'The application encountered a fatal error.','cause'=>'A PHP or application exception terminated the job.','kind'=>'exception'];
        if($exit!==0&&(str_contains($lower,'rate limit')||preg_match('/\b429\b/',$lower)))return ['summary'=>'The EVE API is rate-limiting requests.','cause'=>'Too many requests or a temporary ESI rate limit.','kind'=>'api_rate'];
        if($exit!==0)return ['summary'=>'The job exited unsuccessfully (code '.$exit.').','cause'=>'See captured stdout and stderr for the precise failure.','kind'=>'exit'];
        // Includes pretty-printed JSON and JSON embedded in command log output.
        if(preg_match('/"ok"\s*:\s*false\b/i',$text))return ['summary'=>'The command reported an unsuccessful application result.','cause'=>'Process exited zero, but its output contains ok: false.','kind'=>'application'];
        return ['summary'=>'Completed successfully.','cause'=>'','kind'=>'ok'];
    }
    public static function systemTimers(): array {
        $timers=[];
        foreach(['guristas-venal-collect'=>'Venal collection','guristas-cache-cleanup'=>'Cache cleanup'] as $unit=>$name){
            $row=['name'=>$name,'unit'=>$unit.'.timer','control'=>'read-only','verified'=>false];
            if(PHP_OS_FAMILY!=='Windows'){
                $t=self::inspectCommand(['/usr/bin/systemctl','show',$unit.'.timer','--no-pager','--property=LoadState,ActiveState,SubState,NextElapseUSecRealtime,LastTriggerUSec']);
                $s=self::inspectCommand(['/usr/bin/systemctl','show',$unit.'.service','--no-pager','--property=LoadState,ActiveState,Result,ExecMainStatus,ExecMainStartTimestamp,ExecMainExitTimestamp']);
                foreach(['timer'=>$t,'service'=>$s] as $key=>$result){$row[$key]=[];if($result['exit']===0)foreach(explode("\n",trim($result['output'])) as $line){$kv=explode('=',$line,2);if(count($kv)===2)$row[$key][$kv[0]]=$kv[1];}}
                $row['verified']=($row['timer']['LoadState']??'')==='loaded'&&($row['service']['LoadState']??'')==='loaded';
            }
            $timers[]=$row;
        }
        return $timers;
    }
    public static function view(): array {
        $state=self::state();$now=time();$entries=[];$scheduler=self::scheduler();$mode=$scheduler['mode'];
        $freshTick=$now-(int)($state['last_tick_at']??0)<=180;
        foreach(self::jobs() as $id=>$job){
            $s=self::settings($id,$state);$runs=$state['runs'][$id]??[];
            $done=array_values(array_filter($runs,static fn($r)=>isset($r['duration'],$r['ended'])&&!self::active($r)&&is_numeric($r['duration'])));
            $dur=array_column(array_slice($done,0,50),'duration');sort($dur,SORT_NUMERIC);
            $avg=$dur?round(array_sum($dur)/count($dur),1):null;$p95=$dur?$dur[(int)ceil(count($dur)*.95)-1]:null;
            $last=$runs[0]??null;$queued=(bool)array_filter($state['queue'],static fn($q)=>$q['job']===$id);
            $health='unverified';
            if($mode==='managed'){
                if(!$freshTick)$health='unverified';
                elseif($last&&self::active($last))$health=($now-(int)($last['heartbeat']??$last['started']??$last['requested']??0)>$s['timeout']+90)?'unverified':$last['status'];
                elseif($queued)$health='queued';
                elseif(!$s['enabled'])$health='disabled';
                elseif(!$last)$health='awaiting_first_run';
                else $health=match($last['status']??''){ 'success'=>'healthy','skipped'=>'skipped','failed','timeout'=>'failed','crashed'=>'crashed',default=>'unverified'};
            }
            $entries[]=['id'=>$id,'name'=>$job['name'],'group'=>$job['group'],'hint'=>$job['hint'],'source'=>$job['source'],'pulls'=>$job['pulls'],'purpose'=>$job['purpose'],'settings'=>$s,'last'=>$last,'average_seconds'=>$avg,'p95_seconds'=>$p95,'samples'=>count($dur),'queue'=>$queued,'health'=>$health,'history'=>array_slice($runs,0,20),'freshness'=>['status'=>'unverified','reason'=>'Dataset freshness requires workload-specific evidence; a completed process alone does not verify it.']];
        }
        return ['jobs'=>$entries,'last_tick'=>$state['last_tick']??null,'last_tick_at'=>$state['last_tick_at']??null,'mode'=>$mode,'scheduler'=>$scheduler,'dispatcher_recent'=>$freshTick,'systemd'=>self::systemTimers()];
    }
}
