<?php

// Guristas.net Zarzakh overlay service - PHP 8.0 compatible

declare(strict_types=1);

final class GuristasZarzakhService
{
    public const ZARZAKH_SYSTEM_ID = 30100000;

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
     * A system-scale view of Zarzakh using actual in-system object positions:
     * the central star/system origin, The Fulcrum (when exposed by ESI), and
     * every Zarzakh stargate with the destination system resolved.
     */
    public function overlay(): array
    {
        $static = $this->staticOverlay();
        $killsResult = $this->esi->getJson('/universe/system_kills/', [], 300);

        $shipKills = 0;
        $podKills = 0;
        foreach ((array) $killsResult['data'] as $row) {
            if ((int) ($row['system_id'] ?? 0) !== self::ZARZAKH_SYSTEM_ID) {
                continue;
            }

            $shipKills = (int) ($row['ship_kills'] ?? 0);
            $podKills = (int) ($row['pod_kills'] ?? 0);
            break;
        }

        return [
            'data' => array_merge($static['data'], [
                'activity' => [
                    'ship_kills' => $shipKills,
                    'pod_kills' => $podKills,
                    'window' => 'last_hour',
                    'updated_at' => $killsResult['meta']['last_modified']
                        ?? $killsResult['meta']['fetched_at']
                        ?? gmdate('c'),
                ],
            ]),
            'meta' => [
                'generated_at' => gmdate('c'),
                'static_cache' => $static['meta']['cache'],
                'activity_cache' => $killsResult['meta']['cache'] ?? null,
            ],
            'sources' => array_merge(
                $static['sources'],
                [$this->sources->esi('/universe/system_kills/', $killsResult['meta'])]
            ),
        ];
    }

    private function staticOverlay(): array
    {
        $cacheKey = 'zarzakh-system-overlay-static-v1';
        $cached = $this->derivedCache->read($cacheKey);
        $now = time();

        if ($cached !== null && $this->derivedCache->isFresh($cached, $now)) {
            return $this->staticResultFromCache($cached, 'HIT');
        }

        try {
            $built = $this->buildStaticOverlay();
            $entry = [
                'data' => $built['data'],
                'sources' => $built['sources'],
                'fetched_at' => $now,
                'expires_at' => $now + 86400,
            ];
            $this->derivedCache->write($cacheKey, $entry);

            return $this->staticResultFromCache($entry, 'MISS');
        } catch (Throwable $e) {
            if ($cached !== null && $this->derivedCache->isUsableStale($cached, 604800, $now)) {
                $result = $this->staticResultFromCache($cached, 'STALE');
                $result['meta']['warning'] = $e->getMessage();
                return $result;
            }

            throw $e;
        }
    }

