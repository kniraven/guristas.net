<?php

// Guristas.net Guristas Frontlines/Insurgency service - PHP 8.0 compatible

declare(strict_types=1);

final class GuristasFrontlinesService
{
    public const GURISTAS_FACTION_ID = 500010;
    public const WAR_REPORT_URL = 'https://www.eveonline.com/api/warzone/insurgency';
    public const EVE_REF_WARZONE_URL = 'https://data.everef.net/warzone-insurgency/warzone-insurgency-latest.json';

    /** @var GuristasEsiClient */
    private $esi;

    /** @var GuristasEsiCache */
    private $webCache;

    /** @var GuristasEsiCache */
    private $derivedCache;

    /** @var array */
    private $config;

    public function __construct(
        GuristasEsiClient $esi,
        GuristasEsiCache $webCache,
        GuristasEsiCache $derivedCache,
        array $config
    ) {
        $this->esi = $esi;
        $this->webCache = $webCache;
        $this->derivedCache = $derivedCache;
        $this->config = $config;
    }

    /**
     * Current Guristas pirate-insurgency systems rendered using official ESI
     * universe coordinates and live corruption/suppression from EVE's official
     * Frontlines War Report web service.
     *
     * The War Report endpoint is official CCP/EVE Online web data, but it is an
     * undocumented web contract rather than a supported ESI route. The parser
     * accepts the campaign schema and the API endpoint exposes parser diagnostics.
     */
    public function guristasOverlay(): array
    {
        /*
         * Primary source: EVE Online's own Frontlines web endpoint.
         *
         * That endpoint is not a documented third-party API and its response
         * shape can change without notice. If it stops exposing a parseable
         * campaign payload, fall back to EVE Ref's current warzone-insurgency
         * mirror instead of taking the overlay offline.
         */
        $report = null;
        $parsed = null;
        $records = [];
        $sourceMode = 'eve_online_frontlines';
        $primaryWarning = null;

        try {
            $report = $this->warReport();
            $parsed = $this->parseGuristasSystems($report['data']);
            $records = $parsed['systems'];

        } catch (Throwable $e) {
            $primaryWarning = 'EVE Online Frontlines unavailable: ' . $e->getMessage();
            $sourceMode = 'everef_fallback';
            $report = $this->eveRefWarzoneReport();
            $parsed = $this->parseGuristasSystems($report['data']);
            $records = $parsed['systems'];
        }

        $topology = $this->topology($records);
        $recordById = [];
        foreach ($records as $record) {
            $recordById[(int) $record['system_id']] = $record;
        }

        $systems = [];
        foreach ($topology['data']['systems'] as $system) {
            $id = (int) $system['id'];
            $record = isset($recordById[$id]) ? $recordById[$id] : [];

            $system['corruption_stage'] = isset($record['corruption_stage'])
                ? $record['corruption_stage']
                : null;
            $system['corruption_percent'] = isset($record['corruption_percent'])
                ? $record['corruption_percent']
                : null;
            $system['suppression_stage'] = isset($record['suppression_stage'])
                ? $record['suppression_stage']
                : null;
            $system['suppression_percent'] = isset($record['suppression_percent'])
                ? $record['suppression_percent']
                : null;
            $system['occupier_faction_id'] = isset($record['occupier_faction_id'])
                ? $record['occupier_faction_id']
                : null;
            $system['owner_faction_id'] = isset($record['owner_faction_id'])
                ? $record['owner_faction_id']
                : null;
            $system['is_fob'] = !empty($record['is_fob']);

            $systems[] = $system;
        }

        return [
            'data' => [
                'faction' => [
                    'id' => self::GURISTAS_FACTION_ID,
                    'name' => 'Guristas Pirates',
                ],
                'systems' => $systems,
                'edges' => $topology['data']['edges'],
                'counts' => [
                    'systems' => count($systems),
                    'connections' => count($topology['data']['edges']),
                ],
                'updated_at' => $report['meta']['updated_at'],
            ],
            'meta' => [
                'generated_at' => gmdate('c'),
                'war_report_cache' => $report['meta']['cache'],
                'topology_cache' => $topology['meta']['cache'],
                'source_mode' => $sourceMode,
                'primary_warning' => $primaryWarning,
                'parser' => $parsed['diagnostics'],
            ],
            'sources' => array_merge(
                $sourceMode === 'eve_online_frontlines'
                    ? [[
                        'name' => 'EVE Online Frontlines War Report',
                        'publisher' => 'CCP Games',
                        'url' => self::WAR_REPORT_URL,
                        'official' => true,
                        'contract' => 'undocumented web API',
                        'updated_at' => $report['meta']['updated_at'],
                        'retrieved_at' => $report['meta']['retrieved_at'],
                        'cache' => $report['meta']['cache'],
                        'stale' => $report['meta']['stale'],
                    ]]
                    : [[
                        'name' => 'EVE Ref Warzone Insurgency',
                        'publisher' => 'EVE Ref / Autonomous Logic',
                        'url' => self::EVE_REF_WARZONE_URL,
                        'official' => false,
                        'contract' => 'community mirror / derived dataset',
                        'updated_at' => $report['meta']['updated_at'],
                        'retrieved_at' => $report['meta']['retrieved_at'],
                        'cache' => $report['meta']['cache'],
                        'stale' => $report['meta']['stale'],
                    ]],
                [[
                    'name' => 'CCP ESI',
                    'publisher' => 'CCP Games',
                    'endpoint' => '/universe/systems/, /universe/stargates/',
                    'official' => true,
                    'cache' => $topology['meta']['cache'],
                ]]
            ),
        ];
    }

