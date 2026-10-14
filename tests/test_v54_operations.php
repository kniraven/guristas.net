<?php
require_once dirname(__DIR__).'/app/services/IntelligenceOperations.php';
$jobs=GuristasOperations::jobs();
if(count($jobs)!==11)throw new RuntimeException('All 11 cron jobs must be registered');
if(!GuristasOperations::validTimeList([0,30],59)||GuristasOperations::validTimeList([0,0],59)||GuristasOperations::validTimeList([60],59))throw new RuntimeException('Time list validation broken');
foreach($jobs as $id=>$j){if(!$j['cmd']||!is_array($j['minutes'])||!is_array($j['hours']))throw new RuntimeException('Malformed registry '.$id);}
if(GuristasOperations::digest('Collector already running.',3)['kind']!=='overlap')throw new RuntimeException('Overlap classification broken');
if(GuristasOperations::digest('Allowed memory size exhausted',1)['kind']!=='memory')throw new RuntimeException('Memory classification broken');
if(GuristasOperations::digest('rate limit 429',1)['kind']!=='api_rate')throw new RuntimeException('Rate limit classification broken');
if(!str_contains(file_get_contents(dirname(__DIR__).'/public/admin/operations/index.php'),'tickets_owner()'))throw new RuntimeException('Owner protection absent');
echo "PASS v54: 11 registered jobs, schedule validation, error classification, owner control\n";
