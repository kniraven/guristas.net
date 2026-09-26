<?php

// Guristas.net Venal intelligence service - PHP 8.0 compatible

declare(strict_types=1);

final class GuristasVenalService
{
    public const VENAL_REGION_ID = 10000015;

    /** @var GuristasEsiClient */
    private $esi;

    /** @var GuristasSourceService */
    private $sources;

    /** @var GuristasEsiCache */
    private $derivedCache;

    public function __construct(
        GuristasEsiClient $esi,
        GuristasSourceService $sources,
        GuristasEsiCache $derivedCache
    ) {
        $this->esi = $esi;
        $this->sources = $sources;
        $this->derivedCache = $derivedCache;
    }

    /**
     * Complete map payload used by the Venal intelligence interface.
     */
    public function map(): array
    {
        $static = $this->staticMap();
        $activity = $this->activity($static['data']['systems']);

        $activityBySystem = [];
        foreach ($activity['data']['systems'] as $row) {
            $activityBySystem[(int) $row['id']] = $row;
        }

        $systems = [];
        foreach ($static['data']['systems'] as $system) {
            $id = (int) $system['id'];
            $system['activity'] = isset($activityBySystem[$id])
                ? $activityBySystem[$id]['activity']
                : $this->emptyActivity();
            $systems[] = $system;
        }

        $data = $static['data'];
        $data['systems'] = $systems;
        $data['activity'] = [
            'window' => 'last_hour',
            'updated_at' => $activity['meta']['updated_at'],
        ];

        return [
            'data' => $data,
            'meta' => [
                'generated_at' => gmdate('c'),
                'static_cache' => $static['meta']['cache'],
                'static_built_at' => $static['meta']['built_at'],
                'static_expires_at' => $static['meta']['expires_at'],
                'activity_updated_at' => $activity['meta']['updated_at'],
                'activity_cache' => $activity['meta']['cache'],
            ],
            'sources' => array_values(array_merge(
                $static['sources'],
                $activity['sources']
            )),
        ];
    }

    /**
     * Static-ish Venal topology. Cached as a derived payload so a normal page
     * view never needs to fan out into hundreds of ESI calls.
     */
    public function staticMap(): array
    {
        $cacheKey = 'venal-static-map-v2';
        $cached = $this->derivedCache->read($cacheKey);
        $now = time();

        if ($cached !== null && $this->derivedCache->isFresh($cached, $now)) {
            return $this->staticResultFromCache($cached, 'HIT');
        }

        try {
            $built = $this->buildStaticMap();
            $entry = [
                'data' => $built['data'],
                'sources' => $built['sources'],
                'fetched_at' => $now,
                'expires_at' => $now + 86400,
            ];

            $this->derivedCache->write($cacheKey, $entry);

            return $this->staticResultFromCache($entry, 'MISS');
        } catch (Throwable $e) {
            // Geography is very stable. If a rebuild fails, retain a recently
            // expired derived map for up to seven days rather than blanking it.
            if ($cached !== null && $this->derivedCache->isUsableStale($cached, 604800, $now)) {
                $result = $this->staticResultFromCache($cached, 'STALE');
                $result['meta']['warning'] = $e->getMessage();
                return $result;
            }

            throw $e;
        }
    }

