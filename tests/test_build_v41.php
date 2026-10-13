<?php
$src=file_get_contents(__DIR__.'/../app/services/BuildIntelligence.php');
$checks=[
 'Manufacturing action'=>'Manufacture ',
 'Mining action'=>'Mine ',
 'Price field'=>'buyer_unit_isk',
 'PI sale destination'=>'PRODUCE ON VENAL PLANET',
 'Conditional mining fallback'=>'MINE PYROXERES IN VENAL',
 'Mining value'=>'gross_isk_per_m3',
];
foreach($checks as $label=>$needle){
 if(strpos($src,$needle)===false)throw new RuntimeException($label.' missing');
 echo 'PASS v41 '.$label."\n";
}
if(strpos($src,"'Survey '")!==false)throw new RuntimeException('Survey title still present');
echo "PASS v41 BUILD source assertions\n";
