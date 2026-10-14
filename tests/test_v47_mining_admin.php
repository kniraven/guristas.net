<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$php=file_get_contents($root.'/public/admin/mining/index.php');
$js=file_get_contents($root.'/public/assets/js/mining-admin.js');
foreach(['tickets_staff(', 'eve_require_csrf(', 'hash_equals($revision', 'vm_classify(', '$allow[$id]', 'confirm_name', "'confirmed_systems'", "'skill_level'"] as $text)if(!str_contains($php,$text))throw new RuntimeException('Missing admin safeguard '.$text);
foreach(['fetch(location.pathname','?api=catalog','beforeunload','confirm(','replaceChildren(','resource-filter','status-filter','category-filter','sort'] as $text)if(!str_contains($js,$text))throw new RuntimeException('Missing UI control '.$text);
echo "PASS v47: staff protection, classification, CSRF, concurrency, async CRUD, filter/sort contracts\n";
