<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/PilotDataService.php';
require_once dirname(__DIR__) . '/app/services/PilotHullGoal.php';
require_once dirname(__DIR__) . '/app/services/PilotInsurgency.php';
function recommend_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$reference = eve_readiness_reference();
$skills = array_fill_keys(array_keys($reference['types']), 5);
$pilot = ['skills' => ['state' => 'ready', 'data' => $skills], 'assets' => ['state' => 'ready', 'data' => []],
    'location' => ['state' => 'ready', 'data' => ['solar_system_id' => 30000142, 'station_id' => 60003760]],
    'ship' => ['state' => 'ready', 'data' => ['ship_type_id' => 17930]], 'combat' => ['data' => ['kills' => []]]];
recommend_check(GuristasPilotDataService::skills(['skills'=>[['skill_id'=>3330,'active_skill_level'=>3]]])[3330] === 3, 'Full active skills retained');
$hulls = eve_pilot_hull_readiness($pilot);
recommend_check($hulls[17930]['can_fly'] === true && $hulls[17930]['current'] && $hulls[17930]['local'], 'Current hull skill and availability evidence');
$untrained = $pilot; $untrained['skills']['data'][3330] = 0;
recommend_check(eve_pilot_hull_readiness($untrained)[17930]['can_fly'] === false, 'Cannot board without hull prerequisites');
$untrained['skills']['data'][3328] = 0;
recommend_check(eve_guristas_hull_goal($untrained)['id'] !== 17930, 'Current hull does not override active training restrictions');
$unknown = $pilot; $unknown['skills']['state'] = 'stale';
recommend_check(eve_guristas_hull_goal($unknown)['id'] === null, 'Stale skills cannot assert combat readiness');
$weak = $pilot; $weak['skills']['data'][3436] = 1;
recommend_check(eve_guristas_hull_goal($weak)['id'] === null, 'Support baseline distinct from boarding ability');
$agents = [
 ['id'=>1,'name'=>'Close','state'=>'eligible','division_name'=>'Security','agent_level'=>2,'system_id'=>10],
 ['id'=>2,'name'=>'Distant','state'=>'eligible','division_name'=>'Security','agent_level'=>4,'system_id'=>20],
 ['id'=>3,'name'=>'Group','state'=>'eligible','division_name'=>'Security','agent_level'=>5,'system_id'=>10],
 ['id'=>4,'name'=>'Fallback','state'=>'level_one','division_name'=>'Security','agent_level'=>1,'system_id'=>10],
 ['id'=>5,'name'=>'Incomplete','state'=>'incomplete','division_name'=>'Security','agent_level'=>4,'system_id'=>10],
];
$pilot['travel']['agent_jumps'] = [10=>1,20=>20];
$chosen = eve_choose_mission_agent($agents, $pilot, []);
recommend_check($chosen['id'] === 1 && $chosen['readiness_hull']['name'] === 'Worm', 'Available suitable hull and close agent beat standing-only higher level');
$weak['travel'] = $pilot['travel'];
recommend_check(eve_choose_mission_agent($agents, $weak, [])['id'] === 4, 'Weak support skills fall back to level one');
$pilot['combat']['data']['kills'] = array_fill(0,4,['hull_id'=>17930]);
$pilot['ship']['state'] = 'unavailable';
$pilot['assets']['data'] = [['type_id'=>17930,'location_id'=>60003760,'location_type'=>'station'],['type_id'=>78367,'location_id'=>60003760,'location_type'=>'station']];
$goal = eve_guristas_hull_goal($pilot);
recommend_check($goal['id'] === 78367 && $goal['first_blood'], 'Equal availability prefers unfinished hull milestone over most kills');
$pilot['combat']['data']['kills'] = array_fill(0,9,['hull_id'=>17930]);
$goal = eve_guristas_hull_goal($pilot);
recommend_check($goal['id'] === 17930 && !$goal['first_blood'] && $goal['remaining'] === 1, 'Near overall milestone retains smaller available hull');
$pilot['assets']['data'] = [];$pilot['assets']['state'] = 'unavailable';
recommend_check(eve_pilot_hull_readiness($pilot)[17930]['owned'] === null, 'Unknown ownership is not false');
$rows = [
 ['type_id'=>17930,'quantity'=>1,'location_id'=>60003760,'location_type'=>'station','location_flag'=>'Hangar'],
 ['type_id'=>78367,'quantity'=>1,'location_id'=>99,'location_type'=>'item','location_flag'=>'FrigateEscapeBay'],
 ['type_id'=>17715,'quantity'=>1,'location_id'=>99,'location_type'=>'other','location_flag'=>'AssetSafety'],
 ['type_id'=>17930,'quantity'=>1,'location_id'=>60003760,'location_type'=>'station','location_flag'=>'Hangar','is_blueprint_copy'=>true],
];
recommend_check(count(eve_normalize_pilot_context('assets',$rows)) === 1, 'Nested, asset safety and copy records do not imply accessible hulls');
$dir = sys_get_temp_dir() . '/guristas-recommend-' . bin2hex(random_bytes(8));
$config = require dirname(__DIR__) . '/config/esi.php'; $calls = []; $denied = false; $assetStatus = 200;
$transport = static function ($url,$headers) use (&$calls,&$assetStatus,$rows) {
    $calls[] = [$url,$headers]; $pages = 1; $status = 200;
    if (str_contains($url,'/assets/')) { $data = str_contains($url,'page=2') ? [$rows[0]] : []; $pages=2; if(str_contains($url,'page=2')) $status=$assetStatus; }
    elseif (str_contains($url,'/location/')) $data=['solar_system_id'=>30000142];
    elseif (str_contains($url,'/ship/')) $data=['ship_type_id'=>17930];
    else { $parts = explode('/route/',$url); $end=explode('/',$parts[1]);$data=[(int)$end[0],(int)$end[1]]; }
    return ['status'=>$status,'headers'=>['x-pages'=>(string)$pages,'cache-control'=>'max-age=300'],'body'=>json_encode($data)];
};
$client = new GuristasEsiClient($config,new GuristasEsiCache($dir),$transport);
$authorize = static function ($id,$scope) use (&$denied) { if($denied)throw new GuristasAuthorizationRequired('Denied');return ['token'=>'private-test-secret','identity'=>'fixture|'.$id]; };
try {
    $context = eve_read_pilot_context($client,101,$authorize);
    recommend_check($context['assets']['state'] === 'ready' && count($context['assets']['data']) === 1 && count($calls) === 4, 'All inventory pages scanned');
    $denied=true;$before=count($calls);$context=eve_read_pilot_context($client,101,$authorize);
    recommend_check($context['assets']['data'] === null && $context['location']['data'] === null && count($calls) === $before, 'Revoked scope hides fresh cached context');
    $denied=false;$assetStatus=403;$context=eve_read_pilot_context($client,202,$authorize);
    recommend_check($context['assets']['state'] === 'authorization_required' && $context['assets']['data'] === null, 'Failed later asset page never becomes partial ownership evidence');
    $travel = eve_pilot_travel($pilot,[30000142,30000144],$client);
    recommend_check($travel[30000142] === 0 && $travel[30000144] === 1, 'Route distances from reported location');
    $last=end($calls);recommend_check(!str_contains(implode(' ',$last[1]),'private-test-secret'), 'Public route carries no bearer');
    $pilot['location']['state']='stale';$before=count($calls);
    recommend_check(eve_pilot_travel($pilot,[30000144],$client) === [] && count($calls) === $before, 'Stale location does not trigger travel ranking');
    echo "PASS: active training, support baselines, mission fallback, accessible hull milestones, complete assets, revoked consent and public travel isolation.\n";
} finally { foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir); }