    private function warReport(): array
    {
        return $this->fetchCampaignReport(self::WAR_REPORT_URL, 'eve-frontlines-insurgency-v1');
    }

    private function eveRefWarzoneReport(): array
    {
        return $this->fetchCampaignReport(self::EVE_REF_WARZONE_URL, 'everef-warzone-insurgency-current-v1');
    }

    private function fetchCampaignReport(string $url, string $cacheKey): array
    {
        $cached = $this->webCache->read($cacheKey);
        $now = time();

        if ($cached !== null && $this->webCache->isFresh($cached, $now)) {
            return $this->warReportFromCache($cached, 'HIT', false);
        }

        $headers = [];
        $requestHeaders = [
            'Accept: application/json',
            'User-Agent: ' . (string) ($this->config['user_agent'] ?? 'Guristas.net'),
        ];

        if ($cached !== null && !empty($cached['etag'])) {
            $requestHeaders[] = 'If-None-Match: ' . $cached['etag'];
        }
        if ($cached !== null && !empty($cached['last_modified'])) {
            $requestHeaders[] = 'If-Modified-Since: ' . $cached['last_modified'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => (int) ($this->config['connect_timeout_seconds'] ?? 5),
            CURLOPT_TIMEOUT => (int) ($this->config['request_timeout_seconds'] ?? 15),
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) {
                    return $length;
                }
                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
                return $length;
            },
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status === 304 && $cached !== null) {
            $entry = $cached;
            $entry['fetched_at'] = $now;
            $entry['expires_at'] = $now + $this->cacheTtlFromHeaders($headers, 300);
            $this->webCache->write($cacheKey, $entry);
            return $this->warReportFromCache($entry, 'REVALIDATED', false);
        }

        if ($body === false || $status < 200 || $status >= 300) {
            if ($cached !== null && $this->webCache->isUsableStale($cached, 3600, $now)) {
                $result = $this->warReportFromCache($cached, 'STALE', true);
                $result['meta']['warning'] = $error !== ''
                    ? $error
                    : ('Insurgency feed returned HTTP ' . $status . '.');
                return $result;
            }

            throw new RuntimeException(
                'Unable to retrieve insurgency feed: '
                . ($error !== '' ? $error : ('HTTP ' . $status))
            );
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Insurgency feed did not return valid JSON.');
        }

