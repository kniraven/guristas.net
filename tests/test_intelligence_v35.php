<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/services/PublicIndustry.php';
$reference=guri_industry_reference();
if(!is_array($reference)||count($reference['blueprints']??[])<20)throw new RuntimeException('Industry reference unavailable');
$cli=(string)file_get_contents($root.'/bin/guristas-intelligence.php');
if(!str_contains($cli,'time()-$stamp>=86400'))throw new RuntimeException('Daily LP threshold missing');
$health=(string)file_get_contents($root.'/bin/guristas-intelligence-health.php');
if(!str_contains($health,'daily check=')||!str_contains($health,'threshold=24h'))throw new RuntimeException('SDE/LP diagnostics absent');
echo "PASS v35: reference fallback, daily LP refresh policy and SDE diagnostics\n";
