<?php
declare(strict_types=1);
require_once __DIR__ . '/PilotDataService.php';

/** Export a complete skill catalog joined to the signed-in character's actual ESI skill data. */
function guristas_export_character_skills_csv(array $viewer): void
{
    try {
        $id = (int)($viewer['character_id'] ?? 0);
        if ($id < 1) throw new RuntimeException('No authenticated character.');
        $config = require dirname(__DIR__, 2) . '/config/esi.php';
        $cache = new GuristasEsiCache(dirname(__DIR__, 2) . '/storage/cache/pilot/' . eve_token_table());
        $client = new GuristasEsiClient($config, $cache);
        $grant = eve_authorized_esi_grant($id, eve_feature_scopes('skills')[0]);
        $response = $client->getCharacterJson($id, 'skills', $grant['token'], $grant['identity']);
        if (!empty($response['meta']['stale'])) throw new RuntimeException('Character skill data is stale.');
        $raw = $response['data']['skills'] ?? null;
        if (!is_array($raw)) throw new RuntimeException('Invalid character skills.');
        $trained = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_int($entry['skill_id'] ?? null)
                || !is_int($entry['skillpoints_in_skill'] ?? null)
                || !is_int($entry['trained_skill_level'] ?? null)
                || !is_int($entry['active_skill_level'] ?? null)) {
                throw new RuntimeException('Invalid character skill entry.');
            }
            $skillId = $entry['skill_id'];
            if ($skillId < 1 || isset($trained[$skillId])) throw new RuntimeException('Duplicate character skill.');
            $trained[$skillId] = $entry;
        }
        $catalog = guristas_complete_skill_catalog($client, $config);
        // Keep trained entries even if ESI's public catalog lags behind a newly released skill.
        foreach ($trained as $skillId => $_) {
            if (!isset($catalog[$skillId])) $catalog[$skillId] = ['name' => 'Skill ' . $skillId, 'group' => 'Unknown'];
        }
        uasort($catalog, static function (array $a, array $b): int {
            return strcasecmp($a['group'] . '/' . $a['name'], $b['group'] . '/' . $b['name']);
        });
        $fp = fopen('php://temp/maxmemory:2097152', 'w+');
        if (!$fp) throw new RuntimeException('CSV stream unavailable.');
        fputcsv($fp, ['skill_id', 'skill_group', 'skill_name', 'status', 'trained_level', 'active_level', 'skill_points']);
        foreach ($catalog as $skillId => $details) {
            $skill = $trained[$skillId] ?? null;
            fputcsv($fp, [
                $skillId,
                guristas_csv_safe($details['group']),
                guristas_csv_safe($details['name']),
                $skill === null ? 'Not injected' : 'Injected',
                $skill['trained_skill_level'] ?? 0,
                $skill['active_skill_level'] ?? 0,
                $skill['skillpoints_in_skill'] ?? 0,
            ]);
        }
        rewind($fp);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)($viewer['character_name'] ?? 'character'));
        $filename = trim($name, '-') . '-skills-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        fpassthru($fp);
        fclose($fp);
    } catch (Throwable $error) {
        // Never expose tokens, ESI responses, server paths, or exception details.
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo 'Skill export is temporarily unavailable. Please try again later.';
    }
}

function guristas_csv_safe(string $value): string
{
    return preg_match('/^[\\s]*[=+@\\-\\t\\r]/u', $value) ? "'" . $value : $value;
}

/** Enumerate the EVE skill category (16), its groups and all skill type IDs. */
function guristas_complete_skill_catalog(GuristasEsiClient $client, array $config): array
{
    $root = dirname(__DIR__, 2);
    $file = $root . '/storage/cache/derived/character-skills-catalog.json';
    if (is_file($file) && filemtime($file) >= time() - 7 * 86400) {
        $saved = json_decode((string)file_get_contents($file), true);
        if (is_array($saved) && count($saved) > 300) return $saved;
    }
    $category = $client->getJson('/universe/categories/16/', [], 86400);
    $groups = $category['data']['groups'] ?? null;
    if (!is_array($groups) || count($groups) < 10) throw new RuntimeException('Missing EVE skill groups.');
    $requests = [];
    foreach ($groups as $groupId) {
        if (!is_int($groupId) || $groupId < 1) throw new RuntimeException('Invalid EVE skill group.');
        $requests[] = ['key' => (string)$groupId, 'path' => '/universe/groups/' . $groupId . '/', 'fallback_ttl_seconds' => 86400];
    }
    $results = $client->getJsonBatch($requests, 12);
    $catalog = [];
    foreach ($groups as $groupId) {
        $group = $results[(string)$groupId]['data'] ?? null;
        if (!is_array($group) || !is_array($group['types'] ?? null) || !is_string($group['name'] ?? null)) {
            throw new RuntimeException('Incomplete EVE skill group.');
        }
        foreach ($group['types'] as $typeId) {
            if (!is_int($typeId) || $typeId < 1) throw new RuntimeException('Invalid skill type.');
            $catalog[$typeId] = ['name' => '', 'group' => $group['name']];
        }
    }
    if (count($catalog) < 300) throw new RuntimeException('Incomplete skill catalog.');
    $names = guristas_skill_names(array_keys($catalog), $config);
    foreach ($catalog as $id => &$entry) {
        $entry['name'] = $names[$id] ?? ('Skill ' . $id);
    }
    unset($entry);
    $folder = dirname($file);
    if (!is_dir($folder)) @mkdir($folder, 0770, true);
    if (is_dir($folder) && is_writable($folder)) {
        $tmp = tempnam($folder, 'skill-catalog-');
        if ($tmp !== false) {
            if (file_put_contents($tmp, json_encode($catalog, JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
                @rename($tmp, $file);
            } else @unlink($tmp);
        }
    }
    return $catalog;
}

/** Resolve type IDs in a single public ESI names request; no character token is transmitted. */
function guristas_skill_names(array $ids, array $config): array
{
    $curl = curl_init('https://esi.evetech.net/universe/names/?datasource=tranquility');
    if ($curl === false) throw new RuntimeException('Cannot initialize ESI.');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array_values($ids), JSON_THROW_ON_ERROR),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => (int)($config['connect_timeout_seconds'] ?? 5),
        CURLOPT_TIMEOUT => (int)($config['request_timeout_seconds'] ?? 15),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: ' . ($config['user_agent'] ?? 'Guristas.net')],
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($body) || strlen($body) > 1000000 || $status !== 200) throw new RuntimeException('Skill name lookup failed.');
    $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('Invalid skill names.');
    $names = [];
    foreach ($decoded as $record) {
        if (is_array($record) && ($record['category'] ?? '') === 'inventory_type'
            && is_int($record['id'] ?? null) && is_string($record['name'] ?? null)) {
            $names[$record['id']] = $record['name'];
        }
    }
    if (count($names) < 300) throw new RuntimeException('Incomplete skill names.');
    return $names;
}
