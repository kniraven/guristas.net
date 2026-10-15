<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__).'/app/services/IntelligenceOperations.php';
$view=GuristasOperations::view();
printf("Scheduler: %s; managed: %d; legacy: %d\n",$view['mode']??'unknown',$view['scheduler']['managed_entries']??-1,$view['scheduler']['legacy_entries']??-1);
foreach($view['jobs']??[] as $job){
 $last=$job['last']??[];$fresh=$job['freshness']??[];
 printf("%-10s last=%-10s samples=%-3d freshness=%-10s ended=%s\n",$job['id']??'?', $last['status']??'none',$job['samples']??0,$fresh['status']??'unknown',isset($last['ended'])?date('c',(int)$last['ended']):'none');
 if(in_array($job['id']??'',['domain','offers','sde'],true))printf("  detail: %s\n",substr((string)($last['summary']??$fresh['reason']??'No verified managed completion'),0,220));
}
