<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
$intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
$dir=sys_get_temp_dir().'/guri-v26-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
$dirProperty=new ReflectionProperty(GuristasMarketIntelligence::class,'dir');
$dirProperty->setAccessible(true); // Required by local PHP 8.0 reflection behavior.
$dirProperty->setValue($intel,$dir);
try{
  $ref=['types'=>['34'=>['name'=>'Tritanium']],'blueprints'=>['17931'=>['product'=>['typeID'=>17930,'quantity'=>1],'materials'=>[['typeID'=>34,'quantity'=>9]]]]];
  $sde=json_decode(file_get_contents(dirname(__DIR__).'/app/data/industry-reference.json'),true);
  foreach([22,1223,28701,28696,16268,16267,16269] as $id)$ref['types'][(string)$id]=$sde['types'][(string)$id];
  $r=(new GuristasBuildIntelligence($intel,$ref))->collect();
  [$m,$ore,$pi]=$r['orders'];
  foreach([$m,$ore,$pi] as $item){if(empty($item['why_selected'])||empty($item['steps']))throw new RuntimeException('Missing rationale or action steps');}
  if(!str_contains($ore['action'],'ARKONOR')||!str_contains($ore['reason'],'market'))throw new RuntimeException('Mining fallback lacks resource and selling check');
  if(!str_contains($pi['action'],'P3 FACTORY')||!str_contains(implode(' ',$pi['steps']),'Advanced Industry Facility'))throw new RuntimeException('PI instructions not suitable for beginners');
  if(!empty($pi['requires_skills']))throw new RuntimeException('PI factory incorrectly requires Remote Sensing');
  echo "PASS BUILD v26: evidence rationale, specific mining destination check, beginner PI steps, skill assumptions\n";
}finally {foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
