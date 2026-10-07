<?php
declare(strict_types=1);
require_once __DIR__ . '/GuristasProgress.php';
require_once __DIR__ . '/PilotRecommendations.php';
/** A compact view model. Recognition is current evidence, not permanent history. */
function eve_guristas_dashboard(array $pilotData, array $catalog): array
{
    $fresh = ($pilotData['standings']['state'] ?? '') === 'ready';
    $skills = $fresh && ($pilotData['skills']['state'] ?? '') === 'ready' ? $pilotData['skills']['data'] : null;
    $progress = eve_guristas_progress($pilotData['standings']['data'] ?? [], $catalog, $skills);
    $faction = null; $bestCorp = null; $corpFive = false;
    foreach ($progress['tracks'] as $track) {
        if ($track['from_type'] === 'faction') $faction = $track;
        else {
            if ($bestCorp === null || $track['standing'] > $bestCorp['standing']) $bestCorp = $track;
            if ($track['standing'] >= 5) $corpFive = true;
        }
    }
    $fwReady = ($pilotData['fw']['state'] ?? '') === 'ready';
    $guriEnlisted = $fwReady && ($pilotData['fw']['data']['faction_id'] ?? null) === 500010;
    $raw = $progress['faction_standing'];
    $badges = [
        ['name' => 'Known to the Pirates', 'description' => '+1 raw Guristas faction standing', 'earned' => $fresh && $raw !== null && $raw >= 1],
        ['name' => 'Trusted by the Pirates', 'description' => '+3 raw Guristas faction standing', 'earned' => $fresh && $raw !== null && $raw >= 3],
        ['name' => 'Inner Circle', 'description' => '+5 raw Guristas faction standing', 'earned' => $fresh && $raw !== null && $raw >= 5],
        ['name' => 'On the Payroll', 'description' => '+5 raw standing with a Guristas corporation', 'earned' => $fresh && $corpFive],
        ['name' => 'Venal Connections', 'description' => 'Three Guristas agents with raw standing of +1', 'earned' => $fresh && $progress['connections'] >= 3],
        ['name' => 'Commando Guri', 'description' => 'Current Guristas enlistment reported by ESI', 'earned' => $guriEnlisted],
    ];
    foreach ($badges as &$badge) $badge['section'] = $badge['name'] === 'Commando Guri' ? 'fw' : 'standings';
    unset($badge);
    foreach ($badges as $index => &$badge) {
        if ($index < 3) { $value = $fresh ? $raw : null; $target = [1, 3, 5][$index]; $metric = 'faction_standing'; }
        elseif ($index === 3) { $value = $fresh && $bestCorp !== null ? $bestCorp['standing'] : null; $target = 5; $metric = 'corporation_standing'; }
        elseif ($index === 4) { $value = $fresh ? $progress['connections'] : null; $target = 3; $metric = 'agent_connections'; }
        else continue;
        $badge += ['value' => $value, 'target' => $target, 'metric' => $metric, 'remaining' => $value === null ? null : max(0, $target - $value)];
    }
    unset($badge);
    // Career FW metrics are not faction-specific. Never label them Guristas-only kills or LP.
    foreach (['kills' => [1, 10, 50, 100], 'victory_points' => [1000, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000]] as $metric => $targets) {
        $value = $fwReady ? ($pilotData['fw']['data'][$metric]['total'] ?? null) : null;
        if ($metric === 'victory_points' && is_numeric($value)) {
            while ($value >= $targets[count($targets) - 1] && $targets[count($targets) - 1] < PHP_INT_MAX / 10) $targets[] = $targets[count($targets) - 1] * 10;
        }
        foreach ($targets as $target) {
            $badges[] = ['name' => $metric === 'kills' ? 'FW combat · ' . $target : 'Career FW VP · ' . number_format($target),
                'description' => number_format($target) . ($metric === 'kills' ? ' career FW kills' : ' career FW victory points') . ' · current Guristas enlistment',
                'earned' => $guriEnlisted && $value !== null && $value >= $target, 'section' => 'fw',
                'metric' => $metric, 'value' => $value, 'target' => $target,
                'remaining' => $value === null ? null : max(0, $target - $value)];
        }
    }
    // Standing has a +10 upper bound; career contribution is not a capped meter.
    foreach ([7 => 'Pirate Veteran', 10 => 'Maximum Faction Trust'] as $target => $name) {
        $value = $fresh ? $raw : null;
        $badges[] = ['name' => $name, 'description' => '+' . $target . ' raw Guristas faction standing',
            'earned' => $value !== null && $value >= $target, 'section' => 'standings',
            'metric' => 'faction_standing', 'value' => $value, 'target' => $target,
            'remaining' => $value === null ? null : max(0, $target - $value)];
    }
    $combatEvidence = $pilotData['combat']['data']['kills'] ?? null;
    if (is_array($combatEvidence)) {
        $killCount = count($combatEvidence); $hullCounts = [];
        foreach ($combatEvidence as $kill) $hullCounts[$kill['hull_id']] = ($hullCounts[$kill['hull_id']] ?? 0) + 1;
    } else { $killCount = null; $hullCounts = []; }
    foreach ([1, 10, 50, 100, 500] as $target) {
        $badges[] = ['name' => 'Pirate hull combat · ' . $target, 'description' => $target . ' recorded player kills while flying Guristas hulls',
            'earned' => $killCount !== null && $killCount >= $target, 'section' => 'combat', 'metric' => 'hull_kills',
            'value' => $killCount, 'target' => $target, 'remaining' => $killCount === null ? null : max(0, $target - $killCount), 'evidence_label' => 'Verified killmail evidence'];
    }
    require_once __DIR__ . '/PilotCombat.php';
    foreach (eve_guristas_hulls() as $id => $name) {
        $value = $killCount === null ? null : ($hullCounts[$id] ?? 0);
        $badges[] = ['name' => $name . ' · First blood', 'description' => 'One recorded player kill while flying a ' . $name,
            'earned' => $value !== null && $value >= 1, 'section' => 'combat', 'metric' => 'hull_' . $id,
            'value' => $value, 'target' => 1, 'remaining' => $value === null ? null : max(0, 1 - $value), 'evidence_label' => 'Verified killmail evidence'];
    }
    $romance = $pilotData['romance'] ?? null;
    foreach ([['Private Frequency', 1, 'romance_chapters'], ['Someone to Trust', 60, 'romance_affinity'], ['A Shared Horizon', 80, 'romance_partner']] as [$name, $target, $metric]) {
        $value = $romance === null ? null : ($metric === 'romance_chapters' ? $romance['chapter'] : $romance['affinity']);
        $partner = $metric !== 'romance_partner' || ($romance !== null && $romance['chapter'] >= 3 && ($romance['choices'][2] ?? '') !== 'friends');
        $badges[] = ['name' => $name, 'description' => $metric === 'romance_chapters' ? 'Complete the opening scene with Ren Vey' : ($metric === 'romance_partner' ? 'Finish the story together with at least 80 affinity' : 'Reach 60 affinity with Ren Vey'),
            'earned' => $value !== null && $value >= $target && $partner, 'section' => 'romance', 'metric' => $metric,
            'value' => $value, 'target' => $target, 'remaining' => $value === null ? null : max(0, $target - $value), 'evidence_label' => 'Saved Guristas.net story choices'];
    }
    $next = null;
    if ($fresh && $raw !== null) {
        foreach ([1, 3, 5, 7, 10] as $threshold) {
            if ($raw >= $threshold) continue;
            $floor = [1 => 0, 3 => 1, 5 => 3, 7 => 5, 10 => 7][$threshold];
            $next = ['threshold' => $threshold, 'name' => [1 => 'Known to the Pirates', 3 => 'Trusted by the Pirates', 5 => 'Inner Circle', 7 => 'Pirate Veteran', 10 => 'Maximum Faction Trust'][$threshold],
                'distance' => $threshold - $raw, 'percent' => max(0, min(100, ($raw - $floor) / ($threshold - $floor) * 100)), 'floor' => $floor];
            break;
        }
    }
    // Evaluate every ordinary agent, not just the displayed shortlist.
    $personal = [];
    foreach ($progress['relationships'] as $row) if ($row['from_type'] === 'agent') $personal[$row['from_id']] = $row['standing'];
    $recommended = $fresh ? eve_choose_mission_agent($progress['all_opportunities'], $pilotData, $personal) : null;
    if ($recommended !== null) {
        // Keep the recommendation visible even when it was outside the old alphabetical shortlist.
        $progress['opportunities'] = array_values(array_filter($progress['opportunities'], static function ($agent) use ($recommended) { return $agent['id'] !== $recommended['id']; }));
        array_unshift($progress['opportunities'], $recommended);
        $progress['opportunities'] = array_slice($progress['opportunities'], 0, 8);
    }
    $missionGoals = [];
    foreach ($progress['tracks'] as $track) {
        $levels = [];
        foreach ($catalog as $agent) {
            if (($agent['kind'] ?? '') !== 'agent' || ($agent['agent_type_id'] ?? 0) !== 2 || ($agent['faction_id'] ?? 0) !== 500010) continue;
            if ($track['from_type'] === 'npc_corp' && ($agent['corporation_id'] ?? null) !== $track['from_id']) continue;
            if (in_array($agent['agent_level'] ?? 0, [2, 3, 4, 5], true)) $levels[$agent['agent_level']] = true;
        }
        $track['mission_levels'] = array_keys($levels); sort($track['mission_levels']);
        $missionGoals[] = $track;
    }
    return $progress + ['fresh' => $fresh, 'skills' => $skills, 'faction' => $faction, 'best_corp' => $bestCorp,
        'fw_ready' => $fwReady, 'enlisted' => $guriEnlisted, 'badges' => $badges, 'next' => $next,
        'recommended' => $recommended, 'mission_tracks' => $missionGoals];
}
