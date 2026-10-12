<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/MarketIntelligence.php';
$dir=sys_get_temp_dir().'/guri-v27-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);
try {
    $intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(GuristasMarketIntelligence::class,'dir'))->setValue($intel,$dir);
    foreach ([10000015,10001000] as $rid) {
        file_put_contents($dir.'/region-'.$rid.'.json',json_encode(['complete'=>true,'orders'=>[]],JSON_THROW_ON_ERROR));
    }
    file_put_contents($dir.'/lp-offers.json',json_encode(['offers'=>[['type_id'=>17931,'required_items'=>[]]]],JSON_THROW_ON_ERROR));
    $method=(new ReflectionClass($intel))->getMethod('relevantTypes');
    $types=$method->invoke($intel);
    foreach ([1224,1228,16268,16269,2312,2319,2346] as $id) {
        if (!isset($types[$id]))throw new RuntimeException('Missing market benchmark type '.$id);
    }
    echo "PASS v27: external-hub ore, ice and PI benchmark quotes survive the collector filter without local bids\n";
} finally {foreach (glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
