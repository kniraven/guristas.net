<?php

// Guristas.net data foundation - PHP 8.0 compatible build 3

declare(strict_types=1);

final class GuristasEsiClient
{
    /** @var array */
    private $config;

    /** @var GuristasEsiCache */
    private $cache;

    private $transport;

    public function __construct(array $config, GuristasEsiCache $cache, ?callable $transport = null)
    {
        $this->config = $config;
        $this->cache = $cache;
        $this->transport = $transport;

        if ($transport === null && !extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL extension is required for ESI requests.');
        }
    }

    /**
     * @return array{data:mixed,meta:array<string,mixed>}
     */
    public function getJson(string $path, array $query = [], ?int $fallbackTtlSeconds = null): array
    {
        $prepared = $this->prepareRequest($path, $query, $fallbackTtlSeconds);

        if ($prepared['fresh_result'] !== null) {
            return $prepared['fresh_result'];
        }

        return $this->executeSinglePreparedRequest($prepared);
    }

    /** Private cache identity includes environment, character and grant fingerprint.
     * Call only after validating the signed-in character and required scope.
     */
    public function getCharacterJson(int $characterId, string $route, string $token, string $authorizationIdentity, int $page = 1): array
    {
        if ($characterId < 1 || !in_array($route, ['standings', 'fw/stats', 'skills', 'killmails/recent', 'location', 'ship', 'assets'], true)
            || $token === '' || preg_match('/[\r\n]/', $token) || $authorizationIdentity === '') {
            throw new InvalidArgumentException('Invalid private ESI request.');
        }
        if (($this->config['base_url'] ?? '') !== 'https://esi.evetech.net') {
            throw new RuntimeException('Private ESI requests require the trusted HTTPS origin.');
        }
        if ($page < 1 || $page > 10000 || (!in_array($route, ['killmails/recent', 'assets'], true) && $page !== 1)) throw new InvalidArgumentException('Invalid private page.');
        $path = '/characters/' . $characterId . '/' . $route;
        $identity = 'PRIVATE|' . $characterId . '|' . hash('sha256', $authorizationIdentity);
        $prepared = $this->prepareRequest($path, in_array($route, ['killmails/recent', 'assets'], true) ? ['page' => $page] : [], in_array($route, ['location', 'ship'], true) ? 5 : ($route === 'killmails/recent' ? 300 : 3600), $identity);
        $prepared['private'] = true;
        if ($prepared['fresh_result'] !== null) return $prepared['fresh_result'];
        $prepared['request_headers'][] = 'Authorization: Bearer ' . $token;
        return $this->executeSinglePreparedRequest($prepared);
    }

