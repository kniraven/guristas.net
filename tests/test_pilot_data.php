<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/services/PilotDataService.php';
function verify(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); }
function throws(callable $action, string $label): void { try {$action();} catch(Throwable $e) {return;} throw new RuntimeException($label); }
$dir = sys_get_temp_dir() . '/guristas-pilot-test-' . bin2hex(random_bytes(8));
$config = require dirname(__DIR__) . '/config/esi.php';
$config['default_cache_ttl_seconds'] = 300;
$cache = new GuristasEsiCache($dir);
$calls = [];
$status = 200;
$headers = ['cache-control' => 'max-age=3600', 'etag' => '"fixture"'];
$fw = ['kills' => ['yesterday'=>0,'last_week'=>2,'total'=>7], 'victory_points' => ['yesterday'=>0,'last_week'=>8,'total'=>50]];
$transport = function(string $url, array $requestHeaders) use (&$calls, &$status, &$headers, &$fw): array {
    $calls[] = [$url,$requestHeaders];
    $body = str_contains($url, '/fw/stats/') ? $fw : [['from_id'=>500010,'from_type'=>'faction','standing'=>3.48]];
    if (str_contains($url, '/skills/')) $body = ['skills' => [['skill_id' => 3361, 'active_skill_level' => 4]]];
    return ['status'=>$status, 'headers'=>$headers, 'body'=>json_encode($body)];
};
$client = new GuristasEsiClient($config,$cache,$transport);
$authorizeCalls = [];
$missing = [];
$authorize = function(int $id,string $scope) use (&$authorizeCalls,&$missing): array {
    $authorizeCalls[] = [$id,$scope];
    if (in_array($scope,$missing,true)) throw new GuristasAuthorizationRequired('Missing fixture scope');
    return ['token'=>'fixture-secret-'.$id,'identity'=>'local|grant-'.$id];
};
$service = new GuristasPilotDataService($client,$authorize);
try {
    verify(count(eve_requested_scopes()) === 7, 'Login requires account scopes');
    verify(eve_requested_scopes('standings') === eve_requested_scopes(), 'Feature scope selection');
    verify(count(eve_requested_scopes('fw',['esi-characters.read_standings.v1','esi-killmails.read_killmails.v1'])) === 7, 'Keep enabled grants only');
    throws(fn()=>eve_requested_scopes('unknown'), 'Reject unknown feature');
    throws(fn()=>eve_validate_feature_consent(['scopes'=>[],'character_id'=>null], ['character_id'=>101]), 'Identity-only consent denied');
    throws(fn()=>eve_validate_feature_consent(['scopes'=>['esi-characters.read_standings.v1'],'character_id'=>101], ['character_id'=>202,'scp'=>['esi-characters.read_standings.v1']]), 'Wrong character feature consent denied');
    throws(fn()=>eve_validate_feature_consent(['scopes'=>['esi-characters.read_standings.v1'],'character_id'=>101], ['character_id'=>101,'scp'=>[]]), 'Missing consent scope denied');
    $a=$service->read(101);verify($a['standings']['state']==='ready' && $a['fw']['data']['enlisted']===false,'Valid standings and not enlisted');
    verify($a['fw']['data']['current_rank']===null && $a['fw']['data']['kills']['yesterday']===0,'Missing field is not zero; real zero preserved');
    verify(count($calls)===3,'Independent initial requests');
    $service->read(101);verify(count($calls)===3 && count($authorizeCalls)===6,'Authorize before fresh cache reads');
    $service->read(202);verify(count($calls)===6,'Characters must not share private cache');
    $client->getCharacterJson(101,'standings','fixture-secret-101','production|grant-101');verify(count($calls)===7,'Environments isolate cache');
    $client->getCharacterJson(101,'standings','fixture-secret-101','local|new-grant-101');verify(count($calls)===8,'Changed grant isolates cache');
    $client->getJson('/characters/101/standings');verify(count($calls)===9,'Public cache separate');
    verify(!str_contains(implode('\n',$calls[8][1]),'Authorization:'),'Public request has no bearer');
    foreach(glob($dir.'/*.json') as $file){ verify(!str_contains(file_get_contents($file),'fixture-secret'),'Token not persisted'); if(PHP_OS_FAMILY!=='Windows')verify((fileperms($file)&0777)===0640,'Private cache permissions'); }
    $missing=['esi-characters.read_standings.v1'];$a=$service->read(101);verify($a['standings']['state']==='authorization_required' && $a['standings']['data']===null,'Missing scope denies cached data');$missing=[];
    // Expire entries without sleeping; retain last valid data for a bounded stale window.
    foreach(glob($dir.'/*.json') as $file){$entry=json_decode(file_get_contents($file),true);$entry['expires_at']=time()-10;file_put_contents($file,json_encode($entry));}
    $status=503;$a=$service->read(101);verify($a['standings']['state']==='stale','Transient service errors serve labeled stale data');
    $status=403;$a=$service->read(101);verify($a['standings']['state']==='authorization_required' && $a['standings']['data']===null,'Rejected authorization never serves stale data');
    $status=503;$a=$service->read(101);verify($a['standings']['state']==='unavailable','Rejected cache was purged');
    $status=200;$headers=['cache-control'=>'max-age=3600'];$a=$service->read(101);verify($a['standings']['state']==='ready','Reconnect can retrieve data');
    foreach(glob($dir.'/*.json') as $file){$entry=json_decode(file_get_contents($file),true);$entry['expires_at']=time()-10;file_put_contents($file,json_encode($entry));}
    $status=304;$a=$service->read(101);verify($a['standings']['meta']['cache']==='REVALIDATED','Conditional cache revalidation');
    foreach(glob($dir.'/*.json') as $file){$entry=json_decode(file_get_contents($file),true);$entry['expires_at']=time()-10;file_put_contents($file,json_encode($entry));}
    $status=429;$headers=['retry-after'=>'60'];$a=$service->read(101);$count=count($calls);$b=$service->read(101);verify($a['standings']['state']==='stale' && count($calls)===$count,'Rate limit backs off without repeated HTTP');
    $a=$service->read(303);$count=count($calls);$service->read(303);verify($a['standings']['state']==='rate_limited' && count($calls)===$count,'Rate limited with no prior data');
    throws(fn()=>GuristasPilotDataService::standings(['error'=>'not standings']),'Reject malformed standings');
    throws(fn()=>GuristasPilotDataService::factionWarfare([]),'Reject missing metrics');
    verify(GuristasPilotDataService::standings([])===[],'Empty standings valid');
    $enlisted=$fw+['faction_id'=>500010,'current_rank'=>0,'enlisted_on'=>'2026-10-01T00:00:00Z'];verify(GuristasPilotDataService::factionWarfare($enlisted)['enlisted']===true,'Enlisted fixture');
    throws(fn()=>$client->getCharacterJson(0,'standings','a','id'),'Invalid character rejected');
    throws(fn()=>$client->getCharacterJson(101,'wallet','a','id'),'Unexpected private route rejected');
    throws(fn()=>$client->getCharacterJson(101,'standings',"a\r\nInjected: yes",'id'),'Header injection rejected');
    $bad=$config;$bad['base_url']='https://example.invalid';throws(fn()=>(new GuristasEsiClient($bad,$cache,$transport))->getCharacterJson(101,'standings','a','id'),'Bearer cannot leave ESI origin');
    echo "PASS: feature scopes, character/environment/grant isolation, authorization-before-cache, private files, stale data, rejected-auth purge, revalidation, rate-limit backoff, malformed/empty responses and zero values.\n";
} finally {
    foreach(glob($dir.'/*') as $file)unlink($file);
    rmdir($dir);
}
