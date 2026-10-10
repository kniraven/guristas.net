<?php
declare(strict_types=1);
/** CLI-only: initialize missing SQL stream documents from versioned defaults. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/app/services/EveAuth.php';
$db = eve_db();
$defaults = [];
foreach (['message-types.json', 'messages.json', 'settings.json'] as $name) {
    $raw = file_get_contents($root . '/config/stream-defaults/' . $name);
    if ($raw === false || !is_array(json_decode($raw, true, 512, JSON_THROW_ON_ERROR))) {
        throw new RuntimeException('Invalid stream defaults: ' . $name);
    }
    $defaults[$name] = $raw;
}
$db->beginTransaction();
try {
    $query = $db->prepare('INSERT IGNORE INTO guristas_stream_documents (filename, document) VALUES (?, ?)');
    foreach ($defaults as $name => $raw) {
        $query->execute([$name, $raw]);
        echo ($query->rowCount() ? 'Initialized: ' : 'Preserved: ') . $name . PHP_EOL;
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
