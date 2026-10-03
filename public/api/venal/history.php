<?php

// Guristas.net Venal rolling activity history API - PHP 8.0 compatible

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=240');

$root = dirname(__DIR__, 3);

require_once $root . '/app/services/DataServices.php';

try {
    $services = new GuristasDataServices();
    $metric = isset($_GET['metric']) ? (string) $_GET['metric'] : 'ship_kills';
    $hours = isset($_GET['hours']) ? (int) $_GET['hours'] : 1;

    $allowedHours = [1, 3, 6, 12, 24, 72, 168, 720];
    if (!in_array($hours, $allowedHours, true)) {
        throw new InvalidArgumentException('Unsupported time window.');
    }

    $history = $services->history();

    $data = $history->aggregate($metric, $hours);
    $esiEndpoint = $metric === 'ship_jumps'
        ? '/universe/system_jumps/'
        : '/universe/system_kills/';

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'service' => 'Guristas.net Venal activity history',
        'generated_at' => gmdate('c'),
        'data' => $data,
        'sources' => [
            [
                'name' => 'CCP ESI',
                'publisher' => 'CCP Games',
                'official' => true,
                'endpoint' => $esiEndpoint,
                'role' => 'Original one-hour activity aggregates',
            ],
            [
                'name' => 'Guristas.net',
                'publisher' => 'Guristas.net',
                'official' => false,
                'role' => 'Locally stored and summed hourly snapshots',
                'retention' => '30 days',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo json_encode([
        'ok' => false,
        'service' => 'Guristas.net Venal activity history',
        'generated_at' => gmdate('c'),
        'error' => [
            'type' => get_class($e),
            'message' => $e->getMessage(),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
