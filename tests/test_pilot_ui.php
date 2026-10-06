<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/EveAuth.php';
$viewer = ['character_name' => 'Test'];
$pilotData = ['standings' => ['state' => 'ready', 'meta' => [], 'data' => [
    ['from_id' => 500010, 'from_type' => 'faction', 'name' => 'Guristas Pirates', 'standing' => 3.98],
    ['from_id' => 500001, 'from_type' => 'faction', 'name' => 'Caldari State', 'standing' => -1.96],
    ['from_id' => 500004, 'from_type' => 'faction', 'name' => 'Gallente Federation', 'standing' => 2.94],
    ['from_id' => 1000127, 'from_type' => 'npc_corp', 'name' => '<script>alert(1)</script>', 'standing' => 6.35],
    ['from_id' => 3019356, 'from_type' => 'agent', 'name' => 'Sister Alitura', 'standing' => 0.0],
]], 'fw' => ['state' => 'unavailable', 'data' => null, 'meta' => null]];
ob_start();
require dirname(__DIR__) . '/app/views/partials/pilot-data.php';
$html = ob_get_clean();
foreach (['Your Guristas dossier', '1.02 standing to this threshold', 'Level 4 missions', 'data-pilot-search', 'data-pilot-relation', 'data-pilot-reset', 'data-pilot-results', 'ID: lowest first', 'Exactly zero', '&lt;script&gt;alert(1)&lt;/script&gt;', 'Guristas only', 'Empire-approved. A regrettable distinction.'] as $expected) {
    if (strpos($html, $expected) === false) throw new RuntimeException('Missing UI: ' . $expected);
}
if (strpos($html, '<script>alert(1)</script>') !== false) throw new RuntimeException('Unescaped name.');
$pilotData['standings'] = ['state' => 'authorization_required', 'data' => null, 'meta' => null];
// Check missing data without invoking the CSRF-producing authorization form.
$pilotData['standings']['state'] = 'unavailable';
ob_start(); require dirname(__DIR__) . '/app/views/partials/pilot-data.php'; $html = ob_get_clean();
if (strpos($html, 'Your Guristas dossier') !== false || strpos($html, 'pilot-score') !== false) throw new RuntimeException('Missing data produced achievements.');
echo "PASS: standings overview, milestone distances, UI controls, escaped names and no achievements for unavailable data.\n";
