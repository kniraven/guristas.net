<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/app/services/EveAuth.php';
$user = eve_current_user();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!$user) { http_response_code(401); echo '{"error":"login required"}'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $layout = json_decode((string)($user['ship_layout_json'] ?? ''), true);
    echo json_encode(['layout' => is_array($layout) ? $layout : null]); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
eve_require_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
$raw = file_get_contents('php://input', false, null, 0, 4097);
if ($raw === false || strlen($raw) > 4096) { http_response_code(413); exit; }
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['order'], $body['visible'], $body['lockCount'])
    || !is_array($body['order']) || !is_array($body['visible'])
    || count($body['order']) > 60 || count($body['visible']) > 60
    || !is_int($body['lockCount']) || $body['lockCount'] < 0 || $body['lockCount'] > 3) {
    http_response_code(400); echo '{"error":"invalid layout"}'; exit;
}
foreach (array_merge($body['order'], $body['visible']) as $key) {
    if (!is_string($key) || !preg_match('/^[a-zA-Z]{1,40}$/D', $key)) {
        http_response_code(400); echo '{"error":"invalid column"}'; exit;
    }
}
$stmt = eve_db()->prepare('UPDATE eve_character_settings SET ship_layout_json = ? WHERE character_id = ?');
$stmt->execute([json_encode(['order'=>array_values($body['order']), 'visible'=>array_values($body['visible']), 'lockCount'=>$body['lockCount']]), $user['character_id']]);
echo '{"saved":true}';
