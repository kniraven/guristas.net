<?php
declare(strict_types=1);
require_once __DIR__ . '/PilotRecommendations.php';
function eve_guristas_hull_goal(array $pilotData): array
{
    $kills = $pilotData['combat']['data']['kills'] ?? null; $counts = []; $total = null;
    if (is_array($kills)) {
        $total = 0;
        foreach ($kills as $kill) if (isset(eve_readiness_reference()['hulls'][$kill['hull_id'] ?? 0])) { $id = $kill['hull_id']; $counts[$id] = ($counts[$id] ?? 0) + 1; $total++; }
    }
    $target = null;
    foreach ([1, 10, 50, 100, 500] as $tier) if ($total === null || $total < $tier) { $target = $tier; break; }
    $remaining = $total === null || $target === null ? null : $target - $total;
    $close = $remaining !== null && $remaining <= max(2, (int)ceil($target * .1));
    $candidates = [];
    foreach (eve_pilot_hull_readiness($pilotData) as $hull) {
        // Solo starter goals exclude capitals and rare prize hulls unless already available.
        if ($hull['can_fly'] !== true || eve_pilot_supports($pilotData, 1) !== true || eve_mission_hull_level($hull) === 0) continue;
        if (!in_array($hull['name'], ['Worm', 'Mamba', 'Gila', 'Alligator', 'Rattlesnake', 'Cobra', 'Python', 'Sidewinder'], true) && $hull['owned'] !== true) continue;
        $hull['recorded_hull_kills'] = $counts[$hull['id']] ?? 0;
        $hull['size_rank'] = ['Frigate' => 0, 'Destroyer' => 1, 'Cruiser' => 2, 'Battlecruiser' => 3, 'Battleship' => 4][$hull['family']] ?? 9;
        $candidates[] = $hull;
    }
    usort($candidates, static function ($a, $b) use ($total, $close) {
        return ($a['availability'] <=> $b['availability'])
            ?: (!$close && $total !== null ? (($a['recorded_hull_kills'] > 0 ? 1 : 0) <=> ($b['recorded_hull_kills'] > 0 ? 1 : 0)) : 0)
            ?: ($a['size_rank'] <=> $b['size_rank']) ?: strcmp($a['name'], $b['name']);
    });
    $chosen = $candidates[0] ?? null;
    $reason = $chosen === null ? 'A fresh skill response and a drone/shield support baseline are needed before suggesting a Guristas combat hull. Review hull prerequisites and use an activity appropriate to your current ship.'
        : 'Hull prerequisites and a basic drone/shield support baseline are met. Your current hull and locally recorded hulls come first, then other owned hulls. ' . ($close ? 'You are close to an overall combat milestone, so continue in an available hull.' : ($total === null ? 'Import combat history before choosing a first-blood goal.' : 'Among equally available hulls, unfinished first-blood milestones come first.')) . ' Smaller hull classes break ties; this is a cost-conscious preference, not a live price comparison. Fitting, replacement budget and engagement suitability remain unverified.';
    return ['id' => $chosen['id'] ?? null, 'name' => $chosen['name'] ?? null, 'recorded_hull_kills' => $chosen['recorded_hull_kills'] ?? 0,
        'recorded_total' => $total, 'target' => $target, 'remaining' => $remaining, 'based_on_history' => $chosen !== null && ($counts[$chosen['id']] ?? 0) > 0,
        'history_available' => is_array($kills), 'first_blood' => $chosen !== null && $total !== null && ($counts[$chosen['id']] ?? 0) === 0 && !$close,
        'reason' => $reason, 'readiness' => $chosen, 'alternatives' => array_slice($candidates, 1, 3)];
}
