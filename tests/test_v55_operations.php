<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/services/IntelligenceOperations.php';
$jobs=GuristasOperations::jobs();if(count($jobs)!==11)throw new RuntimeException('Expected 11 managed jobs');
foreach($jobs as $id=>$j){foreach(['source','pulls','purpose','hint'] as $f)if(empty($j[$f]))throw new RuntimeException("Missing $f for $id");}
$js=file_get_contents(dirname(__DIR__).'/public/assets/js/intelligence-operations.js');
foreach(['function tooltip(','function cadence(','aria-pressed','ops-help','awaiting_first_run','previous.status'] as $term){if(!str_contains($js,$term))throw new RuntimeException('Missing UI contract: '.$term);}
$css=file_get_contents(dirname(__DIR__).'/public/assets/css/intelligence-operations.css');
foreach(['#ops-health','#ops-filters button','.ops-help'] as $term)if(!str_contains($css,$term))throw new RuntimeException('Missing CSS: '.$term);
echo "PASS v55: job sources and purposes, themed controls, readable cadence, run-state distinctions, completion notifications\n";
