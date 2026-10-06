<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/app/services/PilotDataService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); header('Allow: GET'); echo json_encode(['ok' => false, 'error' => 'GET required.']); exit;
}
try {
    $user = eve_current_user();
    if (!$user) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Sign in to view your pilot data.']); exit; }
    // No character ID from the URL is accepted: this endpoint is self-only.
    $data = eve_pilot_data((int)$user['character_id']);
    echo json_encode(['ok' => true, 'pilot' => $data], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Pilot data is temporarily unavailable.']);
}
