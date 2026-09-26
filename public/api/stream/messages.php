<?php

// Guristas.net unified stream message API - PHP 8.0 compatible

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__, 3);
require_once $root . '/app/services/StreamMessageService.php';

function stream_is_local_request(): bool
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($remote, ['127.0.0.1', '::1'], true);
}

function stream_admin_authorized(array $config): bool
{
    if (!empty($config['allow_local_without_key']) && stream_is_local_request()) {
        return true;
    }

    $expected = (string) ($config['admin_key'] ?? '');
    if ($expected === '') {
        return false;
    }

    $provided = (string) ($_SERVER['HTTP_X_GURISTAS_STREAM_KEY'] ?? '');
    return $provided !== '' && hash_equals($expected, $provided);
}

function stream_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

try {
    $config = require $root . '/config/stream.php';
    $service = new GuristasStreamMessageService($root . '/storage/stream');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $adminRequested = isset($_GET['admin']) && (string) $_GET['admin'] === '1';
        if ($adminRequested && !stream_admin_authorized($config)) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'error' => 'Admin authorization required.',
                'admin_key_configured' => ((string) ($config['admin_key'] ?? '')) !== '',
                'local_without_key_allowed' => !empty($config['allow_local_without_key']),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $bundle = $adminRequested ? $service->adminBundle() : $service->publicBundle();
        echo json_encode([
            'ok' => true,
            'admin' => $adminRequested,
            'data' => $bundle,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    if (!stream_admin_authorized($config)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Admin authorization required.']);
        exit;
    }

    $body = stream_json_body();
    $action = (string) ($body['action'] ?? '');
    $result = null;

    switch ($action) {
        case 'save_type':
            $result = $service->saveType((array) ($body['type'] ?? []));
            break;
        case 'delete_type':
            $result = $service->deleteType((string) ($body['id'] ?? ''));
            break;
        case 'save_message':
            $result = $service->saveMessage((array) ($body['message'] ?? []));
            break;
        case 'delete_message':
            $result = $service->deleteMessage((string) ($body['id'] ?? ''));
            break;
        case 'save_settings':
            $result = $service->saveSettings((array) ($body['settings'] ?? []));
            break;
        default:
            throw new InvalidArgumentException('Unknown stream message action.');
    }

    echo json_encode([
        'ok' => true,
        'action' => $action,
        'result' => $result,
        'data' => $service->adminBundle(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'type' => get_class($e),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
