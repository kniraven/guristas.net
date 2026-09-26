<?php

// Guristas.net Venal stream overlay API - PHP 8.0 compatible

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=240');

$root = dirname(__DIR__, 3);

require_once $root . '/app/services/EsiCache.php';
require_once $root . '/app/services/EsiClient.php';
require_once $root . '/app/services/SourceService.php';
require_once $root . '/app/services/VenalService.php';
require_once $root . '/app/services/VenalActivityHistory.php';

try {
    $config = require $root . '/config/esi.php';

    $esiCache = new GuristasEsiCache($config['cache_dir']);
    $derivedCache = new GuristasEsiCache($config['derived_cache_dir']);
    $esi = new GuristasEsiClient($config, $esiCache);
    $sourceService = new GuristasSourceService();
    $venal = new GuristasVenalService($esi, $sourceService, $derivedCache);

    // Keep topology on the long-lived derived cache, but fetch the much smaller
    // hourly activity feed independently so this endpoint remains lightweight.
    $static = $venal->staticMap();
    $activity = $venal->activity($static['data']['systems']);

    $killsBySystem = [];
    foreach ($activity['data']['systems'] as $row) {
        $killsBySystem[(int) $row['id']] = (int) ($row['activity']['ship_kills'] ?? 0);
    }

    $systems = [];
    foreach ($static['data']['systems'] as $system) {
        $id = (int) $system['id'];

        $systems[] = [
            'id' => $id,
            'name' => (string) $system['name'],
            'security' => (float) $system['security'],
            'position' => $system['position'],
            'ship_kills' => isset($killsBySystem[$id]) ? $killsBySystem[$id] : 0,
        ];
    }

    // Preserve the site's rolling hourly aggregate history. The history writer
    // is duplicate-safe per hour, so a stream overlay polling every few minutes
    // does not create multiple retained snapshots for the same hour.
    $historyWarning = null;
    try {
        $historyMap = $static['data'];
        $activityBySystem = [];
        foreach ($activity['data']['systems'] as $row) {
            $activityBySystem[(int) $row['id']] = $row['activity'];
        }

        foreach ($historyMap['systems'] as &$historySystem) {
            $historySystemId = (int) $historySystem['id'];
            $historySystem['activity'] = isset($activityBySystem[$historySystemId])
                ? $activityBySystem[$historySystemId]
                : [
                    'ship_jumps' => 0,
                    'ship_kills' => 0,
                    'pod_kills' => 0,
                    'npc_kills' => 0,
                ];
        }
        unset($historySystem);

        $history = new GuristasVenalActivityHistory(
            $root . '/storage/history/venal',
            720
        );
        $history->record(
            $historyMap,
            isset($activity['meta']['updated_at'])
                ? (string) $activity['meta']['updated_at']
                : null
        );
    } catch (Throwable $historyError) {
        $historyWarning = $historyError->getMessage();
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'service' => 'Guristas.net Venal stream overlay',
        'generated_at' => gmdate('c'),
        'data' => [
            'systems' => $systems,
            'edges' => $static['data']['edges'],
            'activity' => [
                'metric' => 'ship_kills',
                'window' => 'last_hour',
                'updated_at' => $activity['meta']['updated_at'],
            ],
        ],
        'meta' => [
            'static_cache' => $static['meta']['cache'],
            'activity_cache' => $activity['meta']['cache'],
            'history_warning' => $historyWarning,
        ],
        'sources' => $activity['sources'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'service' => 'Guristas.net Venal stream overlay',
        'generated_at' => gmdate('c'),
        'error' => [
            'type' => get_class($e),
            'message' => $e->getMessage(),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
