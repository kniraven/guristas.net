<?php
declare(strict_types=1);

function eve_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('guristas_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function eve_config(): array
{
    static $config;
    if ($config === null) {
        $file = dirname(__DIR__, 2) . '/config/auth.local.php';
        if (!is_file($file)) throw new RuntimeException('Configure config/auth.local.php first.');
        $config = require $file;
        foreach (['client_id','redirect_uri','db_dsn','db_user','db_password'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '' || str_starts_with($config[$key], 'YOUR_')) {
                throw new RuntimeException('Missing auth configuration: ' . $key);
            }
        }
        $callback = parse_url($config['redirect_uri']);
        $local = is_array($callback) && ($callback['scheme'] ?? '') === 'http'
            && ($callback['host'] ?? '') === 'localhost';
        $secure = is_array($callback) && ($callback['scheme'] ?? '') === 'https';
        if ((!$local && !$secure) || ($callback['path'] ?? '') !== '/auth/callback.php'
            || isset($callback['user']) || isset($callback['pass']) || isset($callback['query']) || isset($callback['fragment'])) {
            throw new RuntimeException('Use an HTTPS callback, or an HTTP localhost callback for local development.');
        }
    }
    return $config;
}

function eve_db(): PDO
{
    static $db;
    if ($db === null) {
        $c = eve_config();
        $db = new PDO($c['db_dsn'], $c['db_user'], $c['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $db;
}

function eve_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function eve_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function eve_unb64url(string $s): string
{
    $v = base64_decode(strtr($s, '-_', '+/'), true);
    if ($v === false) throw new RuntimeException('Invalid token encoding.');
    return $v;
}

function eve_http_json(string $url, ?array $form = null): array
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!in_array($host, ['login.eveonline.com', 'esi.evetech.net'], true) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('Untrusted API endpoint.');
    }
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Could not open API request.');
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Guristas.net EVE SSO/1.0 (https://guristas.net/)',
    ];
    if ($form !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body) || $status < 200 || $status >= 300 || strlen($body) > 1000000) {
        throw new RuntimeException('EVE service request failed.');
    }
    $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($json)) throw new RuntimeException('Unexpected EVE response.');
    return $json;
}

function eve_metadata(): array
{
    $m = eve_http_json('https://login.eveonline.com/.well-known/oauth-authorization-server');
    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
        if (!isset($m[$key]) || parse_url($m[$key], PHP_URL_HOST) !== 'login.eveonline.com' || parse_url($m[$key], PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Invalid EVE SSO metadata.');
        }
    }
    return $m;
}

function eve_der_len(int $n): string
{
    if ($n < 128) return chr($n);
    $s = ltrim(pack('N', $n), "\x00");
    return chr(0x80 | strlen($s)) . $s;
}
function eve_der_int(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '') $bytes = "\x00";
    if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\x00" . $bytes;
    return "\x02" . eve_der_len(strlen($bytes)) . $bytes;
}
function eve_rsa_pem(string $n, string $e): string
{
    $rsa = eve_der_int(eve_unb64url($n)) . eve_der_int(eve_unb64url($e));
    $rsa = "\x30" . eve_der_len(strlen($rsa)) . $rsa;
    $algorithm = hex2bin('300d06092a864886f70d0101010500');
    $bit = "\x03" . eve_der_len(strlen($rsa) + 1) . "\x00" . $rsa;
    $spki = "\x30" . eve_der_len(strlen($algorithm . $bit)) . $algorithm . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function eve_verify_token(string $token, array $metadata, string $clientId): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) throw new RuntimeException('Invalid access token.');
    $header = json_decode(eve_unb64url($parts[0]), true, 16, JSON_THROW_ON_ERROR);
    $claims = json_decode(eve_unb64url($parts[1]), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null)) {
        throw new RuntimeException('Invalid token header.');
    }
    $jwks = eve_http_json($metadata['jwks_uri']);
    $key = null;
    foreach ($jwks['keys'] ?? [] as $candidate) {
        if (($candidate['kid'] ?? null) === $header['kid'] && ($candidate['kty'] ?? null) === 'RSA' && ($candidate['alg'] ?? 'RS256') === 'RS256') {
            $key = $candidate; break;
        }
    }
    if (!$key || !is_string($key['n'] ?? null) || !is_string($key['e'] ?? null)) throw new RuntimeException('Unknown EVE signing key.');
    if (openssl_verify($parts[0] . '.' . $parts[1], eve_unb64url($parts[2]), eve_rsa_pem($key['n'], $key['e']), OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('Invalid EVE token signature.');
    }
    $aud = $claims['aud'] ?? [];
    if (is_string($aud)) $aud = [$aud];
    if (!is_array($aud) || !in_array($clientId, $aud, true) || !in_array('EVE Online', $aud, true)
        || !in_array($claims['iss'] ?? null, ['https://login.eveonline.com/', 'https://login.eveonline.com', 'login.eveonline.com'], true)
        || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
        || (isset($claims['nbf']) && $claims['nbf'] > time() + 30)
        || !preg_match('/^CHARACTER:EVE:([1-9][0-9]*)$/D', (string)($claims['sub'] ?? ''), $match)
        || !is_string($claims['name'] ?? null)) {
        throw new RuntimeException('EVE token claims did not validate.');
    }
    $claims['character_id'] = (int)$match[1];
    return $claims;
}

