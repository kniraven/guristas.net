<?php

// Guristas.net data foundation - PHP 8.0 compatible build 2

declare(strict_types=1);

final class GuristasSourceService
{
    /**
     * Produce small provenance blocks that can later power a discreet source UI.
     */
    public function esi(string $endpoint, array $esiMeta): array
    {
        return [
            'name' => 'CCP ESI',
            'publisher' => 'CCP Games',
            'endpoint' => $endpoint,
            'updated_at' => isset($esiMeta['last_modified']) && $esiMeta['last_modified'] !== null
                ? $esiMeta['last_modified']
                : (isset($esiMeta['fetched_at']) ? $esiMeta['fetched_at'] : null),
            'retrieved_at' => isset($esiMeta['fetched_at']) ? $esiMeta['fetched_at'] : null,
            'expires_at' => isset($esiMeta['expires_at']) ? $esiMeta['expires_at'] : null,
            'cache' => isset($esiMeta['cache']) ? $esiMeta['cache'] : null,
            'stale' => !empty($esiMeta['stale']),
            'compatibility_date' => isset($esiMeta['compatibility_date_matched']) && $esiMeta['compatibility_date_matched'] !== null
                ? $esiMeta['compatibility_date_matched']
                : (isset($esiMeta['compatibility_date_requested']) ? $esiMeta['compatibility_date_requested'] : null),
        ];
    }
}
