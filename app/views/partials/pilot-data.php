<?php
// Minimal account integration for GURI-002; full Pilot Record belongs to GURI-001.
$accountView = $accountView ?? 'overview';
$factionNames = ($pilotData['entity_names'] ?? []) + [500001 => 'Caldari State', 500002 => 'Minmatar Republic', 500003 => 'Amarr Empire', 500004 => 'Gallente Federation', 500010 => 'Guristas Pirates', 500011 => 'Angel Cartel'];
$stateMessages = [
    'authorization_required' => 'Sign in again to authorize the required account permissions.',
    'unavailable' => 'EVE data is temporarily unavailable. Your standings or membership have not been inferred from this error.',
    'rate_limited' => 'EVE is limiting requests. Please try again later.',
    'stale' => 'Showing previously retrieved data while EVE is unavailable. This may be out of date.',
];
?>
<div class="pilot-panels">
<?php if ($accountView === 'combat') require __DIR__ . '/pilot-combat.php'; ?>
<?php if ($accountView === 'romance') require __DIR__ . '/pilot-romance.php'; ?>
<?php foreach (['standings' => 'Guristas progression', 'fw' => 'Faction warfare record'] as $feature => $label): ?>
<?php if (($feature === 'fw' && $accountView !== 'fw') || ($feature === 'standings' && !in_array($accountView, ['overview', 'standings', 'achievements'], true))) continue; ?>
<?php $section = $pilotData[$feature]; $state = $section['state']; $data = $section['data'] ?? null; ?>
<section class="account-panel" aria-labelledby="pilot-<?= eve_e($feature) ?>">
<h2 class="<?= $feature === 'standings' ? 'visually-hidden' : '' ?>" id="pilot-<?= eve_e($feature) ?>"><?= eve_e($label) ?></h2>
<?php if ($state !== 'ready'): ?><p role="status"><?= eve_e($stateMessages[$state] ?? $stateMessages['unavailable']) ?></p><?php endif; ?>
<?php if ($state === 'authorization_required'): ?>
<p>Permission: <code><?= eve_e(eve_feature_scopes($feature)[0]) ?></code>. Select <?= eve_e($viewer['character_name']) ?> at CCP to connect this account.</p>
<form method="post" action="/auth/start.php"><input type="hidden" name="csrf" value="<?= eve_e(eve_csrf()) ?>"><button type="submit" name="feature" value="<?= eve_e($feature) ?>">Connect <?= eve_e($feature === 'standings' ? 'personal standings' : strtolower($label)) ?></button></form>
<?php endif; ?>
<?php if ($feature === 'standings') require __DIR__ . '/guristas-progress.php'; ?>
<?php if ($data !== null && in_array($state, ['ready', 'stale'], true)): ?>
<?php if ($feature === 'standings'): ?>

<?php if ($data === []): ?><p>No standings entries were returned by EVE.</p><?php else: ?>

