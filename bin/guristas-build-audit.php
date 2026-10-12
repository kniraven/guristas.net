<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/app/services/MarketIntelligence.php';
require_once dirname(__DIR__).'/app/services/BuildAudit.php';
$intel=new GuristasMarketIntelligence();
echo json_encode(GuristasBuildAudit::summary($intel->dir()),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
