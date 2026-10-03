<?php

// Run hourly from CLI/cron/Task Scheduler to build complete rolling windows.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

require_once $root . '/app/services/DataServices.php';

try {
    $services = new GuristasDataServices();
    $venal = $services->venal();
    $history = $services->history();

    $payload = $venal->map();
    $result = $history->record(
        $payload['data'],
        isset($payload['meta']['activity_updated_at'])
            ? (string) $payload['meta']['activity_updated_at']
            : null
    );

    echo json_encode([
        'ok' => true,
        'collector' => 'Venal hourly activity',
        'result' => $result,
        'retention_hours' => $history->retentionHours(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