        // Reject unsupported payloads before replacing the last valid cache entry.
        $this->parseGuristasSystems($decoded);
        $ttl = $this->cacheTtlFromHeaders($headers, 300);
        $entry = [
            'data' => $decoded,
            'fetched_at' => $now,
            'expires_at' => $now + $ttl,
            'etag' => $headers['etag'] ?? null,
            'last_modified' => $headers['last-modified'] ?? null,
        ];
        $this->webCache->write($cacheKey, $entry);

        return $this->warReportFromCache($entry, 'MISS', false);
    }

    private function parseGuristasSystems(array $document): array
    {
        // An empty list is a valid feed between campaigns; other schemas are errors.
        if ($document !== [] && array_keys($document) !== range(0, count($document) - 1)) {
            throw new RuntimeException('Insurgency feed must be a list of campaigns.');
        }
        $records = [];
        $candidateCampaigns = 0;
        $guristasCampaigns = 0;
        foreach ($document as $campaign) {
            if (!is_array($campaign) || !isset($campaign['pirateFactionId'], $campaign['insurgencies'])
                || !is_numeric($campaign['pirateFactionId'])
                || !is_array($campaign['insurgencies'])) {
                throw new RuntimeException('Unsupported insurgency campaign schema.');
            }
            $candidateCampaigns++;
            if ((int) $campaign['pirateFactionId'] !== self::GURISTAS_FACTION_ID) continue;
            $guristasCampaigns++;
            $originId = (int) ($campaign['originSolarSystem']['id'] ?? 0);
            foreach ($campaign['insurgencies'] as $row) {
                if (!is_array($row) || !isset($row['solarSystem']) || !is_array($row['solarSystem'])) continue;
                $solar = $row['solarSystem'];
                $systemId = (int) ($solar['id'] ?? 0);
                if ($systemId <= 0) continue;
                $metrics = [];
                foreach (['corruptionState', 'corruptionPercentage', 'suppressionState', 'suppressionPercentage'] as $field) {
                    $metrics[$field] = isset($row[$field]) && is_numeric($row[$field]) ? (float) $row[$field] : null;
                }
                if ($metrics['corruptionState'] === null && $metrics['corruptionPercentage'] === null
                    && $metrics['suppressionState'] === null && $metrics['suppressionPercentage'] === null) continue;
                $records[] = [
                    'system_id' => $systemId,
                    'name' => isset($solar['name']) && is_string($solar['name']) ? trim($solar['name']) : null,
                    'corruption_stage' => $metrics['corruptionState'] !== null ? (int) round($metrics['corruptionState']) : null,
                    'corruption_percent' => $metrics['corruptionPercentage'],
                    'suppression_stage' => $metrics['suppressionState'] !== null ? (int) round($metrics['suppressionState']) : null,
                    'suppression_percent' => $metrics['suppressionPercentage'],
                    'occupier_faction_id' => isset($solar['occupierFactionId']) && is_numeric($solar['occupierFactionId']) ? (int) $solar['occupierFactionId'] : null,
                    'owner_faction_id' => isset($solar['ownerFactionId']) && is_numeric($solar['ownerFactionId']) ? (int) $solar['ownerFactionId'] : null,
                    'is_fob' => $originId > 0 && $systemId === $originId,
                ];
            }
        }
        $records = $this->mergeSystemRecords($records);
        return [
            'systems' => $records,
            'diagnostics' => [
                'candidate_campaigns' => $candidateCampaigns,
                'guristas_campaigns' => $guristasCampaigns,
                'unique_systems' => count($records),
                'parser_version' => 3,
                'parser_mode' => 'frontlines_campaign_schema',
            ],
        ];
    }

    private function mergeSystemRecords(array $records): array
    {
        $merged = [];

        foreach ($records as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $key = !empty($candidate['system_id'])
                ? 'id:' . (int) $candidate['system_id']
                : 'name:' . strtolower((string) ($candidate['name'] ?? ''));

            if ($key === 'name:') {
                continue;
            }

            if (!isset($merged[$key])) {
                $merged[$key] = $candidate;
                continue;
            }

            foreach ($candidate as $field => $value) {
                if (($merged[$key][$field] ?? null) === null && $value !== null) {
                    $merged[$key][$field] = $value;
                }
                if ($field === 'is_fob' && $value) {
                    $merged[$key][$field] = true;
                }
            }
        }

        $systems = array_values($merged);
        usort($systems, static function (array $a, array $b): int {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $systems;
    }

    private function topology(array $records): array
    {
        if ($records === []) {
            return ['data' => ['systems' => [], 'edges' => []], 'meta' => ['cache' => 'EMPTY']];
        }
        $ids = [];
        foreach ($records as $record) {
            $id = (int) ($record['system_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $signature = hash('sha256', implode(',', $ids));

        // A single cache file is reused as the insurgency moves, so we don't
        // accumulate one derived topology file for every historical warzone.
        $cacheKey = 'guristas-insurgency-topology-v2';
        $cached = $this->derivedCache->read($cacheKey);
        $now = time();
        if (
            $cached !== null
            && $this->derivedCache->isFresh($cached, $now)
            && isset($cached['signature'])
            && hash_equals((string) $cached['signature'], $signature)
        ) {
            return $this->topologyFromCache($cached, 'HIT');
        }

        $systemRequests = [];
        foreach ($ids as $id) {
            $systemRequests[] = [
                'key' => (string) $id,
                'path' => '/universe/systems/' . $id . '/',
                'query' => ['language' => 'en'],
                'fallback_ttl_seconds' => 86400,
            ];
        }
        $systemResults = $this->esi->getJsonBatch($systemRequests);

        $rawSystems = [];
        $gateIds = [];
        $constellationIds = [];
        foreach ($systemResults as $result) {
            $row = (array) $result['data'];
            $id = (int) ($row['system_id'] ?? 0);
            if ($id === 0) {
                continue;
            }
            $rawSystems[$id] = $row;
            $constellationId = (int) ($row['constellation_id'] ?? 0);
            if ($constellationId > 0) {
                $constellationIds[$constellationId] = true;
            }
            foreach ((array) ($row['stargates'] ?? []) as $gateId) {
                $gateIds[(int) $gateId] = true;
            }
        }

        $constellationRequests = [];
        foreach (array_keys($constellationIds) as $constellationId) {
            $constellationRequests[] = [
                'key' => (string) $constellationId,
                'path' => '/universe/constellations/' . (int) $constellationId . '/',
                'query' => ['language' => 'en'],
                'fallback_ttl_seconds' => 86400,
            ];
        }
        $constellationResults = $this->esi->getJsonBatch($constellationRequests);
        $constellations = [];
        $regionIds = [];
        foreach ($constellationResults as $result) {
            $row = (array) $result['data'];
            $constellationId = (int) ($row['constellation_id'] ?? 0);
            if ($constellationId <= 0) {
                continue;
            }
            $constellations[$constellationId] = $row;
            $regionId = (int) ($row['region_id'] ?? 0);
            if ($regionId > 0) {
                $regionIds[$regionId] = true;
            }
        }

        $regionRequests = [];
        foreach (array_keys($regionIds) as $regionId) {
            $regionRequests[] = [
                'key' => (string) $regionId,
                'path' => '/universe/regions/' . (int) $regionId . '/',
                'query' => ['language' => 'en'],
                'fallback_ttl_seconds' => 86400,
            ];
        }
        $regionResults = $this->esi->getJsonBatch($regionRequests);
        $regions = [];
        foreach ($regionResults as $result) {
            $row = (array) $result['data'];
            $regionId = (int) ($row['region_id'] ?? 0);
            if ($regionId <= 0) {
                continue;
            }
            $regions[$regionId] = $row;
        }

        $gateRequests = [];
        foreach (array_keys($gateIds) as $gateId) {
            $gateRequests[] = [
                'key' => (string) $gateId,
                'path' => '/universe/stargates/' . (int) $gateId . '/',
                'fallback_ttl_seconds' => 86400,
            ];
        }
        $gateResults = $this->esi->getJsonBatch($gateRequests);
        $systemSet = array_fill_keys($ids, true);
        $edges = [];

        foreach ($gateResults as $result) {
            $gate = (array) $result['data'];
            $a = (int) ($gate['system_id'] ?? 0);
            $b = (int) ($gate['destination']['system_id'] ?? 0);
            if ($a === 0 || $b === 0 || !isset($systemSet[$a], $systemSet[$b])) {
                continue;
            }
            $low = min($a, $b);
            $high = max($a, $b);
            $aConstellation = (int) ($rawSystems[$a]['constellation_id'] ?? 0);
            $bConstellation = (int) ($rawSystems[$b]['constellation_id'] ?? 0);
            $edges[$low . ':' . $high] = [
                'a' => $low,
                'b' => $high,
                'type' => ($aConstellation > 0 && $bConstellation > 0 && $aConstellation !== $bConstellation)
                    ? 'cross_constellation'
                    : 'same_constellation',
            ];
        }

        $systems = [];
        foreach ($ids as $id) {
            if (!isset($rawSystems[$id])) {
                continue;
            }
            $row = $rawSystems[$id];
            $constellationId = (int) ($row['constellation_id'] ?? 0);
            $constellation = $constellationId > 0 && isset($constellations[$constellationId])
                ? (array) $constellations[$constellationId]
                : [];
            $regionId = (int) ($constellation['region_id'] ?? 0);
            $region = $regionId > 0 && isset($regions[$regionId])
                ? (array) $regions[$regionId]
                : [];
            $systems[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? $id),
                'security' => (float) ($row['security_status'] ?? 0.0),
                'position' => $row['position'] ?? ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
                'constellation_id' => $constellationId > 0 ? $constellationId : null,
                'constellation_name' => isset($constellation['name']) ? (string) $constellation['name'] : null,
                'region_id' => $regionId > 0 ? $regionId : null,
                'region_name' => isset($region['name']) ? (string) $region['name'] : null,
            ];
        }

        $entry = [
            'signature' => $signature,
            'data' => [
                'systems' => $systems,
                'edges' => array_values($edges),
            ],
            'fetched_at' => $now,
            'expires_at' => $now + 21600,
        ];
        $this->derivedCache->write($cacheKey, $entry);

        return $this->topologyFromCache($entry, 'MISS');
    }

    private function topologyFromCache(array $entry, string $cacheState): array
    {
        return [
            'data' => isset($entry['data']) && is_array($entry['data']) ? $entry['data'] : [],
            'meta' => [
                'cache' => $cacheState,
                'built_at' => isset($entry['fetched_at']) ? gmdate('c', (int) $entry['fetched_at']) : null,
            ],
        ];
    }

    private function warReportFromCache(array $entry, string $cacheState, bool $stale): array
    {
        $updatedAt = !empty($entry['last_modified'])
            ? (string) $entry['last_modified']
            : (isset($entry['fetched_at']) ? gmdate('c', (int) $entry['fetched_at']) : gmdate('c'));

        return [
            'data' => isset($entry['data']) && is_array($entry['data']) ? $entry['data'] : [],
            'meta' => [
                'cache' => $cacheState,
                'stale' => $stale,
                'updated_at' => $updatedAt,
                'retrieved_at' => isset($entry['fetched_at']) ? gmdate('c', (int) $entry['fetched_at']) : null,
            ],
        ];
    }

    private function cacheTtlFromHeaders(array $headers, int $fallback): int
    {
        if (isset($headers['cache-control']) && preg_match('/max-age=(\\d+)/i', $headers['cache-control'], $match)) {
            return max(60, min(900, (int) $match[1]));
        }
        return $fallback;
    }

}
