<?php
/** Public read-only Twitch livestream preflight. Credentials stay server-side. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=20');
header('X-Content-Type-Options: nosniff');

function answer(string $status, bool $verified = false): never {
    echo json_encode(['ok' => true, 'status' => $status, 'verified' => $verified], JSON_UNESCAPED_SLASHES);
    exit;
}
$allowed = ['kniraven', 'federationfrontlinereport', 'myriad_nova'];
$name = strtolower(trim((string) ($_GET['channel'] ?? '')));
if (!in_array($name, $allowed, true)) {
    http_response_code(400);
    answer('unknown');
}
// Optional. Set these in the PHP server environment, NOT in public/.
$id = getenv('TWITCH_CLIENT_ID') ?: '';
$secret = getenv('TWITCH_CLIENT_SECRET') ?: '';
if ($id === '' || $secret === '' || !function_exists('curl_init')) answer('unknown');

function request(string $url, array $headers = [], ?array $post = null): ?array {
    $handle = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($handle, $opts);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($status !== 200 || !is_string($response)) return null;
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

// Simple private file cache reduces API and token calls. Never stored below public/.
$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'guristas-twitch-status-' . substr(hash('sha256', __FILE__), 0, 12);
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $name . '.json';
$cached = @file_get_contents($cacheFile);
if (is_string($cached)) {
    $record = json_decode($cached, true);
    if (is_array($record) && ($record['expires'] ?? 0) > time() && in_array($record['status'] ?? '', ['online', 'offline'], true)) {
        answer($record['status'], true);
    }
}
$tokenFile = $cacheDir . DIRECTORY_SEPARATOR . 'token.json';
$tokenData = json_decode((string) @file_get_contents($tokenFile), true);
$token = is_array($tokenData) && ($tokenData['expires'] ?? 0) > time() ? ($tokenData['token'] ?? '') : '';
if (!is_string($token) || $token === '') {
    $auth = request('https://id.twitch.tv/oauth2/token', [], [
        'client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials'
    ]);
    if (!$auth || !isset($auth['access_token'])) answer('unknown');
    $token = (string) $auth['access_token'];
    @file_put_contents($tokenFile, json_encode([
        'token' => $token, 'expires' => time() + max(60, (int) ($auth['expires_in'] ?? 3600) - 120)
    ]), LOCK_EX);
    @chmod($tokenFile, 0600);
}
$result = request('https://api.twitch.tv/helix/streams?user_login=' . rawurlencode($name), [
    'Client-ID: ' . $id,
    'Authorization: Bearer ' . $token
]);
if (!$result || !isset($result['data']) || !is_array($result['data'])) answer('unknown');
$status = count($result['data']) ? 'online' : 'offline';
@file_put_contents($cacheFile, json_encode(['status' => $status, 'expires' => time() + 30]), LOCK_EX);
answer($status, true);
