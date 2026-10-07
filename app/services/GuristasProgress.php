<?php
declare(strict_types=1);
require_once __DIR__ . '/PilotEntityNames.php';
require_once __DIR__ . '/EffectiveStandings.php';
function eve_is_guristas_entity(array $row, array $catalog): bool
{
    return ($row['from_type'] === 'faction' && $row['from_id'] === 500010)
        || (($catalog[$row['from_id']]['faction_id'] ?? null) === 500010
            && ($catalog[$row['from_id']]['kind'] ?? null) === $row['from_type']);
}
/** Missing skills retain raw evidence and never assert calculated eligibility. */
function eve_guristas_progress(array $rows, array $catalog, ?array $skills = null): array
{
    $standings = []; $relationships = []; $tracks = []; $connections = 0;
    foreach ($rows as $row) {
        $standings[$row['from_id']] = $row['standing'];
        if (!eve_is_guristas_entity($row, $catalog)) continue;
        if ($skills !== null) $row += eve_guristas_effective((float)$row['standing'], $skills);
        $relationships[] = $row;
        if (in_array($row['from_type'], ['faction', 'npc_corp'], true)) $tracks[] = $row;
        if ($row['from_type'] === 'agent' && $row['standing'] >= 1) $connections++;
    }
    usort($tracks, static function ($a, $b) {
        return ($a['from_type'] === 'faction' ? 0 : 1) <=> ($b['from_type'] === 'faction' ? 0 : 1);
    });
    $opportunities = [];
    foreach ($catalog as $id => $agent) {
        if (($agent['kind'] ?? null) !== 'agent' || ($agent['faction_id'] ?? null) !== 500010
            || ($agent['agent_type_id'] ?? null) !== 2 || empty($agent['location_name'])) continue;
        $level = $agent['agent_level'] ?? 0;
        if (!in_array($level, [1, 2, 3, 4, 5], true)) continue;
        $threshold = [1 => -10, 2 => 1, 3 => 3, 4 => 5, 5 => 7][$level];
        $values = []; $evidence = []; $missing = false;
        foreach ([(int)$id, $agent['corporation_id'], 500010] as $entityId) {
            if (array_key_exists($entityId, $standings)) {
                $calculation = $skills === null ? ['raw' => $standings[$entityId], 'effective' => $standings[$entityId], 'skill' => 'Skills unavailable', 'level' => 0] : eve_guristas_effective((float)$standings[$entityId], $skills);
                $values[] = $calculation['effective'];
                $evidence[] = $calculation + ['name' => $catalog[$entityId]['name'] ?? ('EVE ID ' . $entityId)];
            } else { $missing = true; }
        }
        $highest = $values === [] ? null : max($values);
        $blocked = $level > 1 && $values !== [] && min($values) <= -2;
        $state = $level === 1 ? 'level_one' : ($blocked ? 'check_blocker' : ($highest === null ? 'unknown' : ($highest >= $threshold ? 'threshold_met' : 'working_toward')));
        if ($level > 1 && $skills !== null && $state === 'threshold_met') $state = $missing ? 'incomplete' : 'eligible';
        $opportunities[] = $agent + ['evidence' => $evidence, 'calculated' => $skills !== null] + ['id' => (int)$id, 'threshold' => $threshold, 'highest' => $highest, 'state' => $state];
    }
    usort($opportunities, static function ($a, $b) {
        $order = ['eligible' => 0, 'incomplete' => 0, 'threshold_met' => 0, 'level_one' => 1, 'working_toward' => 2, 'unknown' => 3, 'check_blocker' => 4];
        return ($order[$a['state']] <=> $order[$b['state']]) ?: ($b['agent_level'] <=> $a['agent_level'])
            ?: strcmp($a['name'], $b['name']);
    });
    // Varied shortlist: at most two ordinary agents at each level.
    $shortlist = []; $levels = [];
    foreach ($opportunities as $agent) {
        $level = $agent['agent_level'];
        if (($levels[$level] ?? 0) >= 2) continue;
        $levels[$level] = ($levels[$level] ?? 0) + 1; $shortlist[] = $agent;
        if (count($shortlist) === 8) break;
    }
    return ['relationships' => $relationships, 'tracks' => $tracks, 'connections' => $connections, 'opportunities' => $shortlist, 'all_opportunities' => $opportunities, 'faction_standing' => $standings[500010] ?? null];
}
