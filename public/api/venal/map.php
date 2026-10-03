<?php

// Guristas.net Venal intelligence API - PHP 8.0 compatible

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=240');

$root = dirname(__DIR__, 3);

require_once $root . '/app/services/DataServices.php';

try {
    $services = new GuristasDataServices();
    $venal = $services->venal();

    $payload = $venal->map();

    $historyWarning = null;
    try {
        $history = $services->history();
        $history->record(
            $payload['data'],
            isset($payload['meta']['activity_updated_at'])
                ? (string) $payload['meta']['activity_updated_at']
                : null
        );
    } catch (Throwable $historyError) {
        // History is an enhancement. A storage-permission problem must not
        // take the live Venal map offline.
        $historyWarning = $historyError->getMessage();
    }

    $responseMeta = $payload['meta'];
    $responseMeta['activity_history_retention_hours'] = GuristasDataServices::HISTORY_RETENTION_HOURS;
    $responseMeta['activity_history_warning'] = $historyWarning;

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'service' => 'Guristas.net Venal intelligence',
        'generated_at' => gmdate('c'),
        'data' => $payload['data'],
        'meta' => $responseMeta,
        'sources' => $payload['sources'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'service' => 'Guristas.net Venal intelligence',
        'generated_at' => gmdate('c'),
        'error' => [
            'type' => get_class($e),
            'message' => $e->getMessage(),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
