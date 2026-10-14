<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$php=file_get_contents($root.'/public/admin/mining/index.php');
$js=file_get_contents($root.'/public/assets/js/mining-admin.js');
$css=file_get_contents($root.'/public/assets/css/mining-admin.css');
$data=json_decode(file_get_contents($root.'/app/data/mining-resource-types.json'),true);
if(!is_array($data)||count($data['items']??[])<30)throw new RuntimeException('Resource picker missing coverage');
$ids=[];$categories=[];$last='';foreach($data['items'] as $r){
 if(!in_array($r['category'],['ore','ice','gas'],true)||isset($ids[$r['type_id']])||($last!==''&&strcasecmp($last,$r['name'])>0))throw new RuntimeException('Bad ordered allowlist');
 $ids[$r['type_id']]=true;$categories[$r['category']]=true;$last=$r['name'];
}
if(count($categories)!==3)throw new RuntimeException('Missing resource classes');
foreach(['tickets_staff(','eve_require_csrf(', 'hash_equals($revision', '$allow[$id]', 'confirmed_systems', '$venalSystems', 'skill_level', 'siteOptions', "'/app/views/partials/site-header.php'", 'mining-resource-types.json'] as $s){
 if(!str_contains($php,$s))throw new RuntimeException('Missing PHP contract '.$s);
}
foreach(['listItems();renderSystems();render();','fetch(location.pathname','beforeunload','confirm(', 'resource-filter','item-picker','system-filter','confirmed_systems','renderSystems','render()','markDirty'] as $s){if(!str_contains($js,$s))throw new RuntimeException('Missing UI contract '.$s);}
if(!str_contains($css,'@media'))throw new RuntimeException('No responsive CSS');
echo "PASS v48: curated resource selector, shared theme, Venal systems, skill/site dropdowns, async saves and protections\n";
