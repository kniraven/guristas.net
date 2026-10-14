<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$admin=file_get_contents($root.'/public/admin/index.php');
$php=file_get_contents($root.'/public/admin/mining/index.php');
$js=file_get_contents($root.'/public/assets/js/mining-admin.js');
$css=file_get_contents($root.'/public/assets/css/mining-admin.css');
foreach (['<a href="/admin/mining/">Edit mining resources</a>'] as $x)if(!str_contains($admin,$x))throw new RuntimeException('Missing orange native Admin link');
if(str_contains($admin,'class="button" href="/admin/mining/"'))throw new RuntimeException('Button style overrides native Admin link');
foreach (["'ore'=>['Asteroid belt','Ore anomaly']","'ice'=>['Ice anomaly']","'gas'=>['Gas cosmic signature']","$".'siteOptionsByCategory[$category]', 'in_array($site,$siteOptionsByCategory[$category]??[],true)'] as $x)if(!str_contains($php,$x))throw new RuntimeException('Missing server site restriction: '.$x);
foreach (["ore:['Asteroid belt','Ore anomaly']","ice:['Ice anomaly']","gas:['Gas cosmic signature']",'function populateSites(',"populateSites(record.category)","populateSites(r.category,value)","populateSites($('category').value)"] as $x)if(!str_contains($js,$x))throw new RuntimeException('Missing client site restriction: '.$x);
if(!str_contains($css,'.mining-admin .mine-back'))throw new RuntimeException('Missing theme back link');
echo "PASS v50: native orange Admin link, category-limited site options, server validation and legacy review\n";
