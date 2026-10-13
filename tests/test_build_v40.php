<?php
declare(strict_types=1);
$path=dirname(__DIR__).'/app/services/BuildIntelligence.php';
$text=file_get_contents($path);
$checks=[
 'manufacture instruction'=>"'Manufacture '",
 'mining qualification'=>"'Survey '",
 'PI instruction'=>"'Produce '",
 'Fulcrum location'=>"'manufacturing_location'=>'The Fulcrum (planned; job quote unverified)'",
 'Fulcrum 6% ME scenario'=>"'material_reduction_pct'=>".'$eligible?6:0',
 'Fulcrum 70% TE scenario'=>"'time_reduction_pct'=>".'$eligible?70:0',
 'economic uncertainty'=>"'job_cost_verified'=>false",
 'exactly three activity slots'=>'$cards=[$manufacture',
 'at most one Jita instruction metric'=>"'jita_sale_cards'",
 'planet feasibility disclosure'=>"planet-type inventory",
];
foreach($checks as $name=>$needle){if(strpos($text,$needle)===false){fwrite(STDERR,"FAIL v40 $name\n");exit(1);}echo "PASS v40 $name\n";}
echo "PASS v40 source contracts (not a simulation of production market ranking)\n";
require_once $path;
$intel=(new ReflectionClass(GuristasMarketIntelligence::class))->newInstanceWithoutConstructor();
$reference=['types'=>['17930'=>['name'=>'Worm'],'34'=>['name'=>'Tritanium'],'999'=>['name'=>'Unrelated Module']],'blueprints'=>[]];
$instance=new GuristasBuildIntelligence($intel,$reference);
$method=new ReflectionMethod(GuristasBuildIntelligence::class,'fulcrumShipBenefits');
$method->setAccessible(true); // Required for PHP 8.0; newer PHP versions allow invoke directly.
$ship=$method->invoke($instance,['product'=>['typeID'=>17930],'materials'=>[['typeID'=>34,'quantity'=>100]],'manufacture_seconds'=>1000]);
$other=$method->invoke($instance,['product'=>['typeID'=>999],'materials'=>[['typeID'=>34,'quantity'=>100]],'manufacture_seconds'=>1000]);
if(!$ship['eligible']||$ship['estimated_material_quantities']!==[94]||$ship['estimated_facility_time_seconds']!==300||$other['eligible']||$other['material_reduction_pct']!==0)throw new RuntimeException('Fulcrum bonus scope/calculation failed');
echo "PASS v40 Fulcrum eligible/noneligible numeric cases\n";
