<?php

// Guristas.net compact Venal hourly activity history - PHP 8.0 compatible

declare(strict_types=1);

final class GuristasVenalActivityHistory
{
    private const ALLOWED_METRICS = [
        'ship_jumps',
        'ship_kills',
        'pod_kills',
        'npc_kills',
    ];

    /** @var string */
    private $directory;

    /** @var int */
    private $retentionHours;

    public function __construct(string $directory, int $retentionHours = 720)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->retentionHours = max(24, $retentionHours);

        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                throw new RuntimeException(
                    'Unable to create Venal history directory: ' . $this->directory
                );
            }
        }
    }

    /**
     * Store only the small per-system hourly counters already returned by CCP.
     * No characters, killmails, fittings, names, or player identities are kept.
     */
    public function record(array $mapData, ?string $updatedAt = null): array
    {
        $timestamp = $this->parseTimestamp($updatedAt);
        $bucket = (int) (floor($timestamp / 3600) * 3600);
        $path = $this->pathForBucket($bucket);

        $systems = [];
        foreach ((array) ($mapData['systems'] ?? []) as $system) {
            if (!isset($system['id'])) {
                continue;
            }

            $activity = (array) ($system['activity'] ?? []);
            $systems[(string) ((int) $system['id'])] = [
                'ship_jumps' => max(0, (int) ($activity['ship_jumps'] ?? 0)),
                'ship_kills' => max(0, (int) ($activity['ship_kills'] ?? 0)),
                'pod_kills' => max(0, (int) ($activity['pod_kills'] ?? 0)),
                'npc_kills' => max(0, (int) ($activity['npc_kills'] ?? 0)),
            ];
        }

        $entry = [
            'schema' => 1,
            'bucket' => gmdate('c', $bucket),
            'updated_at' => $updatedAt ?: gmdate('c', $timestamp),
            'recorded_at' => gmdate('c'),
            'systems' => $systems,
        ];

        $existing = $this->readFile($path);
        if ($existing !== null) {
            $existingTime = $this->parseTimestamp(
                isset($existing['updated_at']) ? (string) $existing['updated_at'] : null
            );

            if ($existingTime >= $timestamp) {
                $this->prune();
                return [
                    'status' => 'existing',
                    'bucket' => gmdate('c', $bucket),
                    'path' => $path,
                ];
            }
        }

        $this->writeAtomic($path, $entry);
        $this->prune();

        return [
            'status' => 'recorded',
            'bucket' => gmdate('c', $bucket),
            'path' => $path,
        ];
    }

    /**
     * Sum sampled CCP one-hour aggregates over a requested rolling window.
     * Coverage is always returned so partial local history is never presented
     * as a complete multi-hour result.
     */
    public function aggregate(string $metric, int $hours): array
    {
        if (!in_array($metric, self::ALLOWED_METRICS, true)) {
            throw new InvalidArgumentException('Unsupported Venal history metric.');
        }

        if ($hours < 1 || $hours > $this->retentionHours) {
            throw new InvalidArgumentException('Requested history window is outside retention.');
        }

        $currentBucket = (int) (floor(time() / 3600) * 3600);
        $earliestBucket = $currentBucket - (($hours - 1) * 3600);
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            $files = [];
        }

        $snapshots = [];
        foreach ($files as $path) {
            $bucket = $this->bucketFromPath($path);
            if ($bucket === null || $bucket < $earliestBucket || $bucket > $currentBucket) {
                continue;
            }

            $entry = $this->readFile($path);
            if ($entry === null) {
                continue;
            }

            $snapshots[$bucket] = $entry;
        }

        ksort($snapshots, SORT_NUMERIC);

        $totals = [];
        foreach ($snapshots as $entry) {
            foreach ((array) ($entry['systems'] ?? []) as $systemId => $activity) {
                $id = (int) $systemId;
                if (!isset($totals[$id])) {
                    $totals[$id] = 0;
                }

                $activityRow = (array) $activity;
                $totals[$id] += max(
                    0,
                    (int) ($activityRow[$metric] ?? 0)
                );
            }
        }

        ksort($totals, SORT_NUMERIC);
        $systems = [];
        foreach ($totals as $id => $value) {
            $systems[] = [
                'id' => (int) $id,
                'value' => (int) $value,
            ];
        }

        $available = count($snapshots);
        $buckets = array_keys($snapshots);

        return [
            'metric' => $metric,
            'hours' => $hours,
            'systems' => $systems,
            'coverage' => [
                'available_hours' => $available,
                'expected_hours' => $hours,
                'ratio' => $hours > 0 ? $available / $hours : 0,
                'complete' => $available >= $hours,
                'first_bucket' => $available > 0
                    ? gmdate('c', (int) reset($buckets))
                    : null,
                'last_bucket' => $available > 0
                    ? gmdate('c', (int) end($buckets))
                    : null,
            ],
            'retention_hours' => $this->retentionHours,
            'generated_at' => gmdate('c'),
        ];
    }

    public function retentionHours(): int
    {
        return $this->retentionHours;
    }

    private function parseTimestamp(?string $value): int
    {
        if ($value !== null && $value !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return time();
    }

    private function pathForBucket(int $bucket): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . gmdate('YmdH', $bucket)
            . '.json';
    }

    private function bucketFromPath(string $path): ?int
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        if (!preg_match('/^\d{10}$/', $name)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!YmdH',
            $name,
            new DateTimeZone('UTC')
        );

        return $date instanceof DateTimeImmutable
            ? $date->getTimestamp()
            : null;
    }

    private function readFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function writeAtomic(string $path, array $entry): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write Venal history snapshot.');
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to finalize Venal history snapshot.');
        }
    }

    private function prune(): void
    {
        $cutoff = time() - ($this->retentionHours * 3600);
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $path) {
            $bucket = $this->bucketFromPath($path);
            if ($bucket !== null && $bucket < $cutoff) {
                @unlink($path);
            }
        }
    }
}