    /**
     * Public hourly activity overlay for Venal systems.
     */
    public function activity(array $systems): array
    {
        $requests = [
            [
                'key' => 'jumps',
                'path' => '/universe/system_jumps/',
                'fallback_ttl_seconds' => 300,
            ],
            [
                'key' => 'kills',
                'path' => '/universe/system_kills/',
                'fallback_ttl_seconds' => 300,
            ],
        ];

        $results = $this->esi->getJsonBatch($requests, 2);
        $jumpsResult = $results['jumps'];
        $killsResult = $results['kills'];

        $jumpsBySystem = [];
        foreach ((array) $jumpsResult['data'] as $row) {
            if (!isset($row['system_id'])) {
                continue;
            }
            $jumpsBySystem[(int) $row['system_id']] = (int) ($row['ship_jumps'] ?? 0);
        }

        $killsBySystem = [];
        foreach ((array) $killsResult['data'] as $row) {
            if (!isset($row['system_id'])) {
                continue;
            }
            $killsBySystem[(int) $row['system_id']] = [
                'ship_kills' => (int) ($row['ship_kills'] ?? 0),
                'pod_kills' => (int) ($row['pod_kills'] ?? 0),
                'npc_kills' => (int) ($row['npc_kills'] ?? 0),
            ];
        }

        $rows = [];
        foreach ($systems as $system) {
            $id = (int) $system['id'];
            $killData = isset($killsBySystem[$id])
                ? $killsBySystem[$id]
                : [
                    'ship_kills' => 0,
                    'pod_kills' => 0,
                    'npc_kills' => 0,
                ];

            $rows[] = [
                'id' => $id,
                'activity' => [
                    'ship_jumps' => isset($jumpsBySystem[$id]) ? $jumpsBySystem[$id] : 0,
                    'ship_kills' => $killData['ship_kills'],
                    'pod_kills' => $killData['pod_kills'],
                    'npc_kills' => $killData['npc_kills'],
                ],
            ];
        }

        $updatedAt = $this->mostRecentTime([
            $jumpsResult['meta']['last_modified'] ?? null,
            $killsResult['meta']['last_modified'] ?? null,
            $jumpsResult['meta']['fetched_at'] ?? null,
            $killsResult['meta']['fetched_at'] ?? null,
        ]);

        return [
            'data' => [
                'systems' => $rows,
            ],
            'meta' => [
                'updated_at' => $updatedAt,
                'cache' => $this->combinedCacheState([
                    $jumpsResult['meta']['cache'] ?? null,
                    $killsResult['meta']['cache'] ?? null,
                ]),
            ],
            'sources' => [
                $this->sources->esi('/universe/system_jumps/', $jumpsResult['meta']),
                $this->sources->esi('/universe/system_kills/', $killsResult['meta']),
            ],
        ];
    }

    private function buildStaticMap(): array
    {
        $regionEndpoint = '/universe/regions/' . self::VENAL_REGION_ID . '/';
        $regionResult = $this->esi->getJson($regionEndpoint, [], 86400);
        $region = (array) $regionResult['data'];

        $constellationRequests = [];
        foreach ((array) ($region['constellations'] ?? []) as $constellationId) {
            $constellationRequests[] = [
                'key' => (string) $constellationId,
                'path' => '/universe/constellations/' . (int) $constellationId . '/',
                'fallback_ttl_seconds' => 86400,
            ];
        }

        $constellationResults = $this->esi->getJsonBatch($constellationRequests);
        $constellations = [];
        $systemIds = [];

        foreach ($constellationResults as $result) {
            $row = (array) $result['data'];
            if (!isset($row['constellation_id'])) {
                continue;
            }

            $id = (int) $row['constellation_id'];
            $constellations[$id] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? $id),
                'position' => $row['position'] ?? null,
            ];

