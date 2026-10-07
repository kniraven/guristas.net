<?php
declare(strict_types=1);
require_once __DIR__ . '/EveAuth.php';
require_once __DIR__ . '/EsiCache.php';
require_once __DIR__ . '/EsiClient.php';

/** Private character data. The caller supplies the authenticated session ID. */
final class GuristasPilotDataService
{
    private $client;
    private $authorize;
    public function __construct(GuristasEsiClient $client, ?callable $authorize = null)
    {
        $this->client = $client;
        $this->authorize = $authorize ?? static function (int $id, string $scope): array {
            // Validate the scope even for a fresh cached response.
            $token = eve_access_token($id, $scope);
            $q = eve_db()->prepare('SELECT refresh_token_sealed, scopes_json FROM ' . eve_token_table() . ' WHERE character_id = ?');
            $q->execute([$id]);
            $grant = $q->fetch();
            if (!$grant) throw new GuristasAuthorizationRequired('Connect this feature.');
            return ['token' => $token, 'identity' => eve_token_table() . '|' . hash('sha256', $grant['refresh_token_sealed'] . '|' . $grant['scopes_json'])];
        };
    }
    public function read(int $characterId): array
    {
        if ($characterId < 1) throw new InvalidArgumentException('Authenticated character is required.');
        $result = ['character_id' => $characterId];
        foreach (['standings' => 'standings', 'fw' => 'fw/stats', 'skills' => 'skills'] as $feature => $route) {
            try {
                $grant = ($this->authorize)($characterId, eve_feature_scopes($feature)[0]);
                $response = $this->client->getCharacterJson($characterId, $route, $grant['token'], $grant['identity']);
                $data = $feature === 'standings' ? self::standings($response['data'])
                    : ($feature === 'skills' ? self::skills($response['data']) : self::factionWarfare($response['data']));
                $result[$feature] = ['state' => !empty($response['meta']['stale']) ? 'stale' : 'ready', 'data' => $data, 'meta' => $response['meta']];
            } catch (GuristasAuthorizationRequired $error) {
                $result[$feature] = ['state' => 'authorization_required', 'data' => null, 'meta' => null];
            } catch (Throwable $error) {
                $code = (int)$error->getCode();
                $state = in_array($code, [401, 403], true) ? 'authorization_required'
                    : (in_array($code, [420, 429], true) ? 'rate_limited' : 'unavailable');
                // Never include exception details, ESI error bodies or tokens in output.
                $result[$feature] = ['state' => $state, 'data' => null, 'meta' => null];
            }
        }
        return $result;
    }
    public static function skills($data): array
    {
        if (!is_array($data) || !isset($data['skills']) || !is_array($data['skills'])
            || ($data['skills'] !== [] && array_keys($data['skills']) !== range(0, count($data['skills']) - 1))) {
            throw new RuntimeException('Invalid skills response.');
        }
        $levels = []; $seen = [];
        foreach ($data['skills'] as $skill) {
            $id = $skill['skill_id'] ?? null; $level = $skill['active_skill_level'] ?? null;
            if (!is_int($id) || $id < 1 || isset($seen[$id]) || !is_int($level) || $level < 0 || $level > 5) {
                throw new RuntimeException('Invalid active skill.');
            }
            $seen[$id] = true;
            $levels[$id] = $level;
        }
        return $levels + [3357 => 0, 3359 => 0, 3361 => 0];
    }
    public static function standings($data): array
    {
        if (!is_array($data) || ($data !== [] && array_keys($data) !== range(0, count($data) - 1))) {
            throw new RuntimeException('Invalid standings response.');
        }
        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row) || !is_int($row['from_id'] ?? null) || $row['from_id'] < 1
                || !in_array($row['from_type'] ?? null, ['agent', 'npc_corp', 'faction'], true)
                || !is_numeric($row['standing'] ?? null) || !is_finite((float)$row['standing'])
                || $row['standing'] < -10 || $row['standing'] > 10) {
                throw new RuntimeException('Invalid standing entry.');
            }
            $rows[] = ['from_id' => $row['from_id'], 'from_type' => $row['from_type'], 'standing' => (float)$row['standing']];
        }
        usort($rows, static function (array $a, array $b): int {
            $order = ['faction' => 0, 'npc_corp' => 1, 'agent' => 2];
            return ($order[$a['from_type']] <=> $order[$b['from_type']]) ?: ($a['from_id'] <=> $b['from_id']);
        });
        return $rows;
    }
    public static function factionWarfare($data): array
    {
        if (!is_array($data)) throw new RuntimeException('Invalid FW response.');
        $normalized = ['enlisted' => isset($data['faction_id']), 'faction_id' => null, 'enlisted_on' => null, 'current_rank' => null, 'highest_rank' => null];
        foreach (['faction_id', 'current_rank', 'highest_rank'] as $field) {
            if (isset($data[$field])) {
                if (!is_int($data[$field]) || $data[$field] < 0 || ($field === 'faction_id' && $data[$field] === 0)) throw new RuntimeException('Invalid FW field.');
                $normalized[$field] = $data[$field];
            }
        }
        if (isset($data['enlisted_on'])) {
            if (!is_string($data['enlisted_on']) || strtotime($data['enlisted_on']) === false) throw new RuntimeException('Invalid enlistment date.');
            $normalized['enlisted_on'] = $data['enlisted_on'];
        }
        foreach (['kills', 'victory_points'] as $metric) {
            foreach (['yesterday', 'last_week', 'total'] as $period) {
                $value = $data[$metric][$period] ?? null;
                if (!is_int($value) || $value < 0) throw new RuntimeException('Invalid FW statistics.');
                $normalized[$metric][$period] = $value;
            }
        }
        return $normalized;
    }
}
function eve_pilot_data(int $characterId, bool $syncCombat = false): array
{
    $config = require dirname(__DIR__, 2) . '/config/esi.php';
    // Use a separate cache tree from public ESI data.
    $cache = new GuristasEsiCache(dirname(__DIR__, 2) . '/storage/cache/pilot/' . eve_token_table());
    $result = (new GuristasPilotDataService(new GuristasEsiClient($config, $cache)))->read($characterId);
    require_once __DIR__ . '/PilotRecommendations.php';
    $result += eve_read_pilot_context(new GuristasEsiClient($config, $cache), $characterId);
    $combatCache = new GuristasEsiCache(dirname(__DIR__, 2) . '/storage/cache/pilot/' . eve_token_table() . '/combat');
    $authorizeCombat = static function (int $id, string $scope): array {
        $token = eve_access_token($id, $scope);
        $q = eve_db()->prepare('SELECT refresh_token_sealed, scopes_json FROM ' . eve_token_table() . ' WHERE character_id = ?');
        $q->execute([$id]); $grant = $q->fetch();
        if (!$grant) throw new GuristasAuthorizationRequired('Sign in again.');
        return ['token' => $token, 'identity' => eve_token_table() . '|' . hash('sha256', $grant['refresh_token_sealed'] . '|' . $grant['scopes_json'])];
    };
    $result['combat'] = (new GuristasPilotCombat(new GuristasEsiClient($config, $combatCache), eve_pilot_record_store(), $authorizeCombat))->read($characterId, $syncCombat);
    try { $result['romance'] = eve_pilot_record_store()->read($characterId)['romance']; }
    catch (Throwable $error) { $result['romance'] = null; }
    // Name lookup is public and carries no character access token.
    require_once __DIR__ . '/PilotEntityNames.php';
    $catalog = eve_pilot_entity_catalog();
    $ids = [];
    foreach ($result['standings']['data'] ?? [] as $row) {
        $reference = $catalog[$row['from_id']] ?? [];
        if (empty($reference['name'])) $ids[] = $row['from_id'];
        if (!empty($reference['station_id'])) $ids[] = $reference['station_id'];
    }
    foreach ($catalog as $reference) {
        if (($reference['kind'] ?? '') === 'agent' && ($reference['faction_id'] ?? 0) === 500010
            && ($reference['agent_type_id'] ?? 0) === 2 && !empty($reference['station_id'])) $ids[] = $reference['station_id'];
    }
    $factionId = $result['fw']['data']['faction_id'] ?? null;
    if ($factionId && empty($catalog[$factionId]['name'])) $ids[] = $factionId;
    $names = eve_pilot_entity_names($ids);
    $destinations = [];
    foreach ($catalog as $agent) if (($agent['kind'] ?? '') === 'agent' && ($agent['faction_id'] ?? 0) === 500010 && ($agent['agent_type_id'] ?? 0) === 2 && ($agent['division_name'] ?? '') === 'Security') $destinations[] = $agent['system_id'] ?? null;
    $result['travel']['agent_jumps'] = eve_pilot_travel($result, $destinations, new GuristasEsiClient($config, new GuristasEsiCache(dirname(__DIR__, 2) . '/storage/cache/routes')));
    if (is_array($result['standings']['data'])) {
        $result['standings']['data'] = eve_enrich_pilot_entities($result['standings']['data'], $catalog, $names);
    }
    if ($factionId && isset($catalog[$factionId]['name'])) $names[$factionId] = $catalog[$factionId]['name'];
    $result['entity_names'] = $names;
    return $result;
}
