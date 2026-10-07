<?php
declare(strict_types=1);
/** Advice is a transparent heuristic, not a fleet order or guaranteed expansion. */
function eve_insurgency_advice(array $overlay, ?int $now = null, array $pilotData = []): array
{
    $now = $now ?? time();
    $meta = $overlay['meta'] ?? []; $data = $overlay['data'] ?? [];
    $retrieved = strtotime($meta['last_success_at'] ?? '') ?: 0;
    if (!empty($meta['stale']) || !$retrieved || $now - $retrieved > 900 || $retrieved > $now + 60) return ['phase' => 'unavailable', 'reason' => 'A fresh campaign feed is needed before recommending a destination.'];
    $campaigns = array_values(array_filter($data['campaigns'] ?? [], static function ($campaign) { return in_array($campaign['state'] ?? '', ['ACTIVE', 'FORECAST'], true) && empty($campaign['ended_at']); }));
    usort($campaigns, static function ($a, $b) { return ($b['id'] ?? 0) <=> ($a['id'] ?? 0); });
    $result = ['phase' => 'none', 'retrieved_at' => $meta['last_success_at'], 'source_mode' => $meta['source_mode'] ?? 'unknown', 'target' => null];
    if ($campaigns === []) return $result;
    $campaign = $campaigns[0]; $result['campaign'] = $campaign;
    $result['phase'] = strtolower($campaign['state']);
    if ($result['phase'] === 'forecast') {
        // The feed's startDateTime marks the forecasting period, not an assured live start.
        $start = strtotime($campaign['started_at'] ?? '');
        $result['expected_start'] = $start ? gmdate('c', $start + 48 * 3600) : null;
        return $result;
    }
    $systems = array_values(array_filter($data['systems'] ?? [], static function ($system) {
        return is_numeric($system['corruption_stage'] ?? null) && is_numeric($system['corruption_percent'] ?? null)
            && is_numeric($system['suppression_stage'] ?? null) && $system['corruption_stage'] < 5 && $system['suppression_stage'] < 5;
    }));
    foreach ($systems as &$system) $system['jumps'] = $pilotData['travel']['fw_jumps'][$system['id'] ?? $system['system_id'] ?? 0] ?? null;
    unset($system);
    // Practical travel first. Campaign progress breaks ties; no invented LP/hour or safety score.
    usort($systems, static function ($a, $b) {
        return (($a['jumps'] === null ? 1 : 0) <=> ($b['jumps'] === null ? 1 : 0))
            ?: (($a['jumps'] ?? 0) <=> ($b['jumps'] ?? 0)) ?: ($a['suppression_stage'] <=> $b['suppression_stage'])
            ?: ($b['corruption_stage'] <=> $a['corruption_stage']) ?: ($b['corruption_percent'] <=> $a['corruption_percent']) ?: strcmp($a['name'], $b['name']);
    });
    $result['alternatives'] = array_slice($systems, 0, 4);
    $result['target'] = isset($systems[0]) && $systems[0]['jumps'] !== null ? $systems[0] : null;
    $result['selection_reason'] = 'Known shortest stargate travel comes first, then lower suppression and campaign progress. This is a practical shortlist, not an estimate of LP/hour, live opposition or route safety. Winning can increase the final contribution payout, but your personal contribution and actual site availability are not reported by this feed.';
    $result['goal'] = $result['target'] !== null && $result['target']['corruption_stage'] < 3 ? 'spread' : 'win';
    $result['won_systems'] = count(array_filter($data['systems'] ?? [], static function ($system) { return ($system['corruption_stage'] ?? null) === 5; }));
    return $result;
}
function eve_current_insurgency_advice(array $pilotData = []): array
{
    try {
        require_once __DIR__ . '/DataServices.php';
        $services = new GuristasDataServices();
        $overlay = $services->frontlines()->guristasOverlay();
        require_once __DIR__ . '/PilotRecommendations.php';
        $config = require dirname(__DIR__, 2) . '/config/esi.php';
        // Do not perform route lookups against an expired campaign snapshot.
        if (eve_insurgency_advice($overlay)['phase'] === 'active') {
            $destinations = array_map(static function ($system) { return $system['id'] ?? $system['system_id'] ?? null; }, $overlay['data']['systems'] ?? []);
            $pilotData['travel']['fw_jumps'] = eve_pilot_travel($pilotData, $destinations, new GuristasEsiClient($config, new GuristasEsiCache(dirname(__DIR__, 2) . '/storage/cache/routes')));
        }
        return eve_insurgency_advice($overlay, null, $pilotData);
    } catch (Throwable $error) { return ['phase' => 'unavailable', 'reason' => 'The live campaign feed is temporarily unavailable.']; }
}
