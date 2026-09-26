<?php

// Guristas.net unified stream message service - PHP 8.0 compatible

declare(strict_types=1);

final class GuristasStreamMessageService
{
    /** @var string */
    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create stream storage directory.');
        }
    }

    public function publicBundle(): array
    {
        $types = array_values(array_filter($this->readArray('message-types.json'), static function (array $row): bool {
            return !empty($row['enabled']);
        }));
        $messages = array_values(array_filter($this->readArray('messages.json'), static function (array $row): bool {
            return !empty($row['enabled']);
        }));

        return [
            'types' => $types,
            'messages' => $messages,
            'settings' => $this->readObject('settings.json'),
        ];
    }

    public function adminBundle(): array
    {
        return [
            'types' => $this->readArray('message-types.json'),
            'messages' => $this->readArray('messages.json'),
            'settings' => $this->readObject('settings.json'),
        ];
    }

    public function saveType(array $input): array
    {
        $types = $this->readArray('message-types.json');
        $id = $this->sanitizeId((string) ($input['id'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Message type name is required.');
        }
        if ($id === '') {
            $id = $this->uniqueId($this->slugify($name), $types);
        }

        $type = [
            'id' => $id,
            'name' => $this->limit($name, 64),
            'accent' => $this->sanitizeColor($input['accent'] ?? '#ff6a00'),
            'secondary' => $this->sanitizeColor($input['secondary'] ?? '#ffffff'),
            'background' => $this->sanitizeColor($input['background'] ?? '#100804'),
            'border' => $this->sanitizeColor($input['border'] ?? '#ff8737'),
            'glow' => $this->sanitizeColor($input['glow'] ?? '#ff6a00'),
            'meta' => $this->sanitizeColor($input['meta'] ?? '#ffc599'),
            'default_badge' => $this->limit(trim((string) ($input['default_badge'] ?? 'NETWORK')), 40),
            'default_priority' => $this->sanitizePriority((string) ($input['default_priority'] ?? 'ambient')),
            'cooldown_seconds' => $this->clampInt($input['cooldown_seconds'] ?? 20, 0, 3600),
            'enabled' => $this->toBool($input['enabled'] ?? true),
        ];

        $found = false;
        foreach ($types as $index => $existing) {
            if ((string) ($existing['id'] ?? '') === $id) {
                $types[$index] = $type;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $types[] = $type;
        }
        $this->writeJson('message-types.json', array_values($types));
        return $type;
    }

    public function deleteType(string $id): array
    {
        $id = $this->sanitizeId($id);
        if ($id === '') {
            throw new InvalidArgumentException('Message type id is required.');
        }

        $types = $this->readArray('message-types.json');
        $types = array_values(array_filter($types, static function (array $row) use ($id): bool {
            return (string) ($row['id'] ?? '') !== $id;
        }));
        if ($types === []) {
            throw new RuntimeException('At least one message type must remain.');
        }

        $fallback = 'system';
        $hasSystem = false;
        foreach ($types as $type) {
            if ((string) ($type['id'] ?? '') === 'system') {
                $hasSystem = true;
                break;
            }
        }
        if (!$hasSystem) {
            $fallback = (string) ($types[0]['id'] ?? 'system');
        }

        $messages = $this->readArray('messages.json');
        $reassigned = 0;
        foreach ($messages as &$message) {
            if ((string) ($message['type'] ?? '') === $id) {
                $message['type'] = $fallback;
                $reassigned++;
            }
        }
        unset($message);

        $this->writeJson('message-types.json', $types);
        $this->writeJson('messages.json', $messages);
        return ['deleted' => $id, 'reassigned_messages' => $reassigned, 'fallback_type' => $fallback];
    }

    public function saveMessage(array $input): array
    {
        $messages = $this->readArray('messages.json');
        $id = $this->sanitizeId((string) ($input['id'] ?? ''));
        if ($id === '') {
            $id = $this->uniqueId('msg-' . gmdate('Ymd-His'), $messages);
        }

        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('Message title and body are required.');
        }

        $message = [
            'id' => $id,
            'type' => $this->sanitizeId((string) ($input['type'] ?? 'system')) ?: 'system',
            'title' => $this->limit($title, 80),
            'badge' => $this->limit(trim((string) ($input['badge'] ?? '')), 40),
            'body' => $this->limit($body, 360),
            'meta' => $this->limit(trim((string) ($input['meta'] ?? '')), 90),
            'priority' => $this->sanitizePriority((string) ($input['priority'] ?? 'ambient')),
            'weight' => $this->clampFloat($input['weight'] ?? 1, 0.05, 20),
            'side' => $this->sanitizeSide((string) ($input['side'] ?? 'either')),
            'enabled' => $this->toBool($input['enabled'] ?? true),
        ];

        $found = false;
        foreach ($messages as $index => $existing) {
            if ((string) ($existing['id'] ?? '') === $id) {
                $messages[$index] = $message;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $messages[] = $message;
        }
        $this->writeJson('messages.json', array_values($messages));
        return $message;
    }

    public function deleteMessage(string $id): array
    {
        $id = $this->sanitizeId($id);
        $messages = $this->readArray('messages.json');
        $before = count($messages);
        $messages = array_values(array_filter($messages, static function (array $row) use ($id): bool {
            return (string) ($row['id'] ?? '') !== $id;
        }));
        $this->writeJson('messages.json', $messages);
        return ['deleted' => $id, 'existed' => count($messages) < $before];
    }

    public function saveSettings(array $input): array
    {
        $current = $this->readObject('settings.json');
        $booleanKeys = ['notifications_enabled'];
        $integerRanges = [
            'ambient_interval_min_ms' => [2000, 300000],
            'ambient_interval_max_ms' => [2000, 300000],
            'visible_duration_min_ms' => [1500, 60000],
            'visible_duration_max_ms' => [1500, 60000],
            'enter_duration_min_ms' => [0, 5000],
            'enter_duration_max_ms' => [0, 5000],
            'exit_duration_min_ms' => [0, 5000],
            'exit_duration_max_ms' => [0, 5000],
            'event_min_gap_ms' => [500, 120000],
            'event_expiry_ms' => [5000, 900000],
            'max_active' => [1, 3],
            'recent_message_memory' => [0, 100],
            'intel_refresh_ms' => [60000, 1800000],
            'message_refresh_ms' => [10000, 600000],
            'render_fps' => [10, 60],
            'label_fps' => [4, 30],
            'venal_kill_event_minimum' => [1, 100],
            'kill_spike_delta' => [1, 100],
        ];
        $floatRanges = [
            'pixel_ratio_cap' => [0.5, 2.0],
            'war_change_percent_threshold' => [0.1, 50.0],
        ];

        foreach ($booleanKeys as $key) {
            if (array_key_exists($key, $input)) {
                $current[$key] = $this->toBool($input[$key]);
            }
        }
        foreach ($integerRanges as $key => $range) {
            if (array_key_exists($key, $input)) {
                $current[$key] = $this->clampInt($input[$key], $range[0], $range[1]);
            }
        }
        foreach ($floatRanges as $key => $range) {
            if (array_key_exists($key, $input)) {
                $current[$key] = $this->clampFloat($input[$key], $range[0], $range[1]);
            }
        }

        if (($current['ambient_interval_min_ms'] ?? 0) > ($current['ambient_interval_max_ms'] ?? 0)) {
            $current['ambient_interval_max_ms'] = $current['ambient_interval_min_ms'];
        }
        if (($current['visible_duration_min_ms'] ?? 0) > ($current['visible_duration_max_ms'] ?? 0)) {
            $current['visible_duration_max_ms'] = $current['visible_duration_min_ms'];
        }

        $this->writeJson('settings.json', $current);
        return $current;
    }

    private function readArray(string $file): array
    {
        $decoded = $this->readJson($file);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid stream JSON in ' . $file);
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    private function readObject(string $file): array
    {
        $decoded = $this->readJson($file);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid stream JSON in ' . $file);
        }
        return $decoded;
    }

    private function readJson(string $file)
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            throw new RuntimeException('Missing stream configuration file: ' . $file);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeJson(string $file, array $data): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $file;
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary stream configuration.');
        }
        if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) {
            @unlink($path);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to replace stream configuration file.');
        }
    }

    private function sanitizeColor($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!preg_match('/^#[0-9a-f]{6}$/', $value)) {
            throw new InvalidArgumentException('Colors must use six-digit hex format, for example #ff6a00.');
        }
        return $value;
    }

    private function sanitizePriority(string $value): string
    {
        return in_array($value, ['ambient', 'info', 'priority', 'critical'], true) ? $value : 'ambient';
    }

    private function sanitizeSide(string $value): string
    {
        return in_array($value, ['either', 'left', 'right'], true) ? $value : 'either';
    }

    private function sanitizeId(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
        return trim(substr($value, 0, 80), '-_');
    }

    private function slugify(string $value): string
    {
        $slug = $this->sanitizeId($value);
        return $slug !== '' ? $slug : 'type';
    }

    private function uniqueId(string $base, array $rows): string
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[(string) ($row['id'] ?? '')] = true;
        }
        $candidate = $base;
        $i = 2;
        while (isset($ids[$candidate])) {
            $candidate = $base . '-' . $i;
            $i++;
        }
        return $candidate;
    }

    private function limit(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }

    private function clampInt($value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function clampFloat($value, float $min, float $max): float
    {
        return max($min, min($max, (float) $value));
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'off', 'no'], true);
        }
        return (bool) $value;
    }
}
