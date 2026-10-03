<?php

// Guristas.net current Guristas-corruption stream overlay API - PHP 8.0 compatible

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=240');

$root = dirname(__DIR__, 3);

require_once $root . '/app/services/DataServices.php';

try {
    $services = new GuristasDataServices();
    $frontlines = $services->frontlines();
    $result = $frontlines->guristasOverlay();

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'service' => 'Guristas.net Guristas corruption stream overlay',
        'generated_at' => gmdate('c'),
        'data' => $result['data'],
        'meta' => $result['meta'],
        'sources' => $result['sources'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'service' => 'Guristas.net Guristas corruption stream overlay',
        'generated_at' => gmdate('c'),
        'error' => [
            'type' => get_class($e),
            'message' => $e->getMessage(),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
