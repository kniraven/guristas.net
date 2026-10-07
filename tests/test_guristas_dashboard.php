<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/GuristasDashboard.php';
require_once dirname(__DIR__) . '/app/services/EveAuth.php';
function dashboard_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$catalog = eve_pilot_entity_catalog();
$pilotData = ['standings' => ['state' => 'ready', 'meta' => [], 'data' => [
    ['from_id' => 500010, 'from_type' => 'faction', 'name' => 'Guristas Pirates', 'standing' => 3.98],
    ['from_id' => 1000127, 'from_type' => 'npc_corp', 'name' => 'Guristas', 'standing' => 6.35],
    ['from_id' => 500001, 'from_type' => 'faction', 'name' => 'Caldari State', 'standing' => 10.0],
]], 'skills' => ['state' => 'ready', 'meta' => [], 'data' => [3357 => 0, 3359 => 0, 3361 => 2]],
'fw' => ['state' => 'ready', 'meta' => [], 'data' => ['faction_id' => 500010]]];
$d = eve_guristas_dashboard($pilotData, $catalog);
dashboard_check($d['enlisted'] && $d['next']['name'] === 'Inner Circle' && abs($d['next']['distance'] - 1.02) < .00001, 'Next raw recognition, not effective');
dashboard_check(abs($d['next']['percent'] - 49) < .00001, 'Progress within current stage');
dashboard_check($d['recommended']['agent_level'] === 1, 'Incomplete higher agents cannot be primary recommendation');
$known = $pilotData; $known['standings']['data'][] = ['from_id' => 3018272, 'from_type' => 'agent', 'standing' => .53];
$known['standings']['data'][] = ['from_id' => 3015756, 'from_type' => 'agent', 'standing' => 5.43];
foreach (array_keys(eve_readiness_reference()['types']) as $skill) $known['skills']['data'][$skill] = 5;
$known['skills']['data'][3361] = 2;
$knownResult = eve_guristas_dashboard($known, $catalog);
dashboard_check($knownResult['recommended']['id'] === 3018272 && $knownResult['opportunities'][0]['id'] === 3018272, 'Evaluate all agents and surface complete level three recommendation');
dashboard_check(count(array_filter($d['badges'], static function ($b) { return $b['earned']; })) === 4, 'Evidence based recognition');
ob_start(); require dirname(__DIR__) . '/app/views/partials/guristas-progress.php'; $html = ob_get_clean();
dashboard_check(strpos($html, 'Check Guristas enlistment in EVE') === false, 'Do not ask enlisted player to enlist');
dashboard_check(strpos($html, 'Why this agent?') !== false && strpos($html, '?view=standings#guristas-agent-options') !== false, 'Transparent recommendation and cross-view link');
dashboard_check(strpos($html, 'guristas-agent-cards') === false, 'Overview omits full agent list');
$accountView = 'standings'; ob_start(); require dirname(__DIR__) . '/app/views/partials/guristas-progress.php'; $html = ob_get_clean();
dashboard_check(strpos($html, 'Agent profile &amp; access details') !== false && strpos($html, 'no biography') !== false, 'Agent profile uses reference facts');
$accountView = 'achievements'; ob_start(); require dirname(__DIR__) . '/app/views/partials/guristas-progress.php'; $html = ob_get_clean();
dashboard_check(strpos($html, 'dossier-badges') !== false && strpos($html, 'dossier-action') === false, 'Dedicated recognition view');
$pilotData['standings']['state'] = 'stale'; $d = eve_guristas_dashboard($pilotData, $catalog);
dashboard_check($d['next'] === null && $d['recommended'] === null && !$d['badges'][0]['earned'], 'Stale standings do not verify goals');
$pilotData['standings']['state'] = 'ready'; $pilotData['skills']['state'] = 'unavailable';
$d = eve_guristas_dashboard($pilotData, $catalog);
dashboard_check(!isset($d['faction']['effective']), 'No invented effective standings');
$empty = eve_guristas_dashboard([], $catalog);
dashboard_check($empty['next'] === null && $empty['faction'] === null && !$empty['enlisted'], 'Empty data has no inferred progression');
$account = file_get_contents(dirname(__DIR__) . '/public/account/index.php');
dashboard_check(strpos($account, "partials/pilot-data.php") < strpos($account, 'id="account-settings"'), 'Dossier precedes preferences');
echo "PASS: dashboard priorities, raw recognition vs effective access, within-stage progress, enlisted guidance, stale/missing data and collapsed detail.\n";

$pilotData['standings']['state'] = 'ready'; $pilotData['skills']['state'] = 'ready';
$pilotData['fw']['data'] += ['kills' => ['total' => 22, 'last_week' => 0, 'yesterday' => 0], 'victory_points' => ['total' => 6092, 'last_week' => 0, 'yesterday' => 0]];
$fwDashboard = eve_guristas_dashboard($pilotData, $catalog);
$combat = array_values(array_filter($fwDashboard['badges'], static function ($b) { return ($b['name'] ?? '') === 'FW combat · 50'; }))[0];
dashboard_check(!$combat['earned'] && $combat['remaining'] === 28, 'FW next combat tier');
$achievementScope = 'fw'; ob_start(); require dirname(__DIR__) . '/app/views/partials/pilot-achievements.php'; $fwHtml = ob_get_clean();
dashboard_check(strpos($fwHtml, '28 to go') !== false && strpos($fwHtml, '3,908 to go') !== false, 'FW progression distances');
$accountView = 'overview'; ob_start(); require dirname(__DIR__) . '/app/views/partials/guristas-progress.php'; $overviewHtml = ob_get_clean();
dashboard_check(strpos($overviewHtml, 'dossier-highlights') !== false && strpos($overviewHtml, 'dossier-badges') === false && strpos($overviewHtml, 'standings#special-missions') !== false, 'Overview achievements and field-guide discovery');
$pilotData['fw']['state'] = 'stale'; $staleFw = eve_guristas_dashboard($pilotData, $catalog);
foreach ($staleFw['badges'] as $badge) if ($badge['section'] === 'fw') dashboard_check(!$badge['earned'], 'Stale FW cannot verify achievement');
echo "PASS: contextual achievements, exact FW goal distances, overview recognition and safe external link.\n";

$accountView = 'standings'; ob_start(); require dirname(__DIR__) . '/app/views/partials/guristas-progress.php'; $sectionHtml = ob_get_clean();
dashboard_check(strpos($sectionHtml, 'dossier-action') === false && strpos($sectionHtml, 'dossier-milestone-list') !== false && strpos($sectionHtml, 'id="special-missions"') !== false && strpos($sectionHtml, 'target="_blank" rel="noopener noreferrer"') !== false, 'Section evidence without action cards');
dashboard_check(strpos($overviewHtml, 'NEXT FW ACTION') !== false && strpos($overviewHtml, 'standings#special-missions') !== false, 'Overview contains FW and epic recommendations');
dashboard_check(count(array_filter($fwDashboard['badges'], static function ($badge) { return ($badge['target'] ?? 0) === 1000000; })) === 1, 'Contribution continues past 10000');
