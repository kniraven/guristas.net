<?php
declare(strict_types=1);
$src=file_get_contents(dirname(__DIR__).'/app/services/BuildIntelligence.php');
foreach (['unmatched_lp_blueprints','catalog_match_rate_pct','full_catalog_only_matches','recipe_max_runs','manufacture_seconds','job fees/hauling excluded'] as $expected) {
    if(!str_contains($src,$expected))throw new RuntimeException('Missing qualification diagnostic: '.$expected);
}
echo "PASS v38: BUILD reporting and pre-job qualification markers\n";
