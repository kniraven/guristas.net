<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/GuristasProgress.php';
$catalog = eve_pilot_entity_catalog();
$rows = [
 ['from_id' => 500010, 'from_type' => 'faction', 'name' => 'Guristas Pirates', 'standing' => 3.98],
 ['from_id' => 1000127, 'from_type' => 'npc_corp', 'name' => 'Guristas', 'standing' => 6.35],
 ['from_id' => 500001, 'from_type' => 'faction', 'name' => 'Caldari State', 'standing' => 10.0],
];
$result = eve_guristas_progress($rows, $catalog);
if (count($result['tracks']) !== 2 || count($result['relationships']) !== 2 || $result['faction_standing'] !== 3.98) throw new RuntimeException('Enemy progression leaked.');
if ($result['opportunities'] === []) throw new RuntimeException('Missing opportunities.');
foreach ($result['opportunities'] as $agent) {
 if ($agent['faction_id'] !== 500010 || $agent['agent_type_id'] !== 2 || $agent['agent_level'] > 4) throw new RuntimeException('Wrong agent recommended.');
 if ($agent['agent_level'] > 1 && $agent['state'] !== 'threshold_met') throw new RuntimeException('Threshold calculation failed.');
}
$blocked = $rows; $blocked[0]['standing'] = -2.0;
foreach (eve_guristas_progress($blocked, $catalog)['opportunities'] as $agent) {
 if ($agent['agent_level'] > 1 && $agent['state'] !== 'check_blocker') throw new RuntimeException('Blocking threshold missed.');
}
$empty = eve_guristas_progress([], $catalog);
if ($empty['faction_standing'] !== null || $empty['tracks'] !== []) throw new RuntimeException('Missing standing inferred.');
foreach ($empty['opportunities'] as $agent) {
 if ($agent['agent_level'] > 1 && $agent['state'] !== 'unknown') throw new RuntimeException('Unknown access inferred.');
}
$agents = [];
foreach ($catalog as $id => $entry) {
 if (($entry['kind'] ?? '') === 'agent' && ($entry['faction_id'] ?? 0) === 500010) {
  $agents[] = ['from_id' => (int)$id, 'from_type' => 'agent', 'standing' => 1.0];
  if (count($agents) === 3) break;
 }
}
if (eve_guristas_progress($agents, $catalog)['connections'] !== 3) throw new RuntimeException('Connections not measured.');
echo "PASS: Guristas-only progression, ordinary-agent recommendations, real mission thresholds, -2 boundary, missing standings and contact counts.\n";
