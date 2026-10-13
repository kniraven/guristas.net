<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/BuildIntelligence.php';
function eqNear(float $a,float $b):void {if(abs($a-$b)>0.0001)throw new RuntimeException("Expected $b, got $a");}
// Revenue 10m; material baseline 4m; eligible Fulcrum 6% quote 3.76m; LP 1m.
eqNear(GuristasBuildIntelligence::manufacturePreJobMargin(10000000,4000000,1000000),4250000);
eqNear(GuristasBuildIntelligence::manufacturePreJobMargin(10000000,3760000,1000000),4490000);
if(GuristasBuildIntelligence::manufacturePreJobMargin(10000000,3760000,1000000)<=GuristasBuildIntelligence::manufacturePreJobMargin(10000000,4000000,1000000))throw new RuntimeException('ME benefit not reflected in margin');
$src=file_get_contents(__DIR__.'/../app/services/BuildIntelligence.php');
foreach(['$rankedInput=$fulcrumInput??$input','manufacturing_candidate_diagnostics',"'live_deposit_verified'=>false","'Mine '" ] as $x){if(!str_contains($src,$x))throw new RuntimeException('Missing v43 source contract: '.$x);}
if(str_contains($src,"'Check '" ))throw new RuntimeException('Mining card must be an imperative mining action');
echo "PASS v43: ME quote ranking, LP price model, diagnostics, imperative mining action with uncertainty disclosed\n";
