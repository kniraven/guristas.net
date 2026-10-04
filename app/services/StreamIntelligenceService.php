<?php

// Guristas.net combined stream intelligence snapshot - PHP 8.0 compatible

declare(strict_types=1);

final class GuristasStreamIntelligenceService
{
    public const ZARZAKH_SYSTEM_ID = 30100000;
    public const CALDARI_FACTION_ID = 500001;
    public const GALLENTE_FACTION_ID = 500004;

    /** @var GuristasEsiClient */
    private $esi;

    /** @var GuristasVenalService */
    private $venal;

    /** @var GuristasFrontlinesService */
    private $frontlines;

    public function __construct(
        GuristasEsiClient $esi,
        GuristasVenalService $venal,
        GuristasFrontlinesService $frontlines
    ) {
        $this->esi = $esi;
        $this->venal = $venal;
        $this->frontlines = $frontlines;
    }

    public function snapshot(): array
    {
        $venalStatic = $this->venal->staticMap();
        $warzone = $this->frontlines->guristasOverlay();
        $killsResult = $this->esi->getJson('/universe/system_kills/', [], 300);
        $killMap = $this->buildKillMap((array) $killsResult['data']);

        $venalSystems = [];
        foreach ((array) ($venalStatic['data']['systems'] ?? []) as $system) {
            $id = (int) ($system['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $venalSystems[] = [
                'id' => $id,
                'name' => (string) ($system['name'] ?? $id),
                'security' => (float) ($system['security'] ?? 0.0),
                'position' => $system['position'] ?? ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
                'ship_kills' => (int) ($killMap[$id]['ship_kills'] ?? 0),
            ];
        }

        $warSystems = [];
        foreach ((array) ($warzone['data']['systems'] ?? []) as $system) {
            $id = (int) ($system['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $occupier = isset($system['occupier_faction_id']) && is_numeric($system['occupier_faction_id'])
                ? (int) $system['occupier_faction_id']
                : null;
            $owner = isset($system['owner_faction_id']) && is_numeric($system['owner_faction_id'])
                ? (int) $system['owner_faction_id']
                : null;
            $corruption = isset($system['corruption_percent']) && is_numeric($system['corruption_percent'])
                ? (float) $system['corruption_percent']
                : null;
            $suppression = isset($system['suppression_percent']) && is_numeric($system['suppression_percent'])
                ? (float) $system['suppression_percent']
                : null;

            $warSystems[] = [
                'id' => $id,
                'name' => (string) ($system['name'] ?? $id),
                'security' => (float) ($system['security'] ?? 0.0),
                'position' => $system['position'] ?? ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
                'constellation_id' => $system['constellation_id'] ?? null,
                'constellation_name' => $system['constellation_name'] ?? null,
                'region_id' => $system['region_id'] ?? null,
                'region_name' => $system['region_name'] ?? null,
                'corruption_stage' => $system['corruption_stage'] ?? null,
                'corruption_percent' => $corruption,
                'suppression_stage' => $system['suppression_stage'] ?? null,
                'suppression_percent' => $suppression,
                'occupier_faction_id' => $occupier,
                'owner_faction_id' => $owner,
                'empire_side' => $this->empireSide($occupier, $owner),
                'winner' => $this->winner($corruption, $suppression, $occupier, $owner),
                'is_fob' => !empty($system['is_fob']),
                'ship_kills' => (int) ($killMap[$id]['ship_kills'] ?? 0),
            ];
        }

        $zarzakhKills = (int) ($killMap[self::ZARZAKH_SYSTEM_ID]['ship_kills'] ?? 0);

        $updatedAt = $killsResult['meta']['last_modified']
            ?? $killsResult['meta']['fetched_at']
            ?? gmdate('c');

        return [
            'data' => [
                'venal' => [
                    'systems' => $venalSystems,
                    'edges' => $venalStatic['data']['edges'] ?? [],
                ],
                'warzone' => [
                    'systems' => $warSystems,
                    'edges' => $warzone['data']['edges'] ?? [],
                    'regions' => array_values(array_unique(array_values(array_filter(array_map(static function (array $row) {
                        return isset($row['region_name']) && is_string($row['region_name']) && trim($row['region_name']) !== ''
                            ? trim($row['region_name'])
                            : null;
                    }, $warSystems))))),
                    'updated_at' => $warzone['data']['updated_at'] ?? null,
                    'source_mode' => $warzone['meta']['source_mode'] ?? null,
                    'stale' => $warzone['meta']['stale'],
                    'last_success_at' => $warzone['meta']['last_success_at'],
                    'warning' => $warzone['meta']['warning'],
                ],
                'zarzakh' => [
                    'id' => self::ZARZAKH_SYSTEM_ID,
                    'name' => 'Zarzakh',
                    'ship_kills' => $zarzakhKills,
                ],
                'activity' => [
                    'metric' => 'ship_kills',
                    'window' => 'last_hour',
                    'updated_at' => $updatedAt,
                ],
            ],
            'meta' => [
                'generated_at' => gmdate('c'),
                'venal_static_cache' => $venalStatic['meta']['cache'] ?? null,
                'warzone_cache' => $warzone['meta']['war_report_cache'] ?? null,
                'system_kills_cache' => $killsResult['meta']['cache'] ?? null,
            ],
            'sources' => array_values(array_merge(
                (array) ($warzone['sources'] ?? []),
                [[
                    'name' => 'CCP ESI',
                    'publisher' => 'CCP Games',
                    'endpoint' => '/universe/system_kills/',
                    'official' => true,
                    'updated_at' => $updatedAt,
                ]]
            )),
        ];
    }

    private function buildKillMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['system_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = [
                'ship_kills' => (int) ($row['ship_kills'] ?? 0),
                'pod_kills' => (int) ($row['pod_kills'] ?? 0),
                'npc_kills' => (int) ($row['npc_kills'] ?? 0),
            ];
        }
        return $map;
    }

    private function empireSide(?int $occupier, ?int $owner): string
    {
        $id = $occupier ?: $owner;
        if ($id === self::CALDARI_FACTION_ID) {
            return 'caldari';
        }
        if ($id === self::GALLENTE_FACTION_ID) {
            return 'gallente';
        }
        return 'neutral';
    }

    private function winner(?float $corruption, ?float $suppression, ?int $occupier, ?int $owner): string
    {
        if ($corruption === null || $suppression === null) {
            return 'neutral';
        }
        if (abs($corruption - $suppression) < 0.01) {
            return 'neutral';
        }
        if ($corruption > $suppression) {
            return 'guristas';
        }
        return $this->empireSide($occupier, $owner);
    }
}
