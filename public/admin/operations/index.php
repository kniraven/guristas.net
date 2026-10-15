<?php
declare(strict_types=1);
$root=dirname(__DIR__,3);
require_once $root.'/app/services/TicketService.php';
require_once $root.'/app/services/SiteTheme.php';
require_once $root.'/app/services/IntelligenceOperations.php';
$viewer=eve_require_user();$actor=(int)$viewer['character_id'];
if(!tickets_staff($actor)){http_response_code(403);exit('Staff access required');}
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$isOwner=$actor===tickets_owner();
if(isset($_GET['api'])){
    header('Content-Type: application/json; charset=utf-8');
    try {
        if($_SERVER['REQUEST_METHOD']==='GET'){$out=GuristasOperations::view();$out['owner']=$isOwner;$out['ok']=true;echo json_encode($out,JSON_INVALID_UTF8_SUBSTITUTE);exit;}
        if(!$isOwner){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Only the site owner can change schedules or run jobs.']);exit;}
        $input=json_decode((string)file_get_contents('php://input'),true);
        if(!is_array($input))throw new InvalidArgumentException('Invalid request.');
        eve_require_csrf($input['csrf']??null);
        $id=(string)($input['id']??'');$jobs=GuristasOperations::jobs();if(!isset($jobs[$id]))throw new InvalidArgumentException('Unknown job.');
        $action=(string)($input['action']??'');
        if(GuristasOperations::scheduler()['mode']!=='managed')throw new InvalidArgumentException('Legacy or unverified scheduling is active. Managed controls are unavailable until verified activation.');
        if($action==='run'){$out=GuristasOperations::addRequest($id,'manual',$actor);}
        elseif($action==='settings'){
            $mins=$input['minutes']??null;$hours=$input['hours']??null;
            if(!GuristasOperations::validTimeList($mins,59)||!GuristasOperations::validTimeList($hours,23))throw new InvalidArgumentException('Choose valid hours (0–23) and minutes (0–59).');
            $timeout=filter_var($input['timeout']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>10,'max_range'=>1800]]);
            if(!$timeout)throw new InvalidArgumentException('Timeout must be between 10 and 1800 seconds.');
            $notify=(string)($input['notify']??'');if(!in_array($notify,['failure','recovery','all','none'],true))throw new InvalidArgumentException('Invalid notification choice.');
            $enabled=$input['enabled']??null;if(!is_bool($enabled))throw new InvalidArgumentException('Enabled must be a true/false value.');
            GuristasOperations::withLock(static function()use($id,$mins,$hours,$timeout,$notify,$enabled){$s=GuristasOperations::state();$s['settings'][$id]=['minutes'=>array_values($mins),'hours'=>array_values($hours),'timeout'=>$timeout,'notify'=>$notify,'enabled'=>$enabled];GuristasOperations::save($s);});
            $out=['saved'=>true];
        }else throw new InvalidArgumentException('Unknown action.');
        echo json_encode(['ok'=>true]+$out);exit;
    }catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);exit;}
    catch(Throwable $e){http_response_code(503);error_log('Operations UI: '.$e->getMessage());echo json_encode(['ok'=>false,'error'=>'Operations service is unavailable. Check storage/operations permissions and server logs.']);exit;}
}
$initialTheme=SiteTheme::initial();$csrf=eve_csrf();
function ops_h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en" data-theme="<?=ops_h($initialTheme)?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Intelligence Operations // Guristas.net</title>
<?php foreach(['structure','themes','auth','tickets','intelligence-operations'] as $asset):?><link rel="stylesheet" href="/assets/css/<?=$asset?>.css?v=<?=filemtime($root.'/public/assets/css/'.$asset.'.css')?>"><?php endforeach;?>
<?php foreach(['themes','site','auth','intelligence-operations'] as $asset):?><script defer src="/assets/js/<?=$asset?>.js?v=<?=filemtime($root.'/public/assets/js/'.$asset.'.js')?>"></script><?php endforeach;?>
<script>window.guristasAccount=<?=json_encode(['signedIn'=>true,'csrf'=>$csrf],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;window.operationsOwner=<?=$isOwner?'true':'false'?>;</script></head>
<body><a class="skip-link" href="#operations">Skip to operations</a><div class="ambient-grid" aria-hidden="true"></div><div class="screen-noise" aria-hidden="true"></div><div class="scanlines" aria-hidden="true"></div>
<?php $navActive='admin';$headerClass='site-header';$headerSubtitle='Pirate Command Network';require $root.'/app/views/partials/site-header.php';?>
<main id="operations" class="shell ticket-page"><div class="ticket-heading"><div><p class="eyebrow">GURISTAS.NET // ADMIN OPERATIONS</p><h1>Intelligence Operations</h1><p>Schedules, execution history, manual controls and health diagnostics. Schedules and displayed times use America/Chicago (Central) time. Runtime statistics include successful completed jobs only.</p></div></div>
<section class="ticket-panel"><p id="ops-mode" role="status">Checking active scheduler...</p><div class="ops-summary"><div><strong id="ops-healthy">—</strong><small>Last run succeeded</small></div><div><strong id="ops-active">—</strong><small>Running / queued</small></div><div><strong id="ops-errors">—</strong><small>Needs attention</small></div><div><strong id="ops-tick">—</strong><small>Last managed tick</small></div></div><div class="ticket-toolbar"><a class="button" href="/admin/">Back to Admin</a><button id="ops-health" type="button">Run health check</button></div><p id="ops-notice" role="status" aria-live="polite"></p></section>
<section class="ticket-panel"><h2>Scheduled jobs</h2><p>Controls are available only when the managed scheduler is active. Legacy runs are not inferred from historical managed records. Read-only access for staff; owner controls for changes and manual execution.</p><div class="ticket-toolbar" id="ops-filters"><button data-group="ALL" type="button" aria-pressed="true">All</button><button data-group="RAID" type="button">RAID</button><button data-group="TRADE" type="button">TRADE</button><button data-group="BUILD" type="button">BUILD</button><button data-group="SYSTEM" type="button">System</button></div><div id="ops-jobs" class="ops-grid"><p>Loading operations...</p></div></section>
<section class="ticket-panel"><h2>Other system timers</h2><p>Read-only observations from systemd. A successful service exit does not establish dataset freshness.</p><div id="ops-systemd" class="ops-systemd">Loading timer status...</div></section>
</main><dialog id="ops-dialog"><form method="dialog"><button class="ops-close" aria-label="Close" type="submit">×</button></form><div id="ops-details"></div></dialog></body></html>
