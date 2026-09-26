<?php

// Guristas.net combined stream intelligence endpoint - PHP 8.0 compatible

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, stale-while-revalidate=240');

$root = dirname(__DIR__, 3);

require_once $root . '/app/services/EsiCache.php';
require_once $root . '/app/services/EsiClient.php';
require_once $root . '/app/services/SourceService.php';
require_once $root . '/app/services/VenalService.php';
require_once $root . '/app/services/FrontlinesService.php';
require_once $root . '/app/services/StreamIntelligenceService.php';

try {
    $config = require $root . '/config/esi.php';
    $esiCache = new GuristasEsiCache($config['cache_dir']);
    $derivedCache = new GuristasEsiCache($config['derived_cache_dir']);
    $frontlinesCache = new GuristasEsiCache($root . '/storage/cache/frontlines');
    $esi = new GuristasEsiClient($config, $esiCache);
    $sources = new GuristasSourceService();
    $venal = new GuristasVenalService($esi, $sources, $derivedCache);
    $frontlines = new GuristasFrontlinesService($esi, $frontlinesCache, $derivedCache, $config);
    $stream = new GuristasStreamIntelligenceService($esi, $venal, $frontlines);
    $result = $stream->snapshot();

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'service' => 'Guristas.net unified stream intelligence',
        'generated_at' => gmdate('c'),
        'data' => $result['data'],
        'meta' => $result['meta'],
        'sources' => $result['sources'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'service' => 'Guristas.net unified stream intelligence',
        'generated_at' => gmdate('c'),
        'error' => [
            'type' => get_class($e),
            'message' => $e->getMessage(),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