    /**
     * Fetch several independent public ESI routes with bounded concurrency.
     *
     * Each request accepts:
     * - key: optional stable result key
     * - path: required ESI path
     * - query: optional query parameters
     * - fallback_ttl_seconds: optional cache fallback TTL
     *
     * @return array<string|int,array{data:mixed,meta:array<string,mixed>}>
     */
    public function getJsonBatch(array $requests, ?int $concurrency = null): array
    {
        if ($requests === []) {
            return [];
        }

        if ($concurrency === null) {
            $concurrency = isset($this->config['batch_concurrency'])
                ? (int) $this->config['batch_concurrency']
                : 12;
        }

        $concurrency = max(1, min(24, $concurrency));
        $results = [];
        $pending = [];

        foreach ($requests as $index => $request) {
            if (!is_array($request) || empty($request['path'])) {
                throw new InvalidArgumentException('Every ESI batch request must contain a path.');
            }

            $key = array_key_exists('key', $request) ? $request['key'] : $index;
            $query = isset($request['query']) && is_array($request['query'])
                ? $request['query']
                : [];
            $fallbackTtlSeconds = array_key_exists('fallback_ttl_seconds', $request)
                ? (int) $request['fallback_ttl_seconds']
                : null;

            $prepared = $this->prepareRequest(
                (string) $request['path'],
                $query,
                $fallbackTtlSeconds
            );
            $prepared['result_key'] = $key;

            if ($prepared['fresh_result'] !== null) {
                $results[$key] = $prepared['fresh_result'];
            } else {
                $pending[] = $prepared;
            }
        }

        foreach (array_chunk($pending, $concurrency) as $chunk) {
            $chunkResults = $this->executePreparedChunk($chunk);
            foreach ($chunkResults as $key => $value) {
                $results[$key] = $value;
            }
        }

        // Rebuild result ordering to match the caller's request ordering.
        $ordered = [];
        foreach ($requests as $index => $request) {
            $key = array_key_exists('key', $request) ? $request['key'] : $index;
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    private function prepareRequest(string $path, array $query, ?int $fallbackTtlSeconds, string $identity = 'PUBLIC'): array
    {
        $url = $this->buildUrl($path, $query);
        $cacheKey = ($identity === 'PUBLIC' ? 'GET|' : $identity . '|GET|') . $url . '|compat=' . $this->config['compatibility_date'];
        $cached = $this->cache->read($cacheKey);
        $now = time();

        if ($fallbackTtlSeconds === null) {
            $fallbackTtlSeconds = (int) $this->config['default_cache_ttl_seconds'];
        }

        if ($identity !== 'PUBLIC' && $cached !== null && (int)($cached['retry_at'] ?? 0) > $now) {
            if ($cached['data'] !== null && $this->cache->isUsableStale($cached, (int)$this->config['stale_if_error_seconds'])) {
                return ['fresh_result' => $this->resultFromCache($cached, 'STALE', true, 'ESI is rate limited.')];
            }
            throw new RuntimeException('ESI is rate limited.', 429);
        }
        if ($cached !== null && $this->cache->isFresh($cached, $now)) {
            return [
                'path' => $path,
                'url' => $url,
                'cache_key' => $cacheKey,
                'cached' => $cached,
                'fallback_ttl_seconds' => $fallbackTtlSeconds,
                'request_headers' => [],
                'fresh_result' => $this->resultFromCache($cached, 'HIT'),
            ];
        }

        $requestHeaders = [
            'Accept: application/json',
            'X-Compatibility-Date: ' . $this->config['compatibility_date'],
            'User-Agent: ' . $this->config['user_agent'],
        ];

        if ($cached !== null) {
            if (!empty($cached['etag'])) {
                $requestHeaders[] = 'If-None-Match: ' . $cached['etag'];
            }
            if (!empty($cached['last_modified'])) {
                $requestHeaders[] = 'If-Modified-Since: ' . $cached['last_modified'];
            }
        }

        return [
            'path' => $path,
            'url' => $url,
            'cache_key' => $cacheKey,
            'cached' => $cached,
            'fallback_ttl_seconds' => $fallbackTtlSeconds,
            'request_headers' => $requestHeaders,
            'fresh_result' => null,
        ];
    }

    private function executeSinglePreparedRequest(array $prepared): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)($prepared['url'], $prepared['request_headers']);
            return $this->finalizeResponse($prepared, $response['body'], $response['error'] ?? '', (int)$response['status'], $response['headers'] ?? []);
        }
        $headers = [];
        $ch = curl_init($prepared['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) $this->config['connect_timeout_seconds'],
            CURLOPT_TIMEOUT => (int) $this->config['request_timeout_seconds'],
            CURLOPT_HTTPHEADER => $prepared['request_headers'],
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) {
                    return $length;
                }

                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
                return $length;
            },
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $this->finalizeResponse(
            $prepared,
            $body,
            $curlError,
            $status,
            $headers
        );
    }

    private function executePreparedChunk(array $chunk): array
    {
        if ($this->transport !== null) {
            $results = [];
            foreach ($chunk as $prepared) $results[$prepared['result_key']] = $this->executeSinglePreparedRequest($prepared);
            return $results;
        }
        $multi = curl_multi_init();
        $contexts = [];

        foreach ($chunk as $index => $prepared) {
            $contexts[$index] = [
                'prepared' => $prepared,
                'headers' => [],
                'handle' => null,
            ];

            $ch = curl_init($prepared['url']);
            $contexts[$index]['handle'] = $ch;

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => (int) $this->config['connect_timeout_seconds'],
                CURLOPT_TIMEOUT => (int) $this->config['request_timeout_seconds'],
                CURLOPT_HTTPHEADER => $prepared['request_headers'],
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$contexts, $index): int {
                    $length = strlen($line);
                    $line = trim($line);
                    if ($line === '' || strpos($line, ':') === false) {
                        return $length;
                    }

                    list($name, $value) = explode(':', $line, 2);
                    $contexts[$index]['headers'][strtolower(trim($name))] = trim($value);
                    return $length;
                },
            ]);

            curl_multi_add_handle($multi, $ch);
        }

        do {
            $status = curl_multi_exec($multi, $active);
            if ($status !== CURLM_OK) {
                break;
            }

            if ($active) {
                $selected = curl_multi_select($multi, 1.0);
                if ($selected === -1) {
                    usleep(10000);
                }
            }
        } while ($active);

        $results = [];

        try {
            foreach ($contexts as $context) {
                $ch = $context['handle'];
                $prepared = $context['prepared'];
                $body = curl_multi_getcontent($ch);
                $curlError = curl_error($ch);
                $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

                $result = $this->finalizeResponse(
                    $prepared,
                    $body,
                    $curlError,
                    $httpStatus,
                    $context['headers']
                );

                $results[$prepared['result_key']] = $result;
            }
        } finally {
            foreach ($contexts as $context) {
                if ($context['handle'] !== null) {
                    curl_multi_remove_handle($multi, $context['handle']);
                    curl_close($context['handle']);
                }
            }
            curl_multi_close($multi);
        }

        return $results;
    }

    private function finalizeResponse(
        array $prepared,
        $body,
        string $curlError,
        int $status,
        array $headers
    ): array {
        $cached = $prepared['cached'];
        $now = time();
        $fallbackTtlSeconds = (int) $prepared['fallback_ttl_seconds'];

        if ($body === false || $status === 0) {
            return $this->serveStaleOrThrow(
                $cached,
                'ESI network request failed for ' . $prepared['path'] . ': '
                    . ($curlError !== '' ? $curlError : 'unknown cURL error')
            );
        }

        if (!empty($prepared['private']) && in_array($status, [401, 403], true)) {
            $this->cache->forget($prepared['cache_key']);
            throw new RuntimeException('Private ESI authorization was rejected.', $status);
        }
        // Persist a brief retry window so repeated page/API requests do not
        // hammer ESI during throttling. Tokens and error bodies are never cached.
        if (!empty($prepared['private']) && in_array($status, [420, 429], true)) {
            $delay = max(1, min(3600, (int)($headers['retry-after'] ?? 60)));
            $backoff = $cached ?? ['data' => null, 'expires_at' => 0];
            $backoff['retry_at'] = $now + $delay;
            $this->cache->write($prepared['cache_key'], $backoff);
            if ($cached !== null && $this->cache->isUsableStale($cached, (int)$this->config['stale_if_error_seconds'])) {
                return $this->resultFromCache($cached, 'STALE', true, 'ESI is rate limited.');
            }
            throw new RuntimeException('ESI is rate limited.', $status);
        }
        if (!empty($prepared['private']) && $status >= 400 && $status < 500) {
            throw new RuntimeException('Private ESI data is unavailable.', $status);
        }
        if ($status === 304 && $cached !== null) {
            $cached['fetched_at'] = $now;
            $cached['expires_at'] = $this->calculateExpiry($headers, $now, $fallbackTtlSeconds);
            $cached['response_headers'] = $this->selectMetadataHeaders($headers);
            $cached['compatibility_date_matched'] = isset($headers['x-compatibility-date'])
                ? $headers['x-compatibility-date']
                : (isset($cached['compatibility_date_matched']) ? $cached['compatibility_date_matched'] : null);
            $this->cache->write($prepared['cache_key'], $cached);

            return $this->resultFromCache($cached, 'REVALIDATED');
        }

        if ($status < 200 || $status >= 300) {
            $retryAfter = isset($headers['retry-after']) ? (int) $headers['retry-after'] : null;
            $message = sprintf('ESI returned HTTP %d for %s', $status, $prepared['path']);
            if ($retryAfter !== null && $retryAfter > 0) {
                $message .= sprintf(' (retry after %d seconds)', $retryAfter);
            }

            return $this->serveStaleOrThrow($cached, $message, $status, $headers);
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->serveStaleOrThrow(
                $cached,
                'ESI returned invalid JSON for ' . $prepared['path'] . ': ' . $e->getMessage(),
                $status,
                $headers
            );
        }

        $entry = [
            'data' => $data,
            'url' => $prepared['url'],
            'status' => $status,
            'fetched_at' => $now,
            'expires_at' => $this->calculateExpiry($headers, $now, $fallbackTtlSeconds),
            'etag' => isset($headers['etag']) ? $headers['etag'] : null,
            'last_modified' => isset($headers['last-modified']) ? $headers['last-modified'] : null,
            'compatibility_date_requested' => $this->config['compatibility_date'],
            'compatibility_date_matched' => isset($headers['x-compatibility-date']) ? $headers['x-compatibility-date'] : null,
            'response_headers' => $this->selectMetadataHeaders($headers),
        ];

        $this->cache->write($prepared['cache_key'], $entry);

        return $this->resultFromCache($entry, 'MISS');
    }

    private function buildUrl(string $path, array $query): string
    {
        $path = '/' . ltrim($path, '/');
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }

        $query = ['datasource' => $this->config['datasource']] + $query;
        ksort($query);

        return rtrim($this->config['base_url'], '/')
            . $path
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function calculateExpiry(array $headers, int $now, int $fallbackTtlSeconds): int
    {
        if (!empty($headers['cache-control'])
            && preg_match('/(?:^|,)\s*max-age=(\d+)/i', $headers['cache-control'], $match)
        ) {
            return $now + max(1, (int) $match[1]);
        }

        if (!empty($headers['expires'])) {
            $expires = strtotime($headers['expires']);
            if ($expires !== false && $expires > $now) {
                return $expires;
            }
        }

        return $now + max(1, $fallbackTtlSeconds);
    }

    private function resultFromCache(
        array $entry,
        string $cacheState,
        bool $stale = false,
        ?string $warning = null
    ): array {
        return [
            'data' => isset($entry['data']) ? $entry['data'] : null,
            'meta' => [
                'pages' => max(1, (int)($entry['response_headers']['x-pages'] ?? 1)),
                'source' => 'CCP ESI',
                'request_url' => isset($entry['url']) ? $entry['url'] : null,
                'http_status' => isset($entry['status']) ? $entry['status'] : 200,
                'fetched_at' => $this->isoTime(isset($entry['fetched_at']) ? $entry['fetched_at'] : null),
                'expires_at' => $this->isoTime(isset($entry['expires_at']) ? $entry['expires_at'] : null),
                'last_modified' => isset($entry['last_modified']) ? $entry['last_modified'] : null,
                'compatibility_date_requested' => isset($entry['compatibility_date_requested'])
                    ? $entry['compatibility_date_requested']
                    : $this->config['compatibility_date'],
                'compatibility_date_matched' => isset($entry['compatibility_date_matched'])
                    ? $entry['compatibility_date_matched']
                    : null,
                'cache' => $cacheState,
                'stale' => $stale,
                'warning' => $warning,
                'rate_limit' => [
                    'group' => isset($entry['response_headers']['x-ratelimit-group'])
                        ? $entry['response_headers']['x-ratelimit-group']
                        : null,
                    'limit' => isset($entry['response_headers']['x-ratelimit-limit'])
                        ? $entry['response_headers']['x-ratelimit-limit']
                        : null,
                    'remaining' => isset($entry['response_headers']['x-ratelimit-remaining'])
                        ? $entry['response_headers']['x-ratelimit-remaining']
                        : null,
                    'used' => isset($entry['response_headers']['x-ratelimit-used'])
                        ? $entry['response_headers']['x-ratelimit-used']
                        : null,
                ],
            ],
        ];
    }

    private function serveStaleOrThrow(
        ?array $cached,
        string $message,
        ?int $status = null,
        array $headers = []
    ): array {
        if ($cached !== null && $this->cache->isUsableStale(
            $cached,
            (int) $this->config['stale_if_error_seconds']
        )) {
            if ($headers !== []) {
                $existingHeaders = isset($cached['response_headers']) && is_array($cached['response_headers'])
                    ? $cached['response_headers']
                    : [];
                $cached['response_headers'] = $this->selectMetadataHeaders($headers) + $existingHeaders;
            }

            return $this->resultFromCache($cached, 'STALE', true, $message);
        }

        throw new RuntimeException($message, $status !== null ? $status : 0);
    }

    private function selectMetadataHeaders(array $headers): array
    {
        $keep = [
            'x-ratelimit-group',
            'x-ratelimit-limit',
            'x-ratelimit-remaining',
            'x-ratelimit-used',
            'retry-after',
            'x-pages',
        ];

        return array_intersect_key($headers, array_flip($keep));
    }

    private function isoTime($timestamp): ?string
    {
        if (!is_numeric($timestamp)) {
            return null;
        }

        return gmdate('c', (int) $timestamp);
    }
}
