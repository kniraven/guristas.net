<?php
declare(strict_types=1);
require_once __DIR__ . '/EsiCache.php';
/** Public names only; the list of a pilot's standings is never cached here. */
function eve_pilot_entity_names(array $ids, ?callable $request = null, ?GuristasEsiCache $cache = null): array
{
    $ids = array_values(array_unique(array_filter($ids, static function ($id): bool { return is_int($id) && $id > 0; })));
    if ($ids === []) return [];
    $cache = $cache ?? new GuristasEsiCache(dirname(__DIR__, 2) . '/storage/cache/entity-names');
    $names = []; $missing = [];
    foreach ($ids as $id) {
        $entry = $cache->read('entity:' . $id);
        if (is_string($entry['name'] ?? null)) $names[$id] = $entry['name'];
        if (!$entry || !$cache->isFresh($entry)) $missing[] = $id;
    }
    $request = $request ?? static function (array $batch): array {
        $ch = curl_init('https://esi.evetech.net/universe/names/');
        if ($ch === false) throw new RuntimeException('Name lookup unavailable.');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($batch, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Compatibility-Date: 2026-09-14'],
            CURLOPT_USERAGENT => 'Guristas.net/1.0 (https://guristas.net/)', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        try {
            $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($status !== 200 || !is_string($body) || strlen($body) > 1000000) throw new RuntimeException('Name lookup unavailable.');
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('Invalid names.');
            return $data;
        } finally { curl_close($ch); }
    };
    foreach (array_chunk($missing, 1000) as $batch) {
        try {
            foreach ($request($batch) as $row) {
                $id = $row['id'] ?? null; $name = $row['name'] ?? null;
                if (!is_int($id) || !in_array($id, $batch, true) || !is_string($name) || trim($name) === '') continue;
                $names[$id] = $name;
                $cache->write('entity:' . $id, ['name' => $name, 'expires_at' => time() + 86400]);
            }
        } catch (Throwable $error) { /* Names failing must not hide the standings. */ }
    }
    return $names;
}

/** Compact, versioned NPC reference; all details are public static data. */
function eve_pilot_entity_catalog(): array
{
    static $entities = null;
    if ($entities === null) {
        $path = dirname(__DIR__) . '/data/pilot-entities.json';
        $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $entities = $data['entities'] ?? [];
    }
    return $entities;
}
function eve_enrich_pilot_entities(array $rows, array $catalog, array $names): array
{
    foreach ($rows as &$row) {
        $reference = $catalog[$row['from_id']] ?? [];
        // Do not let static metadata replace the authorized ESI standing or its type.
        if (($reference['kind'] ?? null) === $row['from_type']) {
            foreach (['corporation_id', 'corporation_name', 'faction_id', 'faction_name', 'station_id', 'system_id', 'system_name', 'location_name', 'agent_level', 'division_name'] as $field) {
                if (isset($reference[$field])) $row[$field] = $reference[$field];
            }
            $row['name'] = $reference['name'];
        } else { $row['name'] = $names[$row['from_id']] ?? ('EVE ID ' . $row['from_id']); }
        if (isset($row['station_id'], $names[$row['station_id']])) {
            $row['location_name'] = $names[$row['station_id']];
        }
    }
    unset($row);
    return $rows;
}
