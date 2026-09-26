<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/services/EveAuth.php';
eve_session();
try {
    $config = eve_config();
    $metadata = eve_metadata();
    eve_token_key(); // Fail before consent if persistent storage is not configured.
    $scopes = eve_requested_scopes();
    $state = eve_b64url(random_bytes(32));
    $verifier = eve_b64url(random_bytes(32));
    $_SESSION['eve_oauth'] = ['state' => $state, 'verifier' => $verifier, 'issued' => time()];
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
