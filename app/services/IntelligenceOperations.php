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
            'offers'=>['source'=>'Configured loyalty-point store offer import sources','pulls'=>'LP offer and cost reference data','purpose'=>'LP profitability and TRADE/BUILD blueprint opportunities','name'=>'LP store offers','group'=>'TRADE','cmd'=>['/usr/bin/php','-d','memory_limit=256M','bin/guristas-intelligence.php','offers'],'minutes'=>[10],'hours'=>[4],'timeout'=>360,'hint'=>'Daily, with refresh on expiry; revisit if ESI offer changes require it.'],
            'sde'=>['source'=>'EVE static data source configured in guristas-sde-sync.py','pulls'=>'Static items, recipes and universe reference data','purpose'=>'Reference database used by manufacturing and other intelligence','name'=>'EVE SDE synchronization','group'=>'SYSTEM','cmd'=>['/usr/bin/python3','bin/guristas-sde-sync.py'],'minutes'=>[25],'hours'=>[4],'timeout'=>900,'hint'=>'Daily revision check; only update static reference on upstream changes.'],
            'trade'=>['source'=>'Local region market snapshots, priority item data and LP reference data','pulls'=>'Calculated regional trading and LP intelligence','purpose'=>'Publish TRADE recommendations','name'=>'TRADE cards publication','group'=>'TRADE','cmd'=>['/usr/bin/php','-d','memory_limit=512M','bin/guristas-intelligence.php','publish'],'minutes'=>[0,30],'hours'=>range(0,23),'timeout'=>600,'hint'=>'30 min, or after snapshot changes; preserve 512M memory.'],
        ];
    }
    public static function withLock(callable $callback) {
        $dir=self::dir();if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Operations directory unavailable');
        $lock=fopen($dir.'/state.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Operations lock unavailable');
        try{return $callback($dir);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function state(): array {
        $p=self::dir().'/state.json';$state=is_file($p)?json_decode((string)file_get_contents($p),true):null;
        return is_array($state)?$state:['settings'=>[],'queue'=>[],'runs'=>[],'last_tick'=>null];
    }
    public static function save(array $state): void {
        $dir=self::dir();$tmp=tempnam($dir,'.ops');if($tmp===false)throw new RuntimeException('Unable to write operations state');
        file_put_contents($tmp,json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));chmod($tmp,0660);
        if(!rename($tmp,$dir.'/state.json'))throw new RuntimeException('Unable to commit operations state');
    }
    public static function settings(string $id,array $state): array {
        $j=self::jobs()[$id];$v=$state['settings'][$id]??[];
        return ['enabled'=>(bool)($v['enabled']??true),'minutes'=>$v['minutes']??$j['minutes'],'hours'=>$v['hours']??$j['hours'],'timeout'=>(int)($v['timeout']??$j['timeout']),'notify'=>$v['notify']??'recovery'];
    }
    public static function validTimeList($v,int $max): bool {return is_array($v)&&count($v)>=1&&count($v)<=($max+1)&&count($v)===count(array_unique($v))&&array_reduce($v,static fn($ok,$n)=>$ok&&is_int($n)&&$n>=0&&$n<=$max,true);}
    public static function due(array $s,int $ts): bool {return $s['enabled']&&in_array((int)date('i',$ts),$s['minutes'],true)&&in_array((int)date('G',$ts),$s['hours'],true);}
    public static function addRequest(string $id,string $trigger,int $actor=0): array {
        return self::withLock(static function($dir)use($id,$trigger,$actor){
            $state=self::state();if(!isset(self::jobs()[$id]))throw new InvalidArgumentException('Unknown job');
            foreach($state['queue'] as $q)if($q['job']===$id)return ['queued'=>false,'reason'=>'Already queued'];
            $run=$state['runs'][$id][0]??null;
            if($run && $run['status']==='running' && time()-(int)$run['started']<self::settings($id,$state)['timeout']+90)return ['queued'=>false,'reason'=>'Job is already running'];
            $state['queue'][]=['job'=>$id,'trigger'=>$trigger,'actor'=>$actor,'requested'=>time()];self::save($state);return ['queued'=>true];
        });
    }
    public static function digest(string $output,int $exit): array {
        $text=trim($output);$lower=strtolower($text);
        if(str_contains($lower,'collector already running'))return ['summary'=>'Collection skipped because another collector holds the lock.','cause'=>'Overlapping market collections; review durations and stagger schedules.','kind'=>'overlap'];
        if(str_contains($lower,'allowed memory size')||str_contains($lower,'out of memory'))return ['summary'=>'The job exhausted its memory allowance.','cause'=>'Data processing exceeded the configured memory limit.','kind'=>'memory'];
        if(str_contains($lower,'rate limit')||str_contains($lower,'429'))return ['summary'=>'The EVE API is rate-limiting requests.','cause'=>'Too many requests or a temporary ESI rate limit.','kind'=>'api_rate'];
        if(str_contains($lower,'timed out')||$exit===124)return ['summary'=>'The job exceeded its execution timeout.','cause'=>'Slow API, high workload, or a stalled operation.','kind'=>'timeout'];
        if(str_contains($lower,'fatal error')||str_contains($lower,'uncaught'))return ['summary'=>'The application encountered a fatal error.','cause'=>'A PHP or application exception terminated the job.','kind'=>'exception'];
        if($exit!==0)return ['summary'=>'The job exited unsuccessfully (code '.$exit.').','cause'=>'See the captured output for the precise failure.','kind'=>'exit'];
        return ['summary'=>'Completed successfully.','cause'=>'','kind'=>'ok'];
    }
    public static function view(): array {
        $state=self::state();$now=time();$entries=[];
        foreach(self::jobs() as $id=>$job){
            $s=self::settings($id,$state);$runs=$state['runs'][$id]??[];$done=array_values(array_filter($runs,static fn($r)=>isset($r['duration'])&&$r['status']!=='running'));
            $dur=array_column(array_slice($done,0,50),'duration');sort($dur,SORT_NUMERIC);
            $avg=$dur?round(array_sum($dur)/count($dur),1):null;$p95=$dur?$dur[(int)ceil(count($dur)*.95)-1]:null;
            $last=$runs[0]??null;$stale=false;
            if($last && $last['status']==='running' && $now-(int)$last['started']>$s['timeout']+90)$stale=true;
            $fail=$last && in_array($last['status'],['failed','timeout','crashed'],true);
            $queued=(bool)array_filter($state['queue'],static fn($q)=>$q['job']===$id);
            $entries[]=['id'=>$id,'name'=>$job['name'],'group'=>$job['group'],'hint'=>$job['hint'],'source'=>$job['source'],'pulls'=>$job['pulls'],'purpose'=>$job['purpose'],'settings'=>$s,'last'=>$last,'average_seconds'=>$avg,'p95_seconds'=>$p95,'queue'=>$queued,'health'=>!($state['last_tick']??null)?'unmonitored':($stale?'crashed':($queued?'queued':(!$s['enabled']?'disabled':(!$last?'awaiting_first_run':match($last['status']??''){ 'success'=>'healthy','running'=>'running','failed','timeout','crashed'=>'failed',default=>'unmonitored'})))),'history'=>array_slice($runs,0,20)];
        }
        return ['jobs'=>$entries,'last_tick'=>$state['last_tick']??null,'mode'=>'managed','systemd'=>[['name'=>'Venal collection','unit'=>'guristas-venal-collect.timer','control'=>'read-only until verified'],['name'=>'Cache cleanup','unit'=>'guristas-cache-cleanup.timer','control'=>'read-only until verified']]];
    }
}
