<?php

// Guristas.net data foundation - PHP 8.0 compatible build 2

declare(strict_types=1);

final class GuristasEsiCache
{
    /** @var string */
    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = $directory;

        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 02750, true) && !is_dir($this->directory)) {
                throw new RuntimeException('Unable to create ESI cache directory: ' . $this->directory);
            }
        }
    }

    public function read(string $key): ?array
    {
        $path = $this->pathForKey($key);
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $entry = json_decode($json, true);
        return is_array($entry) ? $entry : null;
    }

    public function isFresh(array $entry, ?int $now = null): bool
    {
        if ($now === null) {
            $now = time();
        }

        return isset($entry['expires_at']) && (int) $entry['expires_at'] > $now;
    }

    public function isUsableStale(array $entry, int $staleIfErrorSeconds, ?int $now = null): bool
    {
        if ($now === null) {
            $now = time();
        }

        if (!isset($entry['expires_at'])) {
            return false;
        }

        return $now <= ((int) $entry['expires_at'] + max(0, $staleIfErrorSeconds));
    }

    public function write(string $key, array $entry): void
    {
        $path = $this->pathForKey($key);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $json = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );

        $previousMask = umask(0027);
        try {
            $written = file_put_contents($temp, $json, LOCK_EX);
        } finally {
            umask($previousMask);
        }

        if ($written === false) {
            @unlink($temp);
            throw new RuntimeException('Unable to write ESI cache file: ' . $temp);
        }

        if (PHP_OS_FAMILY !== 'Windows' && !chmod($temp, 0640)) {
            @unlink($temp);
            throw new RuntimeException('Unable to set ESI cache file permissions.');
        }

        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Unable to finalize ESI cache file: ' . $path);
        }
    }

    private function pathForKey(string $key): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
