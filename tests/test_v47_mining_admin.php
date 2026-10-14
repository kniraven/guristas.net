<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$php=file_get_contents($root.'/public/admin/mining/index.php');
$js=file_get_contents($root.'/public/assets/js/mining-admin.js');
$css=file_get_contents($root.'/public/assets/css/mining-admin.css');
foreach([
    'tickets_staff('=>'Staff-only endpoint',
    'eve_require_csrf('=>'CSRF validation',
    'hash_equals($revision'=>'Optimistic concurrency guard',
    'vm_classify('=>'Server-side mining classification',
    "'universe/types/'"=>'Official CCP ESI item lookup',
    "'universe/groups/'"=>'Official CCP ESI group lookup',
] as $needle=>$label){if($needle==='confirmed')continue;if(strpos($php,$needle)===false)throw new RuntimeException('Missing '.$label);}
foreach(['fetch(location.pathname','?api=search','?api=catalog','beforeunload','confirm(','replaceChildren(','resource-filter','status-filter','category-filter','sort'] as $needle){if(strpos($js,$needle)===false)throw new RuntimeException('Missing client behavior '.$needle);}
foreach(['structure.css','themes.css','site-usability.css','mining-admin.css'] as $needle){if(strpos($php,$needle)===false)throw new RuntimeException('Missing theme '.$needle);}
if(strpos($php,'Location: /admin/mining/?saved=1')!==false)throw new RuntimeException('Synchronous redirect remains');
if(strpos($php,'$claimed!==$category')===false)throw new RuntimeException('No category tamper protection');
if(strpos($php,'confirm_name')===false)throw new RuntimeException('Missing delete confirmation');
if(strpos($css,'@media')===false)throw new RuntimeException('Missing responsive design');
echo "PASS v47 staff guard, CCP classification, CSRF, concurrency, async CRUD, filters and theme\n";
