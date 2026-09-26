<?php

// Guristas.net Guristas Frontlines/Insurgency service - PHP 8.0 compatible

declare(strict_types=1);

final class GuristasFrontlinesService
{
    public const GURISTAS_FACTION_ID = 500010;
    public const WAR_REPORT_URL = 'https://www.eveonline.com/api/warzone';
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
     * is deliberately tolerant and the API endpoint exposes parser diagnostics.
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

            if ($records === []) {
                $primaryWarning =
                    'EVE Online Frontlines returned JSON, but its current undocumented schema '
                    . 'did not expose recognizable Guristas campaign records.';
            }
        } catch (Throwable $e) {
            $primaryWarning = 'EVE Online Frontlines unavailable: ' . $e->getMessage();
        }

        if ($records === []) {
            $sourceMode = 'everef_fallback';
            $report = $this->eveRefWarzoneReport();
            $parsed = $this->parseGuristasSystems($report['data']);
            $records = $parsed['systems'];
        }

        if ($records === []) {
            throw new RuntimeException(
                'Neither the EVE Online Frontlines response nor the EVE Ref warzone-insurgency '
                . 'mirror contained recognizable Guristas insurgency systems.'
            );
        }

        $records = $this->resolveMissingSystemIds($records);
        $records = array_values(array_filter($records, static function (array $row): bool {
            return isset($row['system_id']) && (int) $row['system_id'] > 0;
        }));

        if ($records === []) {
            throw new RuntimeException('Guristas insurgency systems were found, but none could be resolved to EVE solar-system IDs.');
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
        $cacheKey = 'eve-frontlines-war-report-v1';
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

        $ch = curl_init(self::WAR_REPORT_URL);
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
            $entry['expires_at'] = $now + 300;
            $this->webCache->write($cacheKey, $entry);
            return $this->warReportFromCache($entry, 'REVALIDATED', false);
        }

        if ($body === false || $status < 200 || $status >= 300) {
            if ($cached !== null && $this->webCache->isUsableStale($cached, 3600, $now)) {
                $result = $this->warReportFromCache($cached, 'STALE', true);
                $result['meta']['warning'] = $error !== ''
                    ? $error
                    : ('Frontlines War Report returned HTTP ' . $status . '.');
                return $result;
            }

            throw new RuntimeException(
                'Unable to retrieve the EVE Frontlines War Report: '
                . ($error !== '' ? $error : ('HTTP ' . $status))
            );
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The EVE Frontlines War Report did not return valid JSON.');
        }

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

    private function eveRefWarzoneReport(): array
    {
        $cacheKey = 'everef-warzone-insurgency-current-v1';
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

        $ch = curl_init(self::EVE_REF_WARZONE_URL);
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
            $entry['expires_at'] = $now + 300;
            $this->webCache->write($cacheKey, $entry);
            return $this->warReportFromCache($entry, 'REVALIDATED', false);
        }

        if ($body === false || $status < 200 || $status >= 300) {
            if ($cached !== null && $this->webCache->isUsableStale($cached, 3600, $now)) {
                $result = $this->warReportFromCache($cached, 'STALE', true);
                $result['meta']['warning'] = $error !== ''
                    ? $error
                    : ('EVE Ref warzone-insurgency returned HTTP ' . $status . '.');
                return $result;
            }

            throw new RuntimeException(
                'Unable to retrieve the EVE Ref warzone-insurgency dataset: '
                . ($error !== '' ? $error : ('HTTP ' . $status))
            );
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('EVE Ref warzone-insurgency did not return valid JSON.');
        }

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
        /*
         * Current Frontlines /api/warzone campaign schema (2026):
         *
         * [
         *   {
         *     "campaignId": 159,
         *     "pirateFactionId": 500010,
         *     "originSolarSystem": {"id": 30000000, "name": "..."},
         *     "insurgencies": [
         *       {
         *         "corruptionPercentage": 93.03,
         *         "corruptionState": 4,
         *         "suppressionPercentage": 100,
         *         "suppressionState": 5,
         *         "solarSystem": {"id": 30000001, "name": "..."}
         *       }
         *     ]
         *   }
         * ]
         *
         * The endpoint is an official CCP web endpoint but not a documented ESI
         * contract, so we first parse this known campaign shape and then retain
         * the older recursive parser as a fallback.
         */
        $campaigns = [];
        $this->findInsurgencyCampaigns($document, $campaigns, 0);

        $records = [];
        $guristasCampaigns = 0;

        foreach ($campaigns as $campaign) {
            $factionId = $this->extractPirateFactionId($campaign);
            if ($factionId !== self::GURISTAS_FACTION_ID) {
                continue;
            }

            $guristasCampaigns++;
            $origin = $this->extractSolarSystemContainer($campaign, [
                'originSolarSystem',
                'origin_system',
                'originSystem',
                'fobSolarSystem',
                'fobSystem',
            ]);
            $originId = isset($origin['id']) ? (int) $origin['id'] : null;

            $rows = $this->getArrayByNormalizedKey($campaign, [
                'insurgencies',
                'systems',
                'solarSystems',
            ]);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $solar = $this->extractSolarSystemContainer($row, [
                    'solarSystem',
                    'system',
                ]);

                $systemId = isset($solar['id']) && is_numeric($solar['id'])
                    ? (int) $solar['id']
                    : $this->extractSystemId($row, null);
                $name = isset($solar['name']) && is_string($solar['name'])
                    ? trim((string) $solar['name'])
                    : $this->extractSystemName($row);

                if (($systemId === null || $systemId <= 0) && ($name === null || $name === '')) {
                    continue;
                }

                $corruptionStage = $this->numericField($row, [
                    'corruptionState',
                    'corruptionStage',
                    'corruptionLevel',
                ]);
                $corruptionPercent = $this->numericField($row, [
                    'corruptionPercentage',
                    'corruptionPercent',
                    'corruptionProgress',
                ]);
                $suppressionStage = $this->numericField($row, [
                    'suppressionState',
                    'suppressionStage',
                    'suppressionLevel',
                ]);
                $suppressionPercent = $this->numericField($row, [
                    'suppressionPercentage',
                    'suppressionPercent',
                    'suppressionProgress',
                ]);

                if ($corruptionStage === null && $corruptionPercent === null) {
                    $metric = $this->extractMetric($row, 'corruption');
                    $corruptionStage = $metric['stage'];
                    $corruptionPercent = $metric['percent'];
                }
                if ($suppressionStage === null && $suppressionPercent === null) {
                    $metric = $this->extractMetric($row, 'suppression');
                    $suppressionStage = $metric['stage'];
                    $suppressionPercent = $metric['percent'];
                }

                $occupierFactionId = isset($solar['occupierFactionId']) && is_numeric($solar['occupierFactionId'])
                    ? (int) $solar['occupierFactionId']
                    : null;
                $ownerFactionId = isset($solar['ownerFactionId']) && is_numeric($solar['ownerFactionId'])
                    ? (int) $solar['ownerFactionId']
                    : null;

                $records[] = [
                    'system_id' => $systemId,
                    'name' => $name,
                    'corruption_stage' => $corruptionStage !== null ? (int) round($corruptionStage) : null,
                    'corruption_percent' => $corruptionPercent !== null ? (float) $corruptionPercent : null,
                    'suppression_stage' => $suppressionStage !== null ? (int) round($suppressionStage) : null,
                    'suppression_percent' => $suppressionPercent !== null ? (float) $suppressionPercent : null,
                    'occupier_faction_id' => $occupierFactionId,
                    'owner_faction_id' => $ownerFactionId,
                    'is_fob' => ($originId !== null && $systemId !== null && $systemId === $originId)
                        || $this->isFobRecord($row),
                ];
            }
        }

        if ($records !== []) {
            $records = $this->mergeSystemRecords($records);

            return [
                'systems' => $records,
                'diagnostics' => [
                    'candidate_campaigns' => count($campaigns),
                    'guristas_campaigns' => $guristasCampaigns,
                    'unique_systems' => count($records),
                    'parser_version' => 2,
                    'parser_mode' => 'frontlines_campaign_schema',
                ],
            ];
        }

        // Fallback for older/alternate versions of the undocumented response.
        $candidates = [];
        $this->walkWarReport($document, false, null, $candidates, 0);
        $systems = $this->mergeSystemRecords($candidates);

        return [
            'systems' => $systems,
            'diagnostics' => [
                'candidate_campaigns' => count($campaigns),
                'guristas_campaigns' => $guristasCampaigns,
                'candidate_records' => count($candidates),
                'unique_systems' => count($systems),
                'parser_version' => 2,
                'parser_mode' => 'recursive_fallback',
            ],
        ];
    }

    private function findInsurgencyCampaigns($node, array &$campaigns, int $depth): void
    {
        if ($depth > 18 || !is_array($node)) {
            return;
        }

        $hasInsurgencies = $this->hasNormalizedKey($node, 'insurgencies');
        $hasFaction = $this->hasNormalizedKey($node, 'piratefactionid')
            || $this->hasNormalizedKey($node, 'piratefaction')
            || $this->hasNormalizedKey($node, 'factionid');

        if ($hasInsurgencies && $hasFaction) {
            $campaigns[] = $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->findInsurgencyCampaigns($value, $campaigns, $depth + 1);
            }
        }
    }

    private function extractPirateFactionId(array $node): ?int
    {
        foreach ($node as $key => $value) {
            $normalized = $this->normalizeKey((string) $key);
            if (!in_array($normalized, ['piratefactionid', 'factionid', 'insurgentfactionid'], true)) {
                continue;
            }
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        foreach ($node as $key => $value) {
            $normalized = $this->normalizeKey((string) $key);
            if (strpos($normalized, 'faction') === false || !is_array($value)) {
                continue;
            }
            foreach ($value as $subKey => $subValue) {
                if ($this->normalizeKey((string) $subKey) === 'id' && is_numeric($subValue)) {
                    return (int) $subValue;
                }
            }
        }

        return null;
    }

    private function extractSolarSystemContainer(array $node, array $keys): array
    {
        $wanted = array_map(function (string $key): string {
            return $this->normalizeKey($key);
        }, $keys);

        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (in_array($this->normalizeKey((string) $key), $wanted, true)) {
                return $value;
            }
        }

        return [];
    }

    private function getArrayByNormalizedKey(array $node, array $keys): array
    {
        $wanted = array_map(function (string $key): string {
            return $this->normalizeKey($key);
        }, $keys);

        foreach ($node as $key => $value) {
            if (in_array($this->normalizeKey((string) $key), $wanted, true) && is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    private function hasNormalizedKey(array $node, string $wanted): bool
    {
        foreach ($node as $key => $_value) {
            if ($this->normalizeKey((string) $key) === $wanted) {
                return true;
            }
        }
        return false;
    }

    private function numericField(array $node, array $keys): ?float
    {
        $wanted = array_map(function (string $key): string {
            return $this->normalizeKey($key);
        }, $keys);

        foreach ($node as $key => $value) {
            if (!in_array($this->normalizeKey((string) $key), $wanted, true)) {
                continue;
            }
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
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

    private function walkWarReport($node, bool $guristasContext, $parentKey, array &$out, int $depth): void
    {
        if ($depth > 18 || !is_array($node)) {
            return;
        }

        $localGuristas = $guristasContext || $this->containsGuristasMarker($node);
        $systemId = $this->extractSystemId($node, $parentKey);
        $name = $this->extractSystemName($node);
        $corruption = $this->extractMetric($node, 'corruption');
        $suppression = $this->extractMetric($node, 'suppression');
        $hasInsurgencyMetric = $corruption['found'] || $suppression['found'];

        if ($localGuristas && $hasInsurgencyMetric && ($systemId !== null || $name !== null)) {
            $out[] = [
                'system_id' => $systemId,
                'name' => $name,
                'corruption_stage' => $corruption['stage'],
                'corruption_percent' => $corruption['percent'],
                'suppression_stage' => $suppression['stage'],
                'suppression_percent' => $suppression['percent'],
                'is_fob' => $this->isFobRecord($node),
            ];
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->walkWarReport($value, $localGuristas, $key, $out, $depth + 1);
            }
        }
    }

    private function containsGuristasMarker(array $node): bool
    {
        foreach ($node as $key => $value) {
            $normalizedKey = $this->normalizeKey((string) $key);

            if (is_scalar($value) || $value === null) {
                $stringValue = strtolower((string) $value);
                if (strpos($stringValue, 'guristas') !== false || strpos($stringValue, 'commando guri') !== false) {
                    return true;
                }

                if (strpos($normalizedKey, 'faction') !== false && (int) $value === self::GURISTAS_FACTION_ID) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractSystemId(array $node, $parentKey): ?int
    {
        if (is_string($parentKey) && preg_match('/^30\\d{6}$/', $parentKey)) {
            return (int) $parentKey;
        }

        $preferred = [
            'solarsystemid',
            'systemid',
            'solarsystem',
        ];

        foreach ($node as $key => $value) {
            $normalized = $this->normalizeKey((string) $key);
            if (!in_array($normalized, $preferred, true)) {
                continue;
            }

            if (is_numeric($value)) {
                $id = (int) $value;
                if ($id >= 30000000 && $id <= 31999999) {
                    return $id;
                }
            }

            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $subNormalized = $this->normalizeKey((string) $subKey);
                    if (($subNormalized === 'id' || $subNormalized === 'systemid' || $subNormalized === 'solarsystemid') && is_numeric($subValue)) {
                        $id = (int) $subValue;
                        if ($id >= 30000000 && $id <= 31999999) {
                            return $id;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function extractSystemName(array $node): ?string
    {
        $preferred = ['solarsystemname', 'systemname'];
        foreach ($node as $key => $value) {
            $normalized = $this->normalizeKey((string) $key);
            if (in_array($normalized, $preferred, true) && is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        foreach (['solarSystem', 'system'] as $containerKey) {
            if (!isset($node[$containerKey]) || !is_array($node[$containerKey])) {
                continue;
            }
            if (isset($node[$containerKey]['name']) && is_string($node[$containerKey]['name'])) {
                return trim($node[$containerKey]['name']);
            }
        }

        return null;
    }

    private function extractMetric(array $node, string $metricName): array
    {
        $result = [
            'found' => false,
            'stage' => null,
            'percent' => null,
        ];

        foreach ($node as $key => $value) {
            $normalized = $this->normalizeKey((string) $key);
            if (strpos($normalized, $metricName) === false) {
                continue;
            }

            $result['found'] = true;
            $this->applyMetricValue($result, $value, $normalized);
        }

        return $result;
    }

    private function applyMetricValue(array &$result, $value, string $keyHint): void
    {
        if (is_numeric($value)) {
            $number = (float) $value;
            if (strpos($keyHint, 'stage') !== false || strpos($keyHint, 'level') !== false) {
                $result['stage'] = (int) round($number);
                if ($result['percent'] === null && $number >= 0 && $number <= 5) {
                    $result['percent'] = $number * 20.0;
                }
                return;
            }

            if ($number >= 0 && $number <= 1) {
                $result['percent'] = $number * 100.0;
            } elseif ($number >= 0 && $number <= 5 && floor($number) === $number) {
                $result['stage'] = (int) $number;
                $result['percent'] = $number * 20.0;
            } elseif ($number >= 0 && $number <= 100) {
                $result['percent'] = $number;
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $subKey => $subValue) {
            $normalized = $this->normalizeKey((string) $subKey);
            if (!is_numeric($subValue)) {
                continue;
            }

            $number = (float) $subValue;
            if (strpos($normalized, 'stage') !== false || strpos($normalized, 'level') !== false) {
                $result['stage'] = (int) round($number);
            } elseif (
                strpos($normalized, 'percent') !== false
                || strpos($normalized, 'progress') !== false
                || strpos($normalized, 'value') !== false
                || strpos($normalized, 'score') !== false
            ) {
                $result['percent'] = ($number >= 0 && $number <= 1)
                    ? $number * 100.0
                    : $number;
            }
        }

        if ($result['percent'] === null && $result['stage'] !== null) {
            $result['percent'] = max(0.0, min(100.0, (float) $result['stage'] * 20.0));
        }
    }

    private function isFobRecord(array $node): bool
    {
        foreach ($node as $key => $value) {
            $normalized = $this->normalizeKey((string) $key);
            if (strpos($normalized, 'fob') === false && strpos($normalized, 'forwardoperatingbase') === false) {
                continue;
            }

            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value !== 0;
            }
            if (is_string($value)) {
                $lower = strtolower(trim($value));
                return in_array($lower, ['true', 'yes', 'active', 'fob'], true);
            }
        }

        return false;
    }

    private function resolveMissingSystemIds(array $records): array
    {
        $names = [];
        foreach ($records as $record) {
            if (!empty($record['system_id']) || empty($record['name'])) {
                continue;
            }
            $names[] = (string) $record['name'];
        }

        $names = array_values(array_unique($names));
        if ($names === []) {
            return $records;
        }

        $resolved = $this->resolveNamesViaEsi($names);
        foreach ($records as &$record) {
            if (!empty($record['system_id']) || empty($record['name'])) {
                continue;
            }
            $key = strtolower((string) $record['name']);
            if (isset($resolved[$key])) {
                $record['system_id'] = $resolved[$key]['id'];
                $record['name'] = $resolved[$key]['name'];
            }
        }
        unset($record);

        return $records;
    }

    private function resolveNamesViaEsi(array $names): array
    {
        $cacheKey = 'frontlines-name-resolution|' . implode('|', array_map('strtolower', $names));
        $cached = $this->derivedCache->read($cacheKey);
        $now = time();
        if ($cached !== null && $this->derivedCache->isFresh($cached, $now)) {
            return isset($cached['data']) && is_array($cached['data']) ? $cached['data'] : [];
        }

        $url = rtrim((string) $this->config['base_url'], '/')
            . '/universe/ids/?datasource=' . rawurlencode((string) ($this->config['datasource'] ?? 'tranquility'));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) ($this->config['connect_timeout_seconds'] ?? 5),
            CURLOPT_TIMEOUT => (int) ($this->config['request_timeout_seconds'] ?? 15),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(array_values($names), JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Compatibility-Date: ' . (string) $this->config['compatibility_date'],
                'User-Agent: ' . (string) $this->config['user_agent'],
            ],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(
                'Unable to resolve Frontlines solar-system names through ESI: '
                . ($error !== '' ? $error : ('HTTP ' . $status))
            );
        }

        $decoded = json_decode((string) $body, true);
        $resolved = [];
        foreach ((array) ($decoded['systems'] ?? []) as $row) {
            if (!isset($row['id'], $row['name'])) {
                continue;
            }
            $resolved[strtolower((string) $row['name'])] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        $this->derivedCache->write($cacheKey, [
            'data' => $resolved,
            'fetched_at' => $now,
            'expires_at' => $now + 86400,
        ]);

        return $resolved;
    }

    private function topology(array $records): array
    {
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

    private function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));
    }
}
