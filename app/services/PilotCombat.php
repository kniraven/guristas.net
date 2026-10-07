<?php
declare(strict_types=1);
require_once __DIR__ . '/PilotRecordStore.php';
require_once __DIR__ . '/EveAuth.php';
require_once __DIR__ . '/EsiClient.php';
function eve_guristas_hulls(): array
{
    static $hulls;
    if ($hulls !== null) return $hulls;
    $document = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/data/ships.json'), true, 64, JSON_THROW_ON_ERROR);
    $hulls = [];
    foreach ($document['ships'] ?? [] as $ship) if (($ship['faction'] ?? '') === 'Guristas Pirates') $hulls[(int)$ship['id']] = $ship['name'];
    if ($hulls === []) throw new RuntimeException('Guristas hull reference unavailable.');
    return $hulls;
}
function eve_guristas_kill_evidence(array $mail, int $characterId, array $hulls): ?array
{
    if (!is_int($mail['killmail_id'] ?? null) || $mail['killmail_id'] < 1 || !is_string($mail['killmail_time'] ?? null) || strtotime($mail['killmail_time']) === false
        || !is_int($mail['solar_system_id'] ?? null) || !is_array($mail['attackers'] ?? null) || !is_array($mail['victim'] ?? null)) throw new RuntimeException('Invalid killmail.');
    // Count player kills only, never losses, structures or NPCs.
    if (!is_int($mail['victim']['character_id'] ?? null) || $mail['victim']['character_id'] === $characterId) return null;
    foreach ($mail['attackers'] as $attacker) if (($attacker['character_id'] ?? null) === $characterId && isset($hulls[$attacker['ship_type_id'] ?? 0])) {
        if (!is_bool($attacker['final_blow'] ?? null)) throw new RuntimeException('Invalid final blow.');
        return ['killmail_id' => $mail['killmail_id'], 'time' => $mail['killmail_time'], 'system_id' => $mail['solar_system_id'], 'hull_id' => $attacker['ship_type_id'], 'victim_hull_id' => $mail['victim']['ship_type_id'] ?? 0, 'final_blow' => $attacker['final_blow']];
    }
    return null;
}
final class GuristasPilotCombat
{
    private GuristasEsiClient $client;
    private GuristasPilotRecordStore $store;
    private $authorize;
    public function __construct(GuristasEsiClient $client, GuristasPilotRecordStore $store, callable $authorize) { $this->client = $client; $this->store = $store; $this->authorize = $authorize; }
    public function read(int $id, bool $sync = true): array
    {
        $record = null;
        try {
            // A revoked/missing permission cannot reveal historical private evidence.
            $grant = ($this->authorize)($id, 'esi-killmails.read_killmails.v1');
            $record = $this->store->read($id);
            if (!$sync) return ['state' => $record['combat']['last_sync'] === null ? 'not_synced' : 'recorded', 'data' => $record['combat']['last_sync'] === null ? null : $record['combat'], 'pending' => null, 'meta' => null];
            $recent = $this->client->getCharacterJson($id, 'killmails/recent', $grant['token'], $grant['identity']);
            if (!empty($recent['meta']['stale'])) return ['state' => 'stale', 'data' => $record['combat'], 'pending' => null, 'meta' => $recent['meta']];
            $refs = $recent['data'];
            if (!is_array($refs) || ($refs !== [] && array_keys($refs) !== range(0, count($refs)-1))) throw new RuntimeException('Invalid recent killmails.');
            $pages = (int)($recent['meta']['pages'] ?? 1);
            $cursor = max(2, min($pages, (int)($record['combat']['next_page'] ?? 2)));
            if ($pages > 1) {
                $older = $this->client->getCharacterJson($id, 'killmails/recent', $grant['token'], $grant['identity'], $cursor);
                if (!empty($older['meta']['stale']) || !is_array($older['data'])) throw new RuntimeException('Historical page unavailable.');
                $refs = array_merge($refs, $older['data']);
            }
            $checked = $record['combat']['checked']; $evidence = []; $pending = 0; $requests = 0;
            $requests = [];
            foreach ($refs as $ref) {
                if (!is_int($ref['killmail_id'] ?? null) || $ref['killmail_id'] < 1 || !is_string($ref['killmail_hash'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $ref['killmail_hash'])) throw new RuntimeException('Invalid killmail reference.');
                $key = (string)$ref['killmail_id'];
                if (isset($checked[$key]) || isset($requests[$key])) continue;
                if (count($requests) >= 12) { $pending++; continue; }
                $requests[$key] = ['key' => $key, 'path' => '/killmails/' . $ref['killmail_id'] . '/' . $ref['killmail_hash']];
            }
            try { $details = $this->client->getJsonBatch(array_values($requests), 6); }
            catch (Throwable $error) { $details = []; }
            $hulls = eve_guristas_hulls();
            foreach ($requests as $key => $request) {
                try {
                    $detail = $details[$key] ?? null;
                    if ($detail === null || !empty($detail['meta']['stale']) || ($detail['data']['killmail_id'] ?? null) !== (int)$key) { $pending++; continue; }
                    $kill = eve_guristas_kill_evidence($detail['data'], $id, $hulls);
                    $checked[$key] = time();
                    if ($kill !== null) $evidence[$key] = $kill;
                } catch (Throwable $error) { $pending++; }
            }
            $record = $this->store->update($id, static function ($current) use ($checked, $evidence, $pending, $cursor, $pages) {
                $current['combat']['kills'] = $current['combat']['kills'] + $evidence;
                $current['combat']['checked'] = array_filter($current['combat']['checked'] + $checked, static function ($time) { return $time >= time() - 95 * 86400; });
                $current['combat']['last_sync'] = gmdate('c');
                $current['combat']['next_page'] = $pending === 0 ? ($cursor >= $pages ? 2 : $cursor + 1) : $cursor;
                $current['combat']['reported_pages'] = $pages;
                return $current;
            });
            return ['state' => 'ready', 'data' => $record['combat'], 'pending' => $pending, 'meta' => $recent['meta']];
        } catch (Throwable $error) {
            $authorization = $error instanceof GuristasAuthorizationRequired || in_array((int)$error->getCode(), [401,403], true);
            return ['state' => $authorization ? 'authorization_required' : 'unavailable', 'data' => $authorization || $record === null ? null : $record['combat'], 'pending' => null, 'meta' => null];
        }
    }
}
