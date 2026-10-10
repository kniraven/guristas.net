<?php
declare(strict_types=1);
/** CLI-only, additive migration; refuses conflicting preexisting rows. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/app/services/EveAuth.php';
$db = eve_db();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function migrate_document(PDO $db, string $table, array $key, string $json): void {
    if ($table === 'guristas_pilot_records') {
        $select = $db->prepare('SELECT document FROM guristas_pilot_records WHERE environment=? AND character_id=?');
        $insert = $db->prepare('INSERT INTO guristas_pilot_records (environment,character_id,document) VALUES (?,?,?)');
        $args = [$key['environment'], $key['character_id']];
    } else {
        $select = $db->prepare('SELECT document FROM guristas_stream_documents WHERE filename=?');
        $insert = $db->prepare('INSERT INTO guristas_stream_documents (filename,document) VALUES (?,?)');
        $args = [$key['filename']];
    }
    $select->execute($args);
    $existing = $select->fetchColumn();
    if ($existing !== false) {
        if (json_decode((string)$existing, true, 64, JSON_THROW_ON_ERROR) != json_decode($json, true, 64, JSON_THROW_ON_ERROR)) {
            throw new RuntimeException('Conflicting existing SQL document for ' . $table . ' ' . implode('/', $args) . '. No overwrite performed.');
        }
        echo "Already migrated: " . $table . ' ' . implode('/', $args) . PHP_EOL;
        return;
    }
    $insert->execute([...$args, $json]);
    $select->execute($args);
    if (json_decode((string)$select->fetchColumn(), true, 64, JSON_THROW_ON_ERROR) != json_decode($json, true, 64, JSON_THROW_ON_ERROR)) {
        throw new RuntimeException('Readback verification failed for ' . $table);
    }
    echo "Migrated: " . $table . ' ' . implode('/', $args) . PHP_EOL;
}

if ($argc !== 2 || !in_array($argv[1], ['pilot', 'stream'], true)) {
    fwrite(STDERR, "Usage: php scripts/migrate-persistent-stores.php pilot|stream\n"); exit(2);
}
$which = $argv[1];
if ($which === 'pilot') {
    foreach (['eve_character_tokens', 'eve_character_tokens_local'] as $environment) {
        $directory = $root . '/storage/pilot-record/' . $environment;
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (!preg_match('/^[1-9][0-9]*$/D', $name)) throw new RuntimeException('Unexpected pilot filename: ' . basename($path));
            $id = (int)$name;
            $json = file_get_contents($path);
            if ($json === false) throw new RuntimeException('Could not read ' . $path);
            $record = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($record) || ($record['version'] ?? null) !== 1 || !is_array($record['romance'] ?? null) || !is_array($record['combat'] ?? null)) {
                throw new RuntimeException('Unexpected pilot record shape: ' . basename($path));
            }
            $db->beginTransaction();
            try {
                migrate_document($db, 'guristas_pilot_records', ['environment' => $environment, 'character_id' => $id], $json);
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        }
    }
} else {
    $directory = $root . '/storage/stream';
    foreach (['message-types.json', 'messages.json', 'settings.json'] as $filename) {
        $path = $directory . '/' . $filename;
        if (!is_file($path)) throw new RuntimeException('Missing active stream data: ' . $path);
        $json = file_get_contents($path);
        if ($json === false) throw new RuntimeException('Unreadable stream data: ' . $path);
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('Bad stream document: ' . $filename);
        $db->beginTransaction();
        try {
            migrate_document($db, 'guristas_stream_documents', ['filename' => $filename], $json);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}
echo "Import and SQL readback complete. No JSON source files deleted.\n";
