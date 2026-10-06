<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/GuristasProgress.php';
require_once dirname(__DIR__) . '/app/services/PilotDataService.php';
function check_effective($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
check_effective(abs(eve_guristas_effective(3.98, [3361 => 5])['effective'] - 5.184) < .000001, 'Positive criminal bonus');
check_effective(abs(eve_guristas_effective(-3, [3357 => 2])['effective'] - -1.96) < .000001, 'Diplomacy blocker repair');
check_effective(eve_guristas_effective(0, [3357 => 5, 3361 => 5])['effective'] === 0.0, 'Zero gets no bonus');
check_effective(eve_guristas_effective(10, [3361 => 5])['effective'] === 10.0, 'Cap');
$levels = GuristasPilotDataService::skills(['skills' => [['skill_id' => 3361, 'active_skill_level' => 2, 'trained_skill_level' => 5]]]);
check_effective($levels[3361] === 2 && $levels[3357] === 0, 'Use active levels, missing skills untrained');
foreach ([null, ['skills' => [['skill_id' => 3361, 'active_skill_level' => 6]]]] as $bad) {
 try { GuristasPilotDataService::skills($bad); throw new LogicException('Malformed skills accepted'); } catch (RuntimeException $e) { if ($e instanceof LogicException) throw $e; }
}
$required = eve_requested_scopes();
check_effective(count($required) === 3 && in_array('esi-skills.read_skills.v1', $required, true), 'Required login scopes');
foreach ($required as $missing) {
 try { eve_validate_feature_consent(['scopes' => $required], ['scp' => array_values(array_diff($required, [$missing])), 'character_id' => 1]); throw new LogicException('Partial consent accepted'); } catch (RuntimeException $e) { if ($e instanceof LogicException) throw $e; }
}
$fixtureCatalog = [500010 => ['name' => 'Guristas Pirates'], 1000127 => ['kind' => 'npc_corp', 'faction_id' => 500010, 'name' => 'Guristas'], 99 => ['kind' => 'agent', 'name' => 'Test agent', 'faction_id' => 500010, 'agent_type_id' => 2, 'location_name' => 'Test base', 'agent_level' => 4, 'corporation_id' => 1000127]];
$fixtureRows = [['from_id' => 500010, 'from_type' => 'faction', 'standing' => 3.98], ['from_id' => 1000127, 'from_type' => 'npc_corp', 'standing' => 0.0], ['from_id' => 99, 'from_type' => 'agent', 'standing' => 0.0]];
check_effective(eve_guristas_progress($fixtureRows, $fixtureCatalog, [3361 => 5])['opportunities'][0]['state'] === 'eligible', 'Skills unlock ordinary level four requirements');
$fixtureRows[1]['standing'] = -2.0;
check_effective(eve_guristas_progress($fixtureRows, $fixtureCatalog, [3361 => 5])['opportunities'][0]['state'] === 'check_blocker', 'Exact minus two blocks');
$fixtureRows[1]['standing'] = 0.0; $fixtureRows[0]['standing'] = 4.99999;
check_effective(eve_guristas_progress($fixtureRows, $fixtureCatalog, [])['opportunities'][0]['state'] === 'working_toward', 'Do not round before access comparison');
$catalog = eve_pilot_entity_catalog();
$rows = [['from_id' => 500010, 'from_type' => 'faction', 'standing' => 3.98], ['from_id' => 1000127, 'from_type' => 'npc_corp', 'standing' => -3.0]];
$result = eve_guristas_progress($rows, $catalog, [3357 => 2, 3361 => 5]);
foreach ($result['opportunities'] as $a) if ($a['agent_level'] > 1) check_effective($a['state'] !== 'check_blocker', 'Diplomacy clears blocker');
echo "PASS: active skills, effective values, zero, malformed data, required login consent and Diplomacy blockers.\n";
