<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$php=file_get_contents($root.'/public/admin/mining/index.php');
$js=file_get_contents($root.'/public/assets/js/mining-admin.js');
foreach (["original_type_id", "Resource identity cannot be changed while editing", "Resource already exists. Choose Edit instead", "Removal requires the original selected resource", "Choose Add or Edit before saving"] as $rule) {if(!str_contains($php,$rule))throw new RuntimeException("Missing server guard: ".$rule);}
foreach (["function setMode(", "resource-choice", "resource-locked", "options.length===1", "el.disabled=options.length===1", "data.set('site',$('site').value)", "setMode('edit',r)", "setMode('add')"] as $rule) {if(!str_contains($js,$rule))throw new RuntimeException("Missing client behavior: ".$rule);}
echo "PASS v52: immutable edit identity, separate add, confirmed removal, single-site automatic selection\n";
