<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/services/EveAuth.php';
eve_session();
try {
    $config = eve_config();
    // A cancelled or incomplete new login must not retain an older login.
    $loginUser = eve_current_user();
    unset($_SESSION['eve_character_id']);
    $feature = $_POST['feature'] ?? null;
    $characterId = null;
    if ($feature !== null) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($feature)) throw new RuntimeException('Invalid feature request.');
        eve_require_csrf($_POST['csrf'] ?? null);
        // Recover the current account for feature reauthorization before clearing login.
        $user = $loginUser;
        if (!$user) throw new RuntimeException('Sign in before reconnecting account permissions.');
        $characterId = (int)$user['character_id'];
        $scopes = eve_requested_scopes($feature, eve_granted_scopes($characterId));
    } else {
        $scopes = eve_requested_scopes();
    }
    if ($scopes !== []) eve_token_key(); // Validate private storage before feature consent.
    $metadata = eve_metadata();
    $state = eve_b64url(random_bytes(32));
    $verifier = eve_b64url(random_bytes(32));
    $_SESSION['eve_oauth'] = ['state' => $state, 'verifier' => $verifier, 'issued' => time(), 'scopes' => $scopes, 'character_id' => $characterId];
    $query = http_build_query([
        'response_type' => 'code', 'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'], 'scope' => implode(' ', $scopes), 'state' => $state,
        'code_challenge' => eve_b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
    header('Cache-Control: no-store');
    header('Location: ' . $metadata['authorization_endpoint'] . '?' . $query, true, 302);
} catch (Throwable $error) {
    error_log('EVE SSO start: ' . $error->getMessage());
    http_response_code(503);
    echo 'EVE login is temporarily unavailable.';
}
