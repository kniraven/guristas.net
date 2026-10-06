<?php
require_once dirname(__DIR__, 2) . '/services/GuristasProgress.php';
$skillsSection = $pilotData['skills'] ?? ['state' => 'unavailable'];
$activeSkills = ($skillsSection['state'] ?? '') === 'ready' && ($pilotData['standings']['state'] ?? '') === 'ready' ? $skillsSection['data'] : null;
$guristas = eve_guristas_progress($data, eve_pilot_entity_catalog(), $activeSkills);
?>
<div class="guristas-journey">
<h3>Your Guristas dossier</h3>
<?php if ($activeSkills === null): ?><p role="status">Effective standings unavailable: skills or standings are missing or stale. <a href="/auth/start.php">Sign in again to authorize all account permissions</a> if access is missing. Reported values remain visible.</p><?php else: ?><p class="note">Active skills: Diplomacy <?= $activeSkills[3357] ?> · Criminal Connections <?= $activeSkills[3361] ?>. Skills retrieved <?= eve_e($skillsSection['meta']['fetched_at'] ?? 'Unknown') ?>; expires <?= eve_e($skillsSection['meta']['expires_at'] ?? 'Unknown') ?>.</p><?php endif; ?>
<p>Build trust with the Pirates. Find work in Venal. Put that trust to use.</p>
<div class="pilot-summary pilot-fw-summary"><div><strong><?= count($guristas['relationships']) ?></strong><span>Reported Guristas relationships</span></div><div><strong><?= $guristas['connections'] ?></strong><span>Guristas agents at +1 or higher</span></div></div>
<?php if ($guristas['tracks'] === []): ?><p>No Guristas faction or corporation standing was reported. Start by checking ordinary level 1 Guristas agents below; unreported standings are not treated as zero.</p><?php endif; ?>
<div class="pilot-spotlights">
<?php foreach ($guristas['tracks'] as $entry):
$value = $entry['effective'] ?? $entry['standing']; $next = $value < 1 ? 1 : ($value < 3 ? 3 : 5);
$progress = max(0, min(100, $value / $next * 100)); ?>
<article class="pilot-spotlight"><span class="note"><?= $entry['from_type'] === 'faction' ? 'Faction trust' : 'Corporation trust' ?></span><h4><?= eve_e($entry['name'] ?? ('EVE ID ' . $entry['from_id'])) ?></h4><strong class="pilot-score"><?= eve_e(($value > 0 ? '+' : '') . number_format($value, 2)) ?></strong>
<p class="note">Raw <?= number_format($entry['standing'], 2) ?><?php if (isset($entry['effective'])): ?> · Effective <?= number_format($entry['effective'], 2) ?> · <?= eve_e($entry['skill']) ?> <?= $entry['level'] ?><?php endif; ?></p>
<ol class="guristas-unlocks">
<?php foreach ([1 => 2, 3 => 3, 5 => 4] as $threshold => $level): ?>
<li class="<?= $value >= $threshold ? 'reached' : '' ?>"><strong>+<?= $threshold ?> · Level <?= $level ?> missions</strong><span><?= $value >= $threshold ? 'Standing threshold reached' : eve_e(number_format($threshold - $value, 2)) . ' standing to this threshold' ?></span></li>
<?php endforeach; ?></ol>
<progress value="<?= eve_e((string)$progress) ?>" max="100" aria-label="Progress toward +<?= $next ?> reported standing"></progress>
<p class="note">Applies to <?= $entry['from_type'] === 'faction' ? 'qualifying ordinary agents across the Guristas faction' : 'qualifying ordinary agents in this corporation' ?>. Agent-specific blockers are checked in the opportunities below.</p>
</article><?php endforeach; ?></div>
<p class="note">Mission thresholds use the highest effective agent, corporation or faction standing; none of the relevant standings may be −2 or lower. Effective calculations use active skills when both datasets are fresh. Missing relationships prevent a complete eligibility assessment. Special, storyline and epic-arc agents have separate rules. No ordinary Guristas level 5 agents are recorded in this reference.</p>
<div class="guristas-next"><h4>Next orders</h4>
<?php if ($guristas['faction_standing'] === null): ?><p>Check your Guristas faction standing in EVE before assessing enlistment.</p>
<?php elseif ($guristas['faction_standing'] < 0): ?><p>Repair your Guristas faction relationship toward 0.0. Ordinary missions build agent/corporation trust; seek Guristas storyline opportunities for faction progress. Do not mistake corporation standing for faction standing.</p>
<?php else: ?><p>Your reported Guristas faction standing meets the 0.0 enlistment threshold. Check the Insurgencies enlistment window for all remaining requirements, or review your current service below.</p><?php endif; ?>
<p>For mission work, inspect a shortlisted agent in EVE, confirm access, prepare for the agent’s division, and travel deliberately: Venal is null security space. Completing missions for that agent builds the agent and corporation relationships; faction gains require appropriate faction-rewarding activities.</p>
</div>
<div class="guristas-badges" aria-label="Guristas.net recognition">
<?php if (($guristas['faction_standing'] ?? -10) >= 1): ?><span>✓ Known to the Pirates · reported faction +1</span><?php endif; ?>
<?php if (($guristas['faction_standing'] ?? -10) >= 3): ?><span>✓ Trusted by the Pirates · reported faction +3</span><?php endif; ?>
<?php foreach ($guristas['tracks'] as $track): if ($track['from_type'] === 'npc_corp' && $track['standing'] >= 5): ?><span>✓ On the Payroll · <?= eve_e($track['name']) ?> reported +5</span><?php endif; endforeach; ?>
<?php if ($guristas['connections'] >= 3): ?><span>✓ Venal Connections · three agents at +1</span><?php endif; ?>
<?php if (($pilotData['fw']['state'] ?? '') === 'ready' && ($pilotData['fw']['data']['faction_id'] ?? null) === 500010): ?><span>✓ Commando Guri · ESI-reported enlistment</span><?php endif; ?>
</div><p class="note">Titles are Guristas.net recognition of the displayed data, not in-game rewards or permanent achievements. Out-of-date data remains labeled above.</p>
<details class="guristas-opportunities" open><summary>Guristas mission opportunities · ordinary agents</summary>
<div class="guristas-agent-cards">
<?php foreach ($guristas['opportunities'] as $agent): ?>
<article><div class="pilot-entity"><img class="pilot-entity-icon" src="https://images.evetech.net/characters/<?= $agent['id'] ?>/portrait?size=64" alt="" width="40" height="40" loading="lazy" referrerpolicy="no-referrer"><h4><?= eve_e($agent['name']) ?></h4></div>
<p>Level <?= $agent['agent_level'] ?> · <?= eve_e($agent['division_name'] ?? 'Division not recorded') ?><br><?= eve_e($agent['corporation_name']) ?><br>Recorded base: <?= eve_e($agent['location_name']) ?></p>
<p class="note"><?php if ($agent['state'] === 'level_one'): ?>Ordinary level 1 mission access generally requires no positive standing.
<?php elseif ($agent['state'] === 'eligible'): ?>Calculated standing requirements met for this ordinary agent. Check availability and other restrictions in EVE.
<?php elseif ($agent['state'] === 'incomplete'): ?>A calculated relationship meets the threshold, but another relationship was not reported. Access cannot be fully assessed.
<?php elseif ($agent['state'] === 'threshold_met'): ?>Reported +<?= $agent['threshold'] ?> threshold reached. Confirm effective standings and agent access in EVE.
<?php elseif ($agent['state'] === 'check_blocker'): ?>A relevant standing is −2 or lower<?= $agent['calculated'] ? ' after active skill adjustments' : ' before skill adjustments' ?>. This blocks higher-level access when effective.
<?php elseif ($agent['state'] === 'unknown'): ?>Relevant standings were not reported. Check agent access in EVE.
<?php else: ?>Work toward +<?= $agent['threshold'] ?> effective agent, corporation or faction standing. Skill adjustments may already change your access.
<?php endif; ?></p><ul class="note"><?php foreach ($agent['evidence'] as $evidence): ?><li><?= eve_e($evidence['name']) ?>: raw <?= number_format($evidence['raw'], 2) ?> → <?= $agent['calculated'] ? 'effective' : 'reported' ?> <?= number_format($evidence['effective'], 2) ?> (<?= eve_e($evidence['skill']) ?> <?= $evidence['level'] ?>)<?= $evidence['effective'] <= -2 ? ' · blocker' : ($evidence['effective'] >= $agent['threshold'] ? ' · qualifying relationship' : '') ?></li><?php endforeach; ?></ul><small>Agent ID <?= $agent['id'] ?></small></article>
<?php endforeach; ?></div></details>
<p class="note"><a href="https://support.eveonline.com/hc/en-us/articles/203217152-Standings" target="_blank" rel="noopener noreferrer">CCP mission-standing rules</a> · <a href="https://www.eveonline.com/news/view/patch-notes-version-23-01" target="_blank" rel="noopener noreferrer">Pirate enlistment threshold update</a></p>
</div>
