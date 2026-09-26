<?php

// Guristas.net data foundation - PHP 8.0 compatible build 2

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__, 2);

require_once $root . '/app/services/EsiCache.php';
require_once $root . '/app/services/EsiClient.php';
require_once $root . '/app/services/SourceService.php';
require_once $root . '/app/services/UniverseService.php';

try {
    $config = require $root . '/config/esi.php';

    $cache = new GuristasEsiCache($config['cache_dir']);
    $esi = new GuristasEsiClient($config, $cache);
    $sourceService = new GuristasSourceService();
    $universe = new GuristasUniverseService($esi, $sourceService);

    $status = $universe->tranquilityStatus();
    $venal = $universe->venal();

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'service' => 'Guristas.net data foundation',
        'generated_at' => gmdate('c'),
        'runtime' => [
            'php_version' => PHP_VERSION,
            'curl_loaded' => extension_loaded('curl'),
        ],
        'esi' => [
            'tranquility' => $status,
            'venal' => $venal,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'service' => 'Guristas.net data foundation',
        'generated_at' => gmdate('c'),
        'runtime' => [
            'php_version' => PHP_VERSION,
            'curl_loaded' => extension_loaded('curl'),
        ],
        'error' => [
            'type' => get_class($e),
            'message' => $e->getMessage(),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