function eve_csrf(): string
{
    eve_session();
    return $_SESSION['eve_csrf'] ??= bin2hex(random_bytes(32));
}
function eve_require_csrf(?string $token): void
{
    if (!is_string($token) || !hash_equals(eve_csrf(), $token)) {
        http_response_code(403); exit('Invalid request token.');
    }
}
function eve_current_user(): ?array
{
    eve_session();
    $id = $_SESSION['eve_character_id'] ?? null;
    if (!is_int($id) || $id < 1) return null;
    $query = eve_db()->prepare('SELECT c.*, s.preferred_theme, s.favorite_ship_id, s.ship_layout_json FROM eve_characters c JOIN eve_character_settings s USING(character_id) WHERE c.character_id = ?');
    $query->execute([$id]);
    return $query->fetch() ?: null;
}
function eve_require_user(): array
{
    $user = eve_current_user();
    if (!$user) { header('Location: /'); exit; }
    return $user;
}
function eve_public_character(int $id): array
{
    $profile = eve_http_json('https://esi.evetech.net/latest/characters/' . $id . '/?datasource=tranquility');
    $corporationId = (int)($profile['corporation_id'] ?? 0);
    $allianceId = (int)($profile['alliance_id'] ?? 0);
    $corporationName = null; $allianceName = null;
    if ($corporationId) {
        try { $corporationName = eve_http_json('https://esi.evetech.net/latest/corporations/' . $corporationId . '/')['name'] ?? null; } catch (Throwable) {}
    }
    if ($allianceId) {
        try { $allianceName = eve_http_json('https://esi.evetech.net/latest/alliances/' . $allianceId . '/')['name'] ?? null; } catch (Throwable) {}
    }
    $origin = [];
    foreach (['race' => 'races', 'bloodline' => 'bloodlines', 'ancestry' => 'ancestries'] as $field => $route) {
        $origin[$field . '_id'] = isset($profile[$field . '_id']) ? (int)$profile[$field . '_id'] : null;
        $origin[$field . '_name'] = null;
        if ($origin[$field . '_id']) {
            try {
                $entries = eve_http_json('https://esi.evetech.net/latest/universe/' . $route . '/?datasource=tranquility');
                foreach ($entries as $entry) {
                    if (is_array($entry) && (int)($entry[$field . '_id'] ?? 0) === $origin[$field . '_id']) {
                        $origin[$field . '_name'] = (string)($entry['name'] ?? '');
                        break;
                    }
                }
            } catch (Throwable) { /* Keep the ID when name lookup is unavailable. */ }
        }
    }
    return array_merge($origin, [
        'corporation_id' => $corporationId ?: null, 'corporation_name' => $corporationName,
        'alliance_id' => $allianceId ?: null, 'alliance_name' => $allianceName,
        'birthday' => isset($profile['birthday']) ? date('Y-m-d H:i:s', strtotime($profile['birthday'])) : null,
    ]);
}
function eve_store_character(int $id, string $name): void
{
    try { $p = eve_public_character($id); }
    catch (Throwable) { $p = null; }
    $db = eve_db();
    if ($p === null) {
        $stmt = $db->prepare('INSERT INTO eve_characters (character_id, character_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE character_name=VALUES(character_name), last_login_at=UTC_TIMESTAMP()');
        $stmt->execute([$id, $name]);
    } else {
        $stmt = $db->prepare('INSERT INTO eve_characters (character_id, character_name, race_id, race_name, bloodline_id, bloodline_name, ancestry_id, ancestry_name, corporation_id, corporation_name, alliance_id, alliance_name, birthday, profile_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE character_name=VALUES(character_name), last_login_at=UTC_TIMESTAMP(), race_id=VALUES(race_id), race_name=VALUES(race_name), bloodline_id=VALUES(bloodline_id), bloodline_name=VALUES(bloodline_name), ancestry_id=VALUES(ancestry_id), ancestry_name=VALUES(ancestry_name), corporation_id=VALUES(corporation_id), corporation_name=VALUES(corporation_name), alliance_id=VALUES(alliance_id), alliance_name=VALUES(alliance_name), birthday=VALUES(birthday), profile_updated_at=UTC_TIMESTAMP()');
        $stmt->execute([$id, $name, $p['race_id'], $p['race_name'], $p['bloodline_id'], $p['bloodline_name'], $p['ancestry_id'], $p['ancestry_name'], $p['corporation_id'], $p['corporation_name'], $p['alliance_id'], $p['alliance_name'], $p['birthday']]);
    }
    $stmt = $db->prepare('INSERT IGNORE INTO eve_character_settings (character_id) VALUES (?)');
    $stmt->execute([$id]);
}

