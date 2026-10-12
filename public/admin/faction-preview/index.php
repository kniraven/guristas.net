<?php
declare(strict_types=1);
$root = dirname(__DIR__, 3);
require_once $root.'/app/services/EveAuth.php';
require_once $root.'/app/services/TicketService.php';
require_once $root.'/app/services/FactionAccess.php';
$viewer=eve_require_user();
if (tickets_owner()<1 || (int)$viewer['character_id']!==tickets_owner()) {http_response_code(403);exit('Site owner only.');}
eve_session();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$choices=['off'=>'LIVE EVE STATUS','guest'=>'Logged out visitor','neutral'=>'Not enlisted','unknown'=>'Status unavailable','guristas'=>'Guristas','angel'=>'Angel Cartel','caldari'=>'Caldari State','gallente'=>'Gallente Federation','amarr'=>'Amarr Empire','minmatar'=>'Minmatar Republic'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    eve_require_csrf($_POST['csrf']??null);
    $choice=(string)($_POST['preview']??'off');
    if (!array_key_exists($choice,$choices)) {http_response_code(400);exit('Invalid preview identity.');}
    if ($choice==='off') unset($_SESSION['guristas_faction_visual_preview']);
    else $_SESSION['guristas_faction_visual_preview']=$choice;
    // Redirect only to a local path. Never accept an external or protocol-relative URL.
    $return=(string)($_POST['return']??'/admin/faction-preview/');
    if (!str_starts_with($return,'/') || str_starts_with($return,'//') || preg_match('/[\\\x00-\x1f]/',$return)) $return='/admin/faction-preview/';
    header('Location: '.$return,true,303);exit;
}
$current=$_SESSION['guristas_faction_visual_preview']??'off';
if (!array_key_exists($current,$choices))$current='off';
$live=GuristasFactionAccess::resolve($viewer);
function e(string $s):string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Faction Preview // Guristas Admin</title>
<style>body{margin:0;background:#140f1a;color:#f2e8e8;font:16px system-ui,sans-serif;padding:clamp(20px,5vw,56px)}main{max-width:690px;margin:auto;background:#251b2b;border:1px solid #ad7e55;padding:28px;border-radius:12px}h1{color:#ffba7a}p{line-height:1.6}select,button,a{font:inherit}select,button{padding:12px;background:#17101b;color:#f5e9e8;border:1px solid #c1935e}button{cursor:pointer}a{color:#ffc488}label{display:block;margin-bottom:10px}code{color:#edc59c}nav{display:flex;gap:22px;flex-wrap:wrap;margin-top:24px}.hint{border-left:3px solid #ca995f;padding-left:14px}</style></head><body><main>
<p>☠ GURISTAS.NET // OWNER CONTROLS</p><h1>Faction experience preview</h1>
<p>Signed in as <strong><?= e((string)($viewer['character_name']??'Site owner')) ?></strong>. Actual EVE faction status: <strong><?= e($live['posture']??'unknown') ?></strong>.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= e(eve_csrf()) ?>"><label for="preview">Simulate visitor experience</label><select name="preview" id="preview">
<?php foreach($choices as $k=>$v):?><option value="<?= e($k) ?>" <?= $current===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select> <button type="submit">Apply preview</button></form>
<p class="hint">Visual preview only. It does not change real SSO credentials, faction membership, API permissions, staff privileges, or private data access. Enemy screens appear on regular pages, not here, so you can always restore the live view.</p>
<nav><a href="/">☞ Preview homepage</a><a href="/industry/">☞ Preview BUILD</a><a href="/admin/">☞ Return to admin</a></nav>
</main></body></html>
