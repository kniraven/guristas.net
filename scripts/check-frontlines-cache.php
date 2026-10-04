<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/app/services/EsiCache.php';
require_once dirname(__DIR__).'/app/services/FrontlinesService.php';
$directory=sys_get_temp_dir().'/guristas-feed-check-'.bin2hex(random_bytes(6));
try {
    $cache=new GuristasEsiCache($directory);
    $reflection=new ReflectionClass(GuristasFrontlinesService::class);
    $service=$reflection->newInstanceWithoutConstructor();
    $property=$reflection->getProperty('webCache');
    $property->setAccessible(true);
    $property->setValue($service,$cache);
    $method=$reflection->getMethod('campaignResponse');
    $method->setAccessible(true);
    $now=1700000000;
    $entry=['data'=>[],'fetched_at'=>$now-600,'expires_at'=>$now-300];
    $cache->write('test',$entry);
    foreach ([[503,false],[200,'not json'],[200,'{}'],[200,'[{"solarsystemID":1}]']] as [$status,$body]) {
        $result=$method->invoke($service,'test',$entry,$now,$status,$body,'',[]);
        if ($result['meta']['cache']!=='STALE' || !$result['meta']['stale'] || empty($result['meta']['warning'])) throw new RuntimeException('Failure must expose stale status and warning.');
        if ($result['meta']['retrieved_at']!==gmdate('c',$entry['fetched_at']) || $cache->read('test')!==$entry) throw new RuntimeException('Failure changed last success or overwrote cache.');
    }
    foreach ([null,array_replace($entry,['expires_at'=>$now-3601])] as $unusable) {
        try { $method->invoke($service,'test',$unusable,$now,503,false,'',[]); }
        catch (RuntimeException $expected) { continue; }
        throw new RuntimeException('Missing or expired stale cache must fail.');
    }
    $empty=$method->invoke($service,'empty',null,$now,200,'[]','',['cache-control'=>'max-age=120']);
    if ($empty['data']!==[] || $empty['meta']['stale'] || $cache->read('empty')['expires_at']!==$now+120) throw new RuntimeException('Valid empty feed or cache TTL changed.');
    $revalidated=$method->invoke($service,'test',$entry,$now,304,'','',['cache-control'=>'max-age=180']);
    if ($revalidated['meta']['cache']!=='REVALIDATED' || $revalidated['meta']['stale'] || $cache->read('test')['expires_at']!==$now+180) throw new RuntimeException('Conditional revalidation failed.');
    echo "Frontlines cache checks passed: HTTP failures, malformed and unsupported JSON, stale limits, preserved cache, empty feeds, TTL and revalidation.\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage().PHP_EOL); exit(1); }
finally { foreach (glob($directory.'/*') ?: [] as $file) unlink($file); if (is_dir($directory)) rmdir($directory); }
