<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/services/PublicIndustry.php';
function industry_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$bp = ['materials'=>[['typeID'=>1,'quantity'=>1],['typeID'=>2,'quantity'=>11]]];
industry_check(guri_materials($bp,10,10,0) === [1=>10,2=>99], 'Single units and job-level material rounding');
industry_check(guri_materials($bp,1,6,6)[2] === 10, 'Material reductions multiply');
try { guri_materials($bp,0,0,0); throw new RuntimeException('Invalid runs accepted'); } catch (InvalidArgumentException $error) {}
$orders=[['type_id'=>17930,'location_id'=>60003760,'is_buy_order'=>false,'price'=>10,'volume_remain'=>2],['type_id'=>17930,'location_id'=>60003760,'is_buy_order'=>false,'price'=>20,'volume_remain'=>4],['type_id'=>17930,'location_id'=>60003761,'is_buy_order'=>false,'price'=>1,'volume_remain'=>100],['type_id'=>17930,'location_id'=>60003760,'is_buy_order'=>true,'price'=>30,'volume_remain'=>3,'min_volume'=>2]];
industry_check(guri_order_fill($orders,60003760,4,true)['value'] === 60.0, 'Whole-batch weighted price and station filtering');
industry_check(guri_order_fill($orders,60003760,8,true)['value'] === null, 'Thin depth has no complete batch value');
industry_check(guri_order_fill($orders,60003760,1,false)['complete'] === false, 'Buy order minimum volume respected');
industry_check(guri_order_fill($orders,60003760,2,false)['value'] === 60.0, 'Eligible buy fill');
$tmp = sys_get_temp_dir() . '/guri-industry-' . bin2hex(random_bytes(6));
$config = require dirname(__DIR__) . '/config/esi.php';
$calls=[];
$transport=static function(string $url, array $headers) use (&$calls,$orders): array {
    $calls[]=$url; parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    $page=(int)($query['page']??1);
    return ['status'=>200,'headers'=>['cache-control'=>'max-age=300','x-pages'=>'2'],'body'=>json_encode($page===1?[$orders[0]]:[$orders[1],$orders[2]])];
};
try {
    $service = new GuristasPublicIndustry(new GuristasEsiClient($config,new GuristasEsiCache($tmp),$transport));
    $quote=$service->quote('jita',17930,4);
    industry_check($quote['buy']['value']===60.0 && count($calls)===2 && $quote['meta']['stale']===false,'All pages consumed with freshness');
    $service->quote('jita',17930,4);
    industry_check(count($calls)===2,'Repeated quotes use the public cache');
    try {$service->quote('unknown',17930,1);throw new RuntimeException('Unknown hub accepted');}catch(InvalidArgumentException $error){}
    $ref=guri_industry_reference();
    industry_check($ref['hubs']['fulcrum']['station_id']===60015187 && count($ref['offers_snapshot'])===374,'Official public reference');
} finally { foreach (glob($tmp.'/*') ?: [] as $file) unlink($file);if(is_dir($tmp))rmdir($tmp); }
echo "Public material calculations, order depth, minimum volumes, pagination and cache passed.\n";
