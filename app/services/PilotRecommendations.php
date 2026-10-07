<?php
declare(strict_types=1);
require_once __DIR__ . '/PilotCombat.php';
/** Automatic evidence checks. Support baselines are site guidance, not EVE unlock rules. */
function eve_readiness_reference(): array
{
    static $reference;
    if ($reference === null) $reference = json_decode((string)file_get_contents(dirname(__DIR__) . '/data/pilot-readiness.json'), true, 128, JSON_THROW_ON_ERROR);
    return $reference;
}
function eve_read_pilot_context(GuristasEsiClient $client, int $characterId, ?callable $authorize = null): array
{
    $authorize = $authorize ?? static function ($id, $scope) {
        $token = eve_access_token($id, $scope);
        $q = eve_db()->prepare('SELECT refresh_token_sealed, scopes_json FROM ' . eve_token_table() . ' WHERE character_id = ?');
        $q->execute([$id]); $grant = $q->fetch();
        if (!$grant) throw new GuristasAuthorizationRequired('Sign in again.');
        return ['token' => $token, 'identity' => eve_token_table() . '|' . hash('sha256', $grant['refresh_token_sealed'] . '|' . $grant['scopes_json'])];
    };
    $result = [];
    foreach (['location', 'ship', 'assets'] as $feature) {
        try {
            // Authorization precedes even a fresh cache read.
            $grant = $authorize($characterId, eve_feature_scopes($feature)[0]);
            $response = $client->getCharacterJson($characterId, $feature, $grant['token'], $grant['identity']);
            $data = $response['data']; $stale = !empty($response['meta']['stale']);
            if ($feature === 'assets') {
                $pages = (int)($response['meta']['pages'] ?? 1);
                if ($pages > 20) throw new RuntimeException('Asset inventory exceeds bounded scan.');
                for ($page = 2; $page <= $pages; $page++) {
                    $next = $client->getCharacterJson($characterId, $feature, $grant['token'], $grant['identity'], $page);
                    if (($next['meta']['pages'] ?? 1) !== $pages) throw new RuntimeException('Inventory changed during scan.');
                    $stale = $stale || !empty($next['meta']['stale']);
                    if (!is_array($next['data'])) throw new RuntimeException('Invalid inventory page.');
                    $data = array_merge($data, $next['data']);
                }
            }
            $result[$feature] = ['state' => $stale ? 'stale' : 'ready', 'data' => eve_normalize_pilot_context($feature, $data), 'meta' => $response['meta']];
        } catch (Throwable $error) {
            $state = $error instanceof GuristasAuthorizationRequired || in_array((int)$error->getCode(), [401, 403], true) ? 'authorization_required' : (in_array((int)$error->getCode(), [420, 429], true) ? 'rate_limited' : 'unavailable');
            $result[$feature] = ['state' => $state, 'data' => null, 'meta' => null];
        }
    }
    return $result;
}
function eve_normalize_pilot_context(string $feature, $data): array
{
    if (!is_array($data)) throw new RuntimeException('Invalid context.');
    if ($feature === 'location') {
        if (!is_int($data['solar_system_id'] ?? null) || $data['solar_system_id'] < 1) throw new RuntimeException('Invalid location.');
        $result = ['solar_system_id' => $data['solar_system_id']];
        foreach (['station_id', 'structure_id'] as $key) if (isset($data[$key])) {
            if (!is_int($data[$key]) || $data[$key] < 1) throw new RuntimeException('Invalid dock.');
            $result[$key] = $data[$key];
        }
        return $result;
    }
    if ($feature === 'ship') {
        if (!is_int($data['ship_type_id'] ?? null) || $data['ship_type_id'] < 1) throw new RuntimeException('Invalid hull.');
        return ['ship_type_id' => $data['ship_type_id']];
    }
    if ($feature !== 'assets') throw new InvalidArgumentException('Unknown context.');
    $hulls = eve_readiness_reference()['hulls']; $result = [];
    foreach ($data as $row) {
        if (!is_array($row) || !is_int($row['type_id'] ?? null) || !is_int($row['quantity'] ?? null) || $row['quantity'] < 1
            || !is_int($row['location_id'] ?? null) || !is_string($row['location_type'] ?? null) || !is_string($row['location_flag'] ?? null)) throw new RuntimeException('Invalid inventory.');
        // Retain only hull evidence. Ignore BPCs, asset safety, and nested ship/container holds.
        if (isset($hulls[$row['type_id']]) && in_array($row['location_type'], ['station', 'solar_system', 'other'], true)
            && in_array($row['location_flag'], ['Hangar', 'HangarAll'], true) && empty($row['is_blueprint_copy'])) {
            $result[] = ['type_id' => $row['type_id'], 'location_id' => $row['location_id'], 'location_type' => $row['location_type'], 'quantity' => $row['quantity']];
        }
    }
    return $result;
}
function eve_required_skills(int $typeId, array $reference, array &$seen = []): array
{
    if (isset($seen[$typeId])) return [];
    $seen[$typeId] = true; $result = [];
    foreach ($reference['types'][$typeId]['requirements'] ?? [] as $id => $level) {
        $result[$id] = max($result[$id] ?? 0, $level);
        foreach (eve_required_skills((int)$id, $reference, $seen) as $parent => $needed) $result[$parent] = max($result[$parent] ?? 0, $needed);
    }
    return $result;
}
function eve_pilot_hull_readiness(array $pilotData): array
{
    $reference = eve_readiness_reference(); $levels = ($pilotData['skills']['state'] ?? '') === 'ready' ? ($pilotData['skills']['data'] ?? null) : null;
    $assets = ($pilotData['assets']['state'] ?? '') === 'ready' ? ($pilotData['assets']['data'] ?? null) : null;
    $location = ($pilotData['location']['state'] ?? '') === 'ready' ? ($pilotData['location']['data'] ?? []) : [];
    $current = ($pilotData['ship']['state'] ?? '') === 'ready' ? ($pilotData['ship']['data']['ship_type_id'] ?? null) : null;
    $result = [];
    foreach ($reference['hulls'] as $id => $hull) {
        $seen = []; $required = eve_required_skills((int)$id, $reference, $seen); $missing = [];
        if ($levels !== null) foreach ($required as $skill => $level) if (($levels[$skill] ?? 0) < $level) $missing[] = ($reference['types'][$skill]['name'] ?? 'Skill ' . $skill) . ' ' . $level;
        $owned = false; $local = false;
        foreach ($assets ?? [] as $asset) if ($asset['type_id'] === (int)$id) {
            $owned = true;
            $local = $local || (isset($location['station_id']) && $asset['location_id'] === $location['station_id'])
                || (isset($location['structure_id']) && $asset['location_id'] === $location['structure_id'])
                || ($asset['location_type'] === 'solar_system' && $asset['location_id'] === ($location['solar_system_id'] ?? null));
        }
        $isCurrent = $current === (int)$id;
        $result[$id] = $hull + ['id' => (int)$id, 'can_fly' => $levels === null || $required === [] ? null : $missing === [],
            'missing_skills' => $missing, 'current' => $isCurrent, 'owned' => $assets === null && !$isCurrent ? null : $owned || $isCurrent,
            'local' => $local || $isCurrent, 'availability' => $isCurrent ? 0 : ($local ? 1 : ($owned ? 2 : 3))];
    }
    return $result;
}
function eve_pilot_supports(array $pilotData, int $level): ?bool
{
    if (($pilotData['skills']['state'] ?? '') !== 'ready') return null;
    // Drone/shield Guristas baseline. This is deliberately independent of any selected fitting.
    $skills = $pilotData['skills']['data']; $minimum = $level <= 1 ? 2 : ($level <= 3 ? 3 : 4);
    foreach ([3436, 3437, 3413, 3416, 3426] as $id) if (($skills[$id] ?? 0) < $minimum) return false;
    if ($level >= 3 && (($skills[3436] ?? 0) < 5 || ($skills[3442] ?? 0) < ($level >= 4 ? 4 : 3))) return false;
    return true;
}
function eve_mission_hull_level(array $hull): int
{
    return ['Frigate' => 2, 'Destroyer' => 2, 'Cruiser' => 3, 'Battlecruiser' => 4, 'Battleship' => 4][$hull['family']] ?? 0;
}
function eve_choose_mission_agent(array $agents, array $pilotData, array $personal): ?array
{
    $hulls = eve_pilot_hull_readiness($pilotData); $candidates = [];
    foreach ($agents as $agent) {
        if (!in_array($agent['state'], ['eligible', 'level_one'], true) || ($agent['division_name'] ?? '') !== 'Security' || $agent['agent_level'] > 4) continue;
        $level = $agent['agent_level']; $support = eve_pilot_supports($pilotData, $level); $usable = [];
        foreach ($hulls as $hull) if ($support === true && $hull['can_fly'] === true && eve_mission_hull_level($hull) >= $level) $usable[] = $hull;
        usort($usable, static function ($a, $b) { return ($a['availability'] <=> $b['availability']) ?: (eve_mission_hull_level($a) <=> eve_mission_hull_level($b)) ?: strcmp($a['name'], $b['name']); });
        // Without enough evidence, offer unrestricted level 1 rather than claim higher-level readiness.
        if ($level > 1 && ($support !== true || $usable === [])) continue;
        $agent['readiness_hull'] = $usable[0] ?? null;
        $agent['personal_standing'] = $personal[$agent['id']] ?? -10;
        $agent['jumps'] = $pilotData['travel']['agent_jumps'][$agent['system_id'] ?? 0] ?? null;
        $agent['availability'] = $agent['readiness_hull']['availability'] ?? 4;
        $candidates[] = $agent;
    }
    usort($candidates, static function ($a, $b) {
        return ($a['availability'] <=> $b['availability']) ?: (($a['jumps'] === null ? 1 : 0) <=> ($b['jumps'] === null ? 1 : 0))
            ?: (($a['jumps'] ?? 0) <=> ($b['jumps'] ?? 0)) ?: ($b['agent_level'] <=> $a['agent_level'])
            ?: ($b['personal_standing'] <=> $a['personal_standing']) ?: strcmp($a['name'], $b['name']);
    });
    if ($candidates === []) return null;
    $agent = $candidates[0];
    $agent['alternatives'] = array_slice($candidates, 1, 3);
    $agent['selection_reason'] = 'Standing access is checked first. Security missions are filtered by Guristas hull prerequisites and a drone/shield support-skill baseline. Available hulls come first, then shortest known stargate travel, mission level and existing relationship. ';
    $agent['selection_reason'] .= $agent['readiness_hull'] ? $agent['readiness_hull']['name'] . ' meets hull prerequisites. ' : 'Readiness is unconfirmed, so level 1 is the fallback. ';
    $agent['selection_reason'] .= 'This is guidance, not proof of a suitable fitting or mission success. Level 5 group missions are not automatic solo recommendations. Unknown routes do not mean safe routes.';
    return $agent;
}
/** Public route data is isolated from bearer credentials. Bound destinations and fail individually. */
function eve_pilot_travel(array $pilotData, array $destinations, GuristasEsiClient $client): array
{
    $origin = ($pilotData['location']['state'] ?? '') === 'ready' ? ($pilotData['location']['data']['solar_system_id'] ?? null) : null;
    if (!$origin) return [];
    $result = [];
    $requests = [];
    foreach (array_slice(array_values(array_unique(array_filter($destinations))), 0, 24) as $destination) {
        $destination = (int)$destination;
        if ($origin === $destination) { $result[$destination] = 0; continue; }
        $requests[] = ['key' => $destination, 'path' => '/route/' . $origin . '/' . $destination, 'query' => ['flag' => 'shortest'], 'fallback_ttl_seconds' => 86400];
    }
    foreach (array_chunk($requests, 6) as $chunk) {
        try {
            foreach ($client->getJsonBatch($chunk, 6) as $destination => $response) {
                $route = $response['data'];
                if (empty($response['meta']['stale']) && is_array($route) && ($route[0] ?? null) === $origin && end($route) === $destination) $result[$destination] = count($route) - 1;
            }
        } catch (Throwable $error) { /* Failed route batches remain unknown; never label them safe. */ }
    }
    return $result;
}