<?php if ($accountView === 'standings'): ?><details open class="dossier-details" id="standings-explorer"><summary>Explore all standings · names, affiliations &amp; bases</summary><h3>Standings intelligence</h3><p class="note">Guristas relationships first. Other relationships are available for reference, without progression goals.</p>
<div class="pilot-controls" data-pilot-controls hidden>
<label class="pilot-search">Search <input type="search" data-pilot-search placeholder="Name, ID, corporation, faction or base" autocomplete="off"></label>
<label>Allegiance <select data-pilot-allegiance><option value="guristas">Guristas only</option><option value="all">All relationships</option><option value="enemy">Caldari / Gallente intelligence</option></select></label>
<label>Relationship <select data-pilot-relation><option value="all">All standings</option><option value="positive">Positive only</option><option value="negative">Negative only</option><option value="neutral">Exactly zero</option></select></label>
<label>Show <select data-pilot-filter><option value="all">All entities</option><option value="faction">Factions</option><option value="npc_corp">NPC corporations</option><option value="agent">Agents</option></select></label>
<label>Sort by <select data-pilot-sort><option value="standing-desc">Highest standing first</option><option value="standing-asc">Lowest standing first</option><option value="name">Name A–Z</option><option value="id-asc">ID: lowest first</option><option value="id-desc">ID: highest first</option></select></label>
<div class="pilot-control-actions"><button type="button" data-pilot-reset>Reset filters</button><button type="button" data-pilot-expand>Expand all groups</button><button type="button" data-pilot-collapse>Collapse all groups</button></div>
</div>
<p class="note" data-pilot-results role="status" aria-live="polite"></p>
<p class="note" data-pilot-sort-note>Grouped by entity type; highest standing first within each group. Agent affiliations and bases use CCP’s static data, retrieved October 6, 2026.</p>
<?php foreach (['faction' => 'Factions', 'npc_corp' => 'NPC corporations', 'agent' => 'Agents'] as $type => $typeLabel): ?>
<?php $rows = array_values(array_filter($data, static function ($row) use ($type) { return $row['from_type'] === $type; }));
usort($rows, static function ($a, $b) { return ($b['standing'] <=> $a['standing']) ?: strcasecmp($a['name'] ?? '', $b['name'] ?? ''); }); ?>
<?php if ($rows !== []): ?><details class="pilot-standing-group" data-pilot-group="<?= eve_e($type) ?>" <?= $type === 'faction' ? 'open' : '' ?>>
<summary><?= eve_e($typeLabel) ?> <span data-pilot-count><?= count($rows) ?></span></summary>
<div class="pilot-table-wrap"><table class="pilot-table"><caption class="visually-hidden"><?= eve_e($typeLabel) ?> standings</caption><thead><tr><th scope="col">Entity</th><th scope="col">Standing</th></tr></thead><tbody>
<?php foreach ($rows as $row): $isGuristas = eve_is_guristas_entity($row, eve_pilot_entity_catalog()); $isEnemy = ($row['from_type'] === 'faction' && in_array($row['from_id'], [500001, 500004], true)) || in_array($row['faction_id'] ?? null, [500001, 500004], true); $name = $row['name'] ?? $factionNames[$row['from_id']] ?? ('EVE ID ' . $row['from_id']); ?>
<tr data-allegiance="<?= $isGuristas ? 'guristas' : ($isEnemy ? 'enemy' : 'other') ?>" data-search="<?= eve_e(implode(' ', [$name, (string)$row['from_id'], $row['corporation_name'] ?? '', $row['faction_name'] ?? '', $row['location_name'] ?? '', $row['system_name'] ?? '', $row['division_name'] ?? ''])) ?>" data-id="<?= (int)$row['from_id'] ?>" data-name="<?= eve_e($name) ?>" data-standing="<?= eve_e((string)$row['standing']) ?>"><th scope="row"><div class="pilot-entity"><img class="pilot-entity-icon" src="https://images.evetech.net/<?= $type === 'agent' ? 'characters' : 'corporations' ?>/<?= (int)$row['from_id'] ?>/<?= $type === 'agent' ? 'portrait' : 'logo' ?>?size=64" alt="" width="40" height="40" loading="lazy" referrerpolicy="no-referrer"><div><?= eve_e($name) ?><small>ID <?= (int)$row['from_id'] ?></small>
<?php if ($isEnemy && $row['from_type'] === 'faction' && $row['standing'] > 0): ?><small class="pilot-rp-note">Empire-approved. A regrettable distinction. <span>(Guristas propaganda)</span></small><?php endif; ?>
<?php if (!empty($row['corporation_name'])): ?><small><?= eve_e($row['corporation_name']) ?></small><?php endif; ?>
<?php if (!empty($row['faction_name'])): ?><small><?= eve_e($row['faction_name']) ?></small><?php endif; ?>
<?php if (!empty($row['agent_level'])): ?><small>Level <?= (int)$row['agent_level'] ?><?= !empty($row['division_name']) ? ' · ' . eve_e($row['division_name']) : '' ?></small><?php endif; ?>
<?php if ($isGuristas && $type === 'agent'): $reference = eve_pilot_entity_catalog()[$row['from_id']] ?? []; ?>
<?php if (($reference['agent_type_id'] ?? null) === 2): $requirement = [1 => -10, 2 => 1, 3 => 3, 4 => 5, 5 => 7][$reference['agent_level']]; ?>
<small><?= $reference['agent_level'] === 1 ? 'Ordinary level 1 mission access: no positive standing required.' : 'This agent: +' . $requirement . ' effective agent/corporation/faction standing required; check all restrictions in EVE.' ?></small>
<?php else: ?><small>Special agent: confirm individual requirements in EVE.</small><?php endif; ?>
<?php if ($row['standing'] >= 1): ?><small>✓ Established contact · Guristas.net recognition</small><?php endif; ?>
<?php endif; ?>
<?php if (!empty($row['location_name'])): ?><small>Recorded base: <?= eve_e($row['location_name']) ?></small><?php endif; ?>
</div></div></th><td><span class="pilot-standing <?= $row['standing'] > 0 ? 'positive' : ($row['standing'] < 0 ? 'negative' : 'neutral') ?>"><?= eve_e(($row['standing'] > 0 ? '+' : '') . number_format($row['standing'], 2)) ?></span></td></tr>
<?php endforeach; ?></tbody></table></div></details><?php endif; ?>
<?php endforeach; ?>
</details><?php endif; ?>
<?php endif; ?>
<?php else: ?>
<p class="pilot-militia"><?= $data['enlisted'] ? 'Reported militia: ' . eve_e($factionNames[$data['faction_id']] ?? ('Faction ID ' . $data['faction_id'])) : 'EVE did not report a current militia enlistment.' ?></p>
<p class="note">Your reported FW career, with current militia status. Totals may include previous service in other militias.</p>
<div class="pilot-summary fw-career-summary"><?php foreach (['total' => 'Career total', 'last_week' => 'Last week', 'yesterday' => 'Yesterday'] as $period => $periodLabel): ?><article><h3><?= $periodLabel ?></h3><strong><?= number_format($data['kills'][$period]) ?></strong><span>FW kills</span><strong><?= number_format($data['victory_points'][$period]) ?></strong><span>Victory points</span></article><?php endforeach; ?></div>
<p class="note">Enlisted since <?= eve_e($data['enlisted_on'] ?? 'Not reported') ?> · current rank <?= isset($data['current_rank']) ? (int)$data['current_rank'] : 'Not reported' ?> · highest rank <?= isset($data['highest_rank']) ? (int)$data['highest_rank'] : 'Not reported' ?>.</p>
<aside class="dossier-guide-link"><div><strong>Open campaign intelligence</strong><p>Current systems, campaign rules and participation guidance are in the public War Room. Your enlistment, career statistics and achievements stay in this dossier.</p><a href="/war/guristas/">Open War Room →</a></div></aside>
<p class="note">Personal insurgency contribution is not reported by ESI. Check your contribution meter in EVE. Personalized campaign actions are in Overview.</p>
<?php $achievementScope = 'fw'; require __DIR__ . '/pilot-achievements.php'; ?>
<details class="dossier-details"><summary>Enlistment details &amp; full statistics</summary><dl><dt>Enlisted on</dt><dd><?= eve_e($data['enlisted_on'] ?? 'Not reported') ?></dd><dt>Current rank</dt><dd><?= eve_e(isset($data['current_rank']) ? (string)$data['current_rank'] : 'Not reported') ?></dd><dt>Highest rank</dt><dd><?= eve_e(isset($data['highest_rank']) ? (string)$data['highest_rank'] : 'Not reported') ?></dd></dl>
<div class="pilot-table-wrap"><table class="pilot-table"><caption>FW statistics reported by EVE</caption><thead><tr><th scope="col">Metric</th><th scope="col">Yesterday</th><th scope="col">Last week</th><th scope="col">Total</th></tr></thead><tbody>
<?php foreach (['kills' => 'FW kills', 'victory_points' => 'Victory points'] as $metric => $metricLabel): ?><tr><th scope="row"><?= eve_e($metricLabel) ?></th><?php foreach (['yesterday', 'last_week', 'total'] as $period): ?><td><?= number_format($data[$metric][$period]) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><p class="note">ESI updates these statistics on its daily schedule. They are not live killmail totals or loyalty points.</p></details>
<?php endif; ?>
<?php endif; ?>
<?php if (in_array($state, ['ready', 'stale'], true)): ?><details class="pilot-freshness"><summary><?= $state === 'stale' ? 'Previously retrieved data' : 'Data retrieved from EVE' ?> · update details</summary><p class="note">Retrieved: <time><?= eve_e($section['meta']['fetched_at'] ?? 'Unknown') ?></time><br>Cache expires: <time><?= eve_e($section['meta']['expires_at'] ?? 'Unknown') ?></time></p></details><?php endif; ?>
</section>
<?php endforeach; ?>
</div>
