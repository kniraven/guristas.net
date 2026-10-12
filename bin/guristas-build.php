<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
require_once dirname(__DIR__).'/app/services/BuildAudit.php';
if(($argv[1]??'')!=='publish'){fwrite(STDERR,"Usage: php bin/guristas-build.php publish\n");exit(2);}
$intel=new GuristasMarketIntelligence();$dir=$intel->dir();
if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Build cache unavailable');
$lock=fopen($dir.'/build-publisher.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Build publisher busy.\n");exit(3);}
try {$report=(new GuristasBuildIntelligence($intel))->collect();$intel->publish('build-orders',['data'=>$report,'collected_at'=>gmdate('c')]);$audit=GuristasBuildAudit::record($dir,$report);echo json_encode(['ok'=>true,'status'=>$report['status'],'published'=>count($report['orders']),'coverage'=>$report['coverage'],'shadow_audit'=>$audit],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";}
finally {flock($lock,LOCK_UN);fclose($lock);}