            foreach ((array) ($row['systems'] ?? []) as $systemId) {
                $systemIds[] = (int) $systemId;
            }
        }

        $systemIds = array_values(array_unique($systemIds));
        sort($systemIds, SORT_NUMERIC);
        $systemSet = array_fill_keys($systemIds, true);

        $systemRequests = [];
        foreach ($systemIds as $systemId) {
            $systemRequests[] = [
                'key' => (string) $systemId,
                'path' => '/universe/systems/' . $systemId . '/',
                'query' => ['language' => 'en'],
                'fallback_ttl_seconds' => 86400,
            ];
        }

        $systemResults = $this->esi->getJsonBatch($systemRequests);
        $rawSystems = [];
        $gateIds = [];

        foreach ($systemResults as $result) {
            $row = (array) $result['data'];
            if (!isset($row['system_id'])) {
                continue;
            }

            $id = (int) $row['system_id'];
            $rawSystems[$id] = $row;

            foreach ((array) ($row['stargates'] ?? []) as $gateId) {
                $gateIds[] = (int) $gateId;
            }
        }

        $gateIds = array_values(array_unique($gateIds));
        sort($gateIds, SORT_NUMERIC);

        $gateRequests = [];
        foreach ($gateIds as $gateId) {
            $gateRequests[] = [
                'key' => (string) $gateId,
                'path' => '/universe/stargates/' . $gateId . '/',
                'fallback_ttl_seconds' => 86400,
            ];
        }

        $gateResults = $this->esi->getJsonBatch($gateRequests);
        $internalEdges = [];
        $externalEdges = [];
        $externalSystemIds = [];

        foreach ($gateResults as $result) {
            $gate = (array) $result['data'];
            $a = isset($gate['system_id']) ? (int) $gate['system_id'] : 0;
            $b = isset($gate['destination']['system_id'])
                ? (int) $gate['destination']['system_id']
                : 0;

            if ($a === 0 || $b === 0 || !isset($systemSet[$a])) {
                continue;
            }

            $low = min($a, $b);
            $high = max($a, $b);
            $edgeKey = $low . ':' . $high;

            if (isset($systemSet[$b])) {
                $aConstellation = isset($rawSystems[$a]['constellation_id'])
                    ? (int) $rawSystems[$a]['constellation_id']
                    : 0;
                $bConstellation = isset($rawSystems[$b]['constellation_id'])
                    ? (int) $rawSystems[$b]['constellation_id']
                    : 0;

                $internalEdges[$edgeKey] = [
                    'a' => $low,
                    'b' => $high,
                    'type' => $aConstellation === $bConstellation
                        ? 'same_constellation'
                        : 'cross_constellation',
                ];
            } else {
                $externalSystemIds[$b] = true;
                $externalEdges[$edgeKey] = [
                    'a' => $a,
                    'b' => $b,
                    'type' => 'region_exit',
                ];
            }
        }

        $boundary = $this->loadBoundarySystems(array_keys($externalSystemIds));

        $neighbors = [];
        $exits = [];
        foreach ($systemIds as $id) {
            $neighbors[$id] = [];
            $exits[$id] = [];
        }

        foreach ($internalEdges as $edge) {
            $neighbors[$edge['a']][] = $edge['b'];
            $neighbors[$edge['b']][] = $edge['a'];
        }
        foreach ($externalEdges as $edge) {
            if (isset($exits[$edge['a']])) {
                $exits[$edge['a']][] = $edge['b'];
            }
        }

        $systems = [];
        foreach ($rawSystems as $id => $row) {
            $constellationId = (int) ($row['constellation_id'] ?? 0);
            $constellationName = isset($constellations[$constellationId])
                ? $constellations[$constellationId]['name']
                : (string) $constellationId;

            sort($neighbors[$id], SORT_NUMERIC);
            sort($exits[$id], SORT_NUMERIC);

            $systems[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? $id),
                'constellation_id' => $constellationId,
                'constellation' => $constellationName,
                'security' => (float) ($row['security_status'] ?? 0.0),
                'position' => $row['position'] ?? ['x' => 0, 'y' => 0, 'z' => 0],
                'star_id' => isset($row['star_id']) ? (int) $row['star_id'] : null,
                'planets' => array_values((array) ($row['planets'] ?? [])),
                'stations' => array_values((array) ($row['stations'] ?? [])),
                'stargates' => array_values((array) ($row['stargates'] ?? [])),
                'gate_count' => count((array) ($row['stargates'] ?? [])),
                'neighbors' => $neighbors[$id],
                'regional_exits' => $exits[$id],
            ];
        }

        usort($systems, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        $constellationRows = array_values($constellations);
        usort($constellationRows, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        $staticUpdatedAt = $regionResult['meta']['last_modified']
            ?? $regionResult['meta']['fetched_at']
            ?? gmdate('c');

        return [
            'data' => [
                'region' => [
                    'id' => self::VENAL_REGION_ID,
                    'name' => (string) ($region['name'] ?? 'Venal'),
                    'description' => (string) ($region['description'] ?? ''),
                ],
                'constellations' => $constellationRows,
                'systems' => $systems,
                'edges' => array_values($internalEdges),
                'boundary_systems' => array_values($boundary),
                'external_edges' => array_values($externalEdges),
                'counts' => [
                    'constellations' => count($constellationRows),
                    'systems' => count($systems),
                    'internal_connections' => count($internalEdges),
                    'regional_exits' => count($externalEdges),
                ],
            ],
            'sources' => [
                [
                    'name' => 'CCP ESI',
                    'publisher' => 'CCP Games',
                    'endpoint' => '/universe/regions/, /universe/constellations/, /universe/systems/, /universe/stargates/',
                    'updated_at' => $staticUpdatedAt,
                    'retrieved_at' => $regionResult['meta']['fetched_at'] ?? null,
                    'expires_at' => null,
                    'cache' => 'DERIVED',
                    'stale' => false,
                    'compatibility_date' => $regionResult['meta']['compatibility_date_matched']
                        ?? $regionResult['meta']['compatibility_date_requested']
                        ?? null,
                ],
            ],
        ];
    }

    private function loadBoundarySystems(array $systemIds): array
    {
        if ($systemIds === []) {
            return [];
        }

        $systemRequests = [];
        foreach ($systemIds as $systemId) {
            $systemRequests[] = [
                'key' => (string) $systemId,
                'path' => '/universe/systems/' . (int) $systemId . '/',
                'query' => ['language' => 'en'],
                'fallback_ttl_seconds' => 86400,
            ];
        }

        $systemResults = $this->esi->getJsonBatch($systemRequests);
        $raw = [];
        $constellationIds = [];

        foreach ($systemResults as $result) {
            $row = (array) $result['data'];
            if (!isset($row['system_id'])) {
                continue;
            }
            $id = (int) $row['system_id'];
            $raw[$id] = $row;
            if (isset($row['constellation_id'])) {
                $constellationIds[(int) $row['constellation_id']] = true;
            }
        }

        $constellationRequests = [];
        foreach (array_keys($constellationIds) as $constellationId) {
            $constellationRequests[] = [
                'key' => (string) $constellationId,
                'path' => '/universe/constellations/' . $constellationId . '/',
                'fallback_ttl_seconds' => 86400,
            ];
        }

        $constellationResults = $this->esi->getJsonBatch($constellationRequests);
        $constellationMap = [];
        $regionIds = [];

        foreach ($constellationResults as $result) {
            $row = (array) $result['data'];
            if (!isset($row['constellation_id'])) {
                continue;
            }
            $id = (int) $row['constellation_id'];
            $regionId = isset($row['region_id']) ? (int) $row['region_id'] : 0;
            $constellationMap[$id] = [
                'name' => (string) ($row['name'] ?? $id),
                'region_id' => $regionId,
            ];
            if ($regionId !== 0) {
                $regionIds[$regionId] = true;
            }
        }

        $regionRequests = [];
        foreach (array_keys($regionIds) as $regionId) {
            $regionRequests[] = [
                'key' => (string) $regionId,
                'path' => '/universe/regions/' . $regionId . '/',
                'fallback_ttl_seconds' => 86400,
            ];
        }

        $regionResults = $this->esi->getJsonBatch($regionRequests);
        $regionMap = [];
        foreach ($regionResults as $result) {
            $row = (array) $result['data'];
            if (!isset($row['region_id'])) {
                continue;
            }
            $regionMap[(int) $row['region_id']] = (string) ($row['name'] ?? $row['region_id']);
        }

        $boundary = [];
        foreach ($raw as $id => $row) {
            $constellationId = (int) ($row['constellation_id'] ?? 0);
            $constellation = $constellationMap[$constellationId] ?? [
                'name' => (string) $constellationId,
                'region_id' => 0,
            ];
            $regionId = (int) $constellation['region_id'];

            $boundary[$id] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? $id),
                'constellation_id' => $constellationId,
                'constellation' => $constellation['name'],
                'region_id' => $regionId,
                'region' => $regionMap[$regionId] ?? (string) $regionId,
                'security' => (float) ($row['security_status'] ?? 0.0),
                'position' => $row['position'] ?? ['x' => 0, 'y' => 0, 'z' => 0],
            ];
        }

        uasort($boundary, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        return $boundary;
    }

    private function staticResultFromCache(array $entry, string $cacheState): array
    {
        return [
            'data' => $entry['data'],
            'meta' => [
                'cache' => $cacheState,
                'built_at' => isset($entry['fetched_at']) ? gmdate('c', (int) $entry['fetched_at']) : null,
                'expires_at' => isset($entry['expires_at']) ? gmdate('c', (int) $entry['expires_at']) : null,
                'warning' => null,
            ],
            'sources' => isset($entry['sources']) && is_array($entry['sources'])
                ? $entry['sources']
                : [],
        ];
    }

    private function emptyActivity(): array
    {
        return [
            'ship_jumps' => 0,
            'ship_kills' => 0,
            'pod_kills' => 0,
            'npc_kills' => 0,
        ];
    }

    private function combinedCacheState(array $states): string
    {
        if (in_array('STALE', $states, true)) {
            return 'STALE';
        }
        if (in_array('MISS', $states, true)) {
            return 'MISS';
        }
        if (in_array('REVALIDATED', $states, true)) {
            return 'REVALIDATED';
        }
        return 'HIT';
    }

    private function mostRecentTime(array $values): ?string
    {
        $latest = null;
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                continue;
            }
            if ($latest === null || $timestamp > $latest) {
                $latest = $timestamp;
            }
        }

        return $latest !== null ? gmdate('c', $latest) : null;
    }
}