    private function buildStaticOverlay(): array
    {
        $systemEndpoint = '/universe/systems/' . self::ZARZAKH_SYSTEM_ID . '/';
        $systemResult = $this->esi->getJson(
            $systemEndpoint,
            ['language' => 'en'],
            86400
        );
        $system = (array) $systemResult['data'];

        if ((int) ($system['system_id'] ?? 0) !== self::ZARZAKH_SYSTEM_ID) {
            throw new RuntimeException('ESI did not return the expected Zarzakh solar system.');
        }

        $gateIds = array_values(array_map('intval', (array) ($system['stargates'] ?? [])));
        $stationIds = array_values(array_map('intval', (array) ($system['stations'] ?? [])));

        $gateRequests = [];
        foreach ($gateIds as $gateId) {
            $gateRequests[] = [
                'key' => (string) $gateId,
                'path' => '/universe/stargates/' . $gateId . '/',
                'fallback_ttl_seconds' => 86400,
            ];
        }
        $gateResults = $this->esi->getJsonBatch($gateRequests);

        $destinationIds = [];
        $rawGates = [];
        foreach ($gateResults as $result) {
            $gate = (array) $result['data'];
            $gateId = (int) ($gate['stargate_id'] ?? 0);
            if ($gateId === 0) {
                continue;
            }

            $rawGates[$gateId] = $gate;
            $destinationId = (int) ($gate['destination']['system_id'] ?? 0);
            if ($destinationId > 0) {
                $destinationIds[$destinationId] = true;
            }
        }

        $destinationRequests = [];
        foreach (array_keys($destinationIds) as $destinationId) {
            $destinationRequests[] = [
                'key' => (string) $destinationId,
                'path' => '/universe/systems/' . (int) $destinationId . '/',
                'query' => ['language' => 'en'],
                'fallback_ttl_seconds' => 86400,
            ];
        }
        $destinationResults = $this->esi->getJsonBatch($destinationRequests);
        $destinationNames = [];
        foreach ($destinationResults as $result) {
            $row = (array) $result['data'];
            $id = (int) ($row['system_id'] ?? 0);
            if ($id > 0) {
                $destinationNames[$id] = (string) ($row['name'] ?? $id);
            }
        }

        $stationRequests = [];
        foreach ($stationIds as $stationId) {
            $stationRequests[] = [
                'key' => (string) $stationId,
                'path' => '/universe/stations/' . $stationId . '/',
                'fallback_ttl_seconds' => 86400,
            ];
        }
        $stationResults = $this->esi->getJsonBatch($stationRequests);

        $objects = [];
        $objects[] = [
            'id' => 'system-origin',
            'type' => 'star',
            'name' => (string) ($system['name'] ?? 'Zarzakh'),
            'position' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        ];

        foreach ($rawGates as $gateId => $gate) {
            $destinationId = (int) ($gate['destination']['system_id'] ?? 0);
            $destinationName = isset($destinationNames[$destinationId])
                ? $destinationNames[$destinationId]
                : (string) $destinationId;

            $objects[] = [
                'id' => 'gate-' . $gateId,
                'type' => 'stargate',
                'name' => $destinationName . ' Gate',
                'destination_system_id' => $destinationId,
                'destination_system_name' => $destinationName,
                'position' => $gate['position'] ?? ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            ];
        }

        foreach ($stationResults as $result) {
            $station = (array) $result['data'];
            $stationId = (int) ($station['station_id'] ?? 0);
            if ($stationId === 0) {
                continue;
            }

            $name = (string) ($station['name'] ?? ('Station ' . $stationId));
            // Keep the official ESI name intact in data, while the overlay can
            // shorten the well-known destination visually to "THE FULCRUM".
            $objects[] = [
                'id' => 'station-' . $stationId,
                'type' => 'station',
                'name' => $name,
                'position' => $station['position'] ?? ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            ];
        }

        return [
            'data' => [
                'system' => [
                    'id' => self::ZARZAKH_SYSTEM_ID,
                    'name' => (string) ($system['name'] ?? 'Zarzakh'),
                    'security' => (float) ($system['security_status'] ?? -1.0),
                ],
                'objects' => $objects,
                'counts' => [
                    'stargates' => count($rawGates),
                    'stations' => count($stationResults),
                ],
            ],
            'sources' => [[
                'name' => 'CCP ESI',
                'publisher' => 'CCP Games',
                'endpoint' => '/universe/systems/, /universe/stargates/, /universe/stations/',
                'updated_at' => $systemResult['meta']['last_modified']
                    ?? $systemResult['meta']['fetched_at']
                    ?? gmdate('c'),
                'retrieved_at' => $systemResult['meta']['fetched_at'] ?? null,
                'cache' => 'DERIVED',
                'stale' => false,
                'compatibility_date' => $systemResult['meta']['compatibility_date_matched']
                    ?? $systemResult['meta']['compatibility_date_requested']
                    ?? null,
            ]],
        ];
    }

    private function staticResultFromCache(array $entry, string $cacheState): array
    {
        return [
            'data' => isset($entry['data']) && is_array($entry['data']) ? $entry['data'] : [],
            'sources' => isset($entry['sources']) && is_array($entry['sources']) ? $entry['sources'] : [],
            'meta' => [
                'cache' => $cacheState,
                'built_at' => isset($entry['fetched_at']) ? gmdate('c', (int) $entry['fetched_at']) : null,
                'expires_at' => isset($entry['expires_at']) ? gmdate('c', (int) $entry['expires_at']) : null,
            ],
        ];
    }
}
