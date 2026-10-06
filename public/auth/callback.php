<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/services/EveAuth.php';
require_once dirname(__DIR__, 2) . '/app/services/SiteTheme.php';
eve_session();
try {
    $pending = $_SESSION['eve_oauth'] ?? null;
    unset($_SESSION['eve_oauth']); // Single use, including rejected attempts.
    if (!is_array($pending) || !is_string($pending['state'] ?? null) || !is_string($pending['verifier'] ?? null)
        || abs(time() - (int)($pending['issued'] ?? 0)) > 600
        || !is_string($_GET['state'] ?? null) || !hash_equals((string)$pending['state'], $_GET['state'])
        || !is_string($_GET['code'] ?? null) || strlen($_GET['code']) > 2048 || isset($_GET['error'])) {
        throw new RuntimeException('SSO state, code, or consent is invalid.');
    }
    $c = eve_config();
    $metadata = eve_metadata();
    $response = eve_http_json($metadata['token_endpoint'], [
        'grant_type' => 'authorization_code', 'code' => $_GET['code'],
        'client_id' => $c['client_id'], 'redirect_uri' => $c['redirect_uri'],
        'code_verifier' => $pending['verifier'],
    ]);
    $claims = eve_verify_token((string)($response['access_token'] ?? ''), $metadata, $c['client_id']);
    $storeFeatureTokens = eve_validate_feature_consent($pending, $claims);
    eve_store_character($claims['character_id'], $claims['name']);
    // Required permissions were validated before establishing the session.
    if ($storeFeatureTokens) eve_store_tokens($claims['character_id'], $response, $claims);
    session_regenerate_id(true);
    $_SESSION['eve_character_id'] = $claims['character_id'];
    unset($_SESSION['eve_csrf']);
    $user = eve_current_user();
    if ($user && SiteTheme::valid($user['preferred_theme'])) {
        setcookie('guristas_theme', $user['preferred_theme'], [
            'expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }
    header('Cache-Control: no-store');
    header('Location: /account/', true, 303);
} catch (Throwable $error) {
    unset($_SESSION['eve_character_id']);
    error_log('EVE SSO callback: ' . $error->getMessage());
    http_response_code(400);
    echo 'EVE login requires standings, faction warfare statistics and skills permission. Login was not completed. <a href="/">Return to the site</a> and try again.';
}
