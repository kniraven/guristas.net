<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$js=file_get_contents($root.'/public/assets/js/mining-admin.js');
$css=file_get_contents($root.'/public/assets/css/mining-admin.css');
foreach (['function oreFamily(', 'function resourceGroup(', "document.createElement('optgroup')", 'opt.dataset.category=r.category', 'picker.append(group)', 'function setItem(', 'listItems();renderSystems();render();'] as $part) {
 if (!str_contains($js,$part)) throw new RuntimeException('Missing group-selection contract: '.$part);
}
foreach (['--mine-action:var(--accent', '.mining-admin a:not(.skip-link)', '.mining-admin button#save', '.mining-admin button#delete', 'select optgroup'] as $part) {
 if (!str_contains($css,$part)) throw new RuntimeException('Missing theme contract: '.$part);
}
$items=json_decode(file_get_contents($root.'/app/data/mining-resource-types.json'),true)['items']??[];
$family=[];
foreach($items as $r){if(($r['category']??'')!=='ore')continue;
 $name=preg_replace('/\s+(?:I|II|III|IV|V)-Grade$/i','',(string)$r['name']);
 $family[$name][]=$r['type_id'];
}
if(!isset($family['Arkonor'])||count($family['Arkonor'])<3||!isset($family['Bistot'])||count($family['Bistot'])<3) throw new RuntimeException('Ore variant groups not grounded in catalog');
echo "PASS v49: ore variants grouped, exact IDs preserved, themed links/actions\n";
