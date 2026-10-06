<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/PilotEntityNames.php';
$directory = sys_get_temp_dir() . '/pilot-names-' . bin2hex(random_bytes(6));
$cache = new GuristasEsiCache($directory);
$calls = 0;
$request = static function ($ids) use (&$calls): array {
    $calls++;
    if ($ids !== [500005, 1000127, 3019356]) throw new RuntimeException('Wrong IDs.');
    return [['id' => 500005, 'name' => 'Jove Empire'], ['id' => 1000127, 'name' => 'Guristas'], ['id' => 3019356, 'name' => 'Agent name'], ['id' => 999, 'name' => 'Unrequested']];
};
try {
    $names = eve_pilot_entity_names([500005, 1000127, 3019356, 500005], $request, $cache);
    if (count($names) !== 3 || $names[500005] !== 'Jove Empire') throw new RuntimeException('Lookup failed.');
    if (eve_pilot_entity_names([500005, 1000127, 3019356], $request, $cache) !== $names || $calls !== 1) throw new RuntimeException('Cache failed.');
    $cache->write('entity:500005', ['name' => 'Jove Empire', 'expires_at' => 0]);
    $fallback = eve_pilot_entity_names([500005, 500006], static function () { throw new RuntimeException('Unavailable'); }, $cache);
    if ($fallback !== [500005 => 'Jove Empire']) throw new RuntimeException('Fallback failed.');
    echo "PASS: public names, deduplication, cache reuse, unrequested-ID rejection and outage fallback.\n";
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