// Keep the key outside public/ and deployment artifacts. A 32-byte random key is required.
function eve_token_key(): string
{
    $config = eve_config();
    $key = base64_decode((string)($config['token_encryption_key'] ?? ''), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('Configure a 32-byte token encryption key before requesting scopes.');
    }
    return $key;
}
function eve_encrypt_token(string $token): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($token, $nonce, eve_token_key()));
}
function eve_decrypt_token(string $sealed): string
{
    $raw = base64_decode($sealed, true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new RuntimeException('Invalid encrypted token.');
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $token = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, eve_token_key());
    if ($token === false) throw new RuntimeException('Cannot decrypt EVE token.');
    return $token;
}
function eve_requested_scopes(): array
{
    $scopes = require dirname(__DIR__, 2) . '/config/esi-scopes.php';
    if (!is_array($scopes) || !$scopes || count($scopes) !== count(array_unique($scopes))) {
        throw new RuntimeException('Invalid EVE scope list.');
    }
    foreach ($scopes as $scope) {
        if (!is_string($scope) || !preg_match('/^[a-z0-9_.:-]+$/D', $scope)) {
            throw new RuntimeException('Invalid EVE scope.');
        }
    }
    return $scopes;
}
function eve_store_tokens(int $id, array $response, array $claims): void
{
    $refresh = $response['refresh_token'] ?? null;
    $access = $response['access_token'] ?? null;
    $expires = $response['expires_in'] ?? null;
    if (!is_string($refresh) || $refresh === '' || !is_string($access) || $access === ''
        || !is_numeric($expires) || (int)$expires < 1 || (int)$expires > 86400) {
        throw new RuntimeException('SSO did not return renewable credentials.');
    }
    $granted = $claims['scp'] ?? [];
    if (is_string($granted)) $granted = preg_split('/\s+/', trim($granted));
    if (!is_array($granted)) throw new RuntimeException('Invalid granted scope list.');
    $granted = array_values(array_filter($granted, 'is_string'));
    $stmt = eve_db()->prepare('INSERT INTO eve_character_tokens (character_id, access_token_sealed, refresh_token_sealed, scopes_json, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE access_token_sealed=VALUES(access_token_sealed), refresh_token_sealed=VALUES(refresh_token_sealed), scopes_json=VALUES(scopes_json), expires_at=VALUES(expires_at)');
    $stmt->execute([$id, eve_encrypt_token($access), eve_encrypt_token($refresh), json_encode($granted, JSON_THROW_ON_ERROR), (int)$expires]);
}
function eve_access_token(int $id, string $scope): string
{
    $db = eve_db();
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM eve_character_tokens WHERE character_id = ? FOR UPDATE');
        $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) throw new RuntimeException('EVE authorization is missing. Sign in again.');
        $granted = json_decode($row['scopes_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($granted) || !in_array($scope, $granted, true)) throw new RuntimeException('Required EVE permission was not granted.');
        if (strtotime($row['expires_at'] . ' UTC') > time() + 90) {
            $token = eve_decrypt_token($row['access_token_sealed']);
            $db->commit();
            return $token;
        }
        $config = eve_config();
        $meta = eve_metadata();
        $response = eve_http_json($meta['token_endpoint'], [
            'grant_type' => 'refresh_token', 'refresh_token' => eve_decrypt_token($row['refresh_token_sealed']),
            'client_id' => $config['client_id'],
        ]);
        $token = (string)($response['access_token'] ?? '');
        $claims = eve_verify_token($token, $meta, $config['client_id']);
        if ($claims['character_id'] !== $id) throw new RuntimeException('EVE token changed character.');
        // Some refresh responses omit refresh_token; reuse the prior value only then.
        if (empty($response['refresh_token'])) $response['refresh_token'] = eve_decrypt_token($row['refresh_token_sealed']);
        eve_store_tokens($id, $response, $claims);
        $db->commit();
        return $token;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
