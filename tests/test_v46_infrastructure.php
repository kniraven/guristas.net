<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$bin=file_get_contents($root.'/bin/guristas-intelligence.php');
foreach(["'collect-region'",'collectRegion((int)($argv[2]??0))'] as $needle) {
 if(!str_contains($bin,$needle))throw new RuntimeException('Missing collection-only command: '.$needle);
}
$build=file_get_contents($root.'/app/services/BuildIntelligence.php');
$market=file_get_contents($root.'/app/services/MarketIntelligence.php');
foreach([$build,$market] as $src)if(!str_contains($src,'storage/venal-mining-resources.json'))throw new RuntimeException('Runtime editor catalog not connected');
$admin=file_get_contents($root.'/public/admin/mining/index.php');
foreach(['tickets_staff','eve_require_csrf','GuristasBuildIntelligence::venalMiningCatalog','rename($temporary,$live)'] as $needle)if(!str_contains($admin,$needle))throw new RuntimeException('Admin editor guard missing '.$needle);
echo "PASS v46: separate regional collection and staff catalog protections\n";
