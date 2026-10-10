<?php
declare(strict_types=1);
/** CLI-only safe export for rollback. Never overwrites old files. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 3 || !in_array($argv[1], ['pilot', 'stream'], true)) {
    fwrite(STDERR, "Usage: php scripts/export-persistent-stores.php pilot|stream ABSOLUTE-OUTPUT-DIRECTORY\n"); exit(2);
}
$root = dirname(__DIR__);
require_once $root . '/app/services/EveAuth.php';
$db = eve_db();
$target = $argv[2];
if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) throw new RuntimeException('Cannot create export directory.');
if (realpath($target) === realpath($root) || str_starts_with(realpath($target) . DIRECTORY_SEPARATOR, realpath($root . '/public') . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Do not export persistent or private data inside the public web directory.');
}
if ($argv[1] === 'pilot') {
    $rows = $db->query('SELECT environment, character_id, document FROM guristas_pilot_records')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (!in_array($r['environment'], ['eve_character_tokens', 'eve_character_tokens_local'], true)) throw new RuntimeException('Unknown record environment.');
        $folder = $target . '/' . $r['environment'];
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) throw new RuntimeException('Cannot create export folder.');
        $path = $folder . '/' . $r['character_id'] . '.json';
        if (file_exists($path)) throw new RuntimeException('Refusing to overwrite ' . $path);
        $json = json_encode(json_decode($r['document'], true, 64, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Export write failed.');
    }
} else {
    $rows = $db->query('SELECT filename, document FROM guristas_stream_documents')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (!in_array($r['filename'], ['messages.json', 'message-types.json', 'settings.json'], true)) throw new RuntimeException('Unexpected stream file.');
        $path = $target . '/' . $r['filename'];
        if (file_exists($path)) throw new RuntimeException('Refusing to overwrite ' . $path);
        $json = json_encode(json_decode($r['document'], true, 64, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== strlen($json) + strlen(PHP_EOL)) throw new RuntimeException('Export write failed.');
    }
}
echo count($rows) . " documents exported to " . $target . ". Existing files were not modified.\n";
