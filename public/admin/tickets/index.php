<?php
declare(strict_types=1);
$root=dirname(__DIR__,3); require_once $root.'/app/services/TicketService.php';
$viewer=eve_require_user(); $actor=(int)$viewer['character_id'];
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
if (!tickets_staff($actor)) { http_response_code(403); exit('Staff access required. Contact the site owner.'); }
$owner=$actor===tickets_owner(); $error=''; $ticketId=(int)($_POST['id']??$_GET['id']??0);
$themes=['commando'=>'Commando Guri','cryptic'=>'Cryptic Ecdysis','cozen'=>'Cozen Corp','kniraven'=>'Galnet'];
$initialTheme=$_COOKIE['guristas_theme']??'cryptic'; if (!isset($themes[$initialTheme])) $initialTheme='cryptic';
function escape(string $v):string { return eve_e($v); }
function age(string $time):string { $s=max(0,time()-strtotime($time.' UTC')); return $s<3600 ? floor($s/60).'m' : ($s<86400 ? floor($s/3600).'h' : floor($s/86400).'d'); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (!$_POST && (int)($_SERVER['CONTENT_LENGTH']??0)>0) { http_response_code(413); exit('Submission exceeds the server limit. Reduce attachments and try again.'); }
 eve_require_csrf(is_string($_POST['csrf']??null)?$_POST['csrf']:null);
 try {
  $action=$_POST['action']??'';
  if ($action==='save') $ticketId=tickets_write($_POST,$actor,tickets_upload_prepare($_FILES['attachments']??[]));
  elseif ($action==='comment') {
   $ticketId=(int)($_POST['id']??0);
   tickets_comment($ticketId,(string)($_POST['body']??''),(string)($_POST['body_format']??'plain'),$actor,tickets_upload_prepare($_FILES['attachments']??[]));
  } elseif ($action==='grant' || $action==='revoke') {
   if (!$owner) { http_response_code(403); exit('Owner access required.'); }
   $person=(int)($_POST['character_id']??0);
   if ($person===tickets_owner()) throw new InvalidArgumentException('Owner access is managed in configuration.');
   $q=eve_db()->prepare('SELECT character_id FROM eve_characters WHERE character_id=?'); $q->execute([$person]); if (!$q->fetch()) throw new InvalidArgumentException('That character must log in to Guristas.net first.');
   $q=eve_db()->prepare($action==='grant' ? 'INSERT IGNORE INTO guristas_staff(character_id,granted_by) VALUES (?,?)' : 'DELETE FROM guristas_staff WHERE character_id=?'); $q->execute($action==='grant'?[$person,$actor]:[$person]); $ticketId=0;
  } else throw new InvalidArgumentException('Unknown action.');
  header('Location: /admin/tickets/'.($ticketId?'?id='.$ticketId:'?staff=1'),true,303); exit;
 } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
 catch (Throwable $e) { error_log('Tickets: '.$e->getMessage()); $error='Unable to save. Please retry or contact the owner.'; }
}
$people=tickets_people(); $names=array_column($people,'character_name','character_id');
$q=eve_db()->prepare('SELECT * FROM guristas_tickets WHERE id=?'); $q->execute([$ticketId]); $ticket=$q->fetch();
if ($ticketId && !$ticket) { http_response_code(404); exit('Ticket not found.'); }
$statuses=['Backlog','To Do','In Progress','Review','Done','Cancelled'];
?>
<!doctype html><html lang="en" data-operation="raid" data-theme="<?= eve_e($initialTheme) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Staff Tickets // Guristas.net</title>
<?php foreach (['structure','themes','auth','tickets','rich-editor'] as $asset): ?><link rel="stylesheet" href="/assets/css/<?= $asset ?>.css?v=<?= filemtime($root.'/public/assets/css/'.$asset.'.css') ?>"><?php endforeach; ?>
<?php foreach (['themes','site','auth','rich-editor','tickets'] as $asset): ?><script defer src="/assets/js/<?= $asset ?>.js?v=<?= filemtime($root.'/public/assets/js/'.$asset.'.js') ?>"></script><?php endforeach; ?>
<script>window.guristasAccount=<?= json_encode(['signedIn'=>true,'csrf'=>eve_csrf()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script></head>
<body>
<a class="skip-link" href="#tickets">Skip to tickets</a>
<div class="ambient-grid" aria-hidden="true"></div>
<div class="screen-noise" aria-hidden="true"></div>
<div class="scanlines" aria-hidden="true"></div>
    <header class="site-header">
        <div class="shell header-inner">
            <a
                class="brand"
                href="/"
                aria-label="Guristas.net command deck"
            >
                <span
                    class="brand-mark"
                    aria-hidden="true"
                >
                    <svg viewBox="0 0 64 64">
                        <path
                            d="M17 6 29 24l-8 5-11-10L17 6Z"
                        ></path>

                        <path
                            d="M47 6 35 24l8 5 11-10L47 6Z"
                        ></path>

                        <path
                            d="M15 28c4-6 10-9 17-9s13 3 17 9l-3 19-8 10H26l-8-10-3-19Z"
                        ></path>

                        <path
                            class="brand-mark-cut"
                            d="m21 34 9 2-3 8-8-4 2-6Zm22 0-9 2 3 8 8-4-2-6ZM29 49h6l-3 5-3-5Z"
                        ></path>
                    </svg>
                </span>

                <span class="brand-copy">
                    <strong>
                        GURISTAS.NET
                    </strong>

                    <span>
                        Pirate Command Network
                    </span>
                </span>
            </a>

            <div
                class="theme-switcher"
                role="group"
                aria-label="Select Guristas.net visual theme"
                data-theme-switcher
            >
                <?php foreach ($themes as $themeKey => $themeName): ?>
                    <button
                        class="theme-option"
                        type="button"
                        data-theme-option="<?= escape($themeKey) ?>"
                        aria-pressed="<?= $themeKey === $initialTheme ? 'true' : 'false' ?>"
                        title="<?= escape($themeName) ?>"
                    >
                        <span
                            class="theme-swatch"
                            data-theme-preview="<?= escape($themeKey) ?>"
                            aria-hidden="true"
                        ></span>

                        <span class="theme-option-name">
                            <?= escape($themeName) ?>
                        </span>
                    </button>
                <?php endforeach; ?>

                <span
                    id="themeAnnouncement"
                    class="sr-only"
                    aria-live="polite"
                ></span>
            </div>

            <button
                class="menu-button"
                type="button"
                aria-expanded="false"
                aria-controls="site-navigation"
                data-menu-button
            >
                <span class="menu-button-label">
                    Menu
                </span>

                <span
                    class="menu-lines"
                    aria-hidden="true"
                >
                    <i></i>
                    <i></i>
                    <i></i>
                </span>
            </button>

            <?php $navActive = 'tickets'; require $root . '/app/views/partials/site-nav.php'; ?>
        </div>
    </header>


<main id="tickets" class="shell ticket-page">
<div class="ticket-heading"><div><p class="eyebrow">GURISTAS.NET // STAFF OPERATIONS</p><h1>Ticket Command</h1></div><a class="button" href="?new=1">New ticket</a></div>
<nav class="ticket-toolbar" aria-label="Ticket views"><a href="?view=board">Board</a><a href="?view=list">List</a><?php if($owner): ?><a href="?staff=1">Manage staff</a><?php endif; ?></nav>
<?php if($error): ?><p role="alert" class="ticket-error"><?= eve_e($error) ?></p><?php endif; ?>
<?php if(isset($_GET['staff']) && $owner): ?>
<section class="ticket-panel"><h2>Staff access</h2><p>Characters must sign in once before you can grant access. Owner access is configured separately.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= eve_csrf() ?>"><input type="hidden" name="action" value="grant"><label>Character <select name="character_id" required><?php foreach(eve_db()->query('SELECT character_id,character_name FROM eve_characters ORDER BY character_name') as $c): ?><option value="<?= (int)$c['character_id'] ?>"><?= eve_e($c['character_name']) ?> (<?= (int)$c['character_id'] ?>)</option><?php endforeach; ?></select></label><button class="button">Grant staff access</button></form>
<?php foreach($people as $c): ?><div class="ticket-toolbar"><strong><?= eve_e($c['character_name']) ?></strong><?php if((int)$c['character_id']===tickets_owner()): ?>Owner<?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= eve_csrf() ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="character_id" value="<?= (int)$c['character_id'] ?>"><button class="button">Remove access</button></form><?php endif; ?></div><?php endforeach; ?></section>
<?php elseif(isset($_GET['new']) || $ticket):
 $t=$ticket?:['id'=>0,'version'=>0,'title'=>'','summary'=>'','status'=>'Backlog','priority'=>'Normal','category'=>'Feature','assignee_id'=>'','due_date'=>'','blocked_reason'=>'','subtasks_json'=>'[]','summary_format'=>'plain'];
 if($error && ($_POST['action']??'')==='save') { foreach(['title','summary','status','priority','category','assignee_id','due_date','blocked_reason'] as $k) $t[$k]=(string)($_POST[$k]??''); }
?>
<section class="ticket-panel"><h2><?= $ticket?'GURI-'.str_pad((string)$ticketId,3,'0',STR_PAD_LEFT):'New ticket' ?></h2>
<?php if($ticket): ?><p class="ticket-meta">Opened <?= age($ticket['created_at']) ?> ago · In <?= eve_e($ticket['status']) ?> for <?= age($ticket['status_since']) ?> · Updated <?= age($ticket['updated_at']) ?> ago</p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="ticket-form"><input type="hidden" name="csrf" value="<?= eve_csrf() ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="version" value="<?= (int)$t['version'] ?>">
<label class="wide">Title<input name="title" required maxlength="180" value="<?= eve_e($t['title']) ?>"></label><div class="wide"><?php
$editorId='ticket-summary';$editorName='summary';$editorLabel='Summary / definition of done';$editorValue=$t['summary'];$editorFormat=$error && ($_POST['action']??'')==='save' ? (string)($_POST['summary_format']??'plain') : ($t['summary_format']??'plain');
require $root.'/app/views/partials/rich-editor.php';
?></div>
<?php foreach(['status'=>$statuses,'priority'=>['Low','Normal','High','Urgent'],'category'=>['Feature','Bug','Improvement','Research']] as $key=>$opts): ?><label><?= ucfirst($key) ?><select name="<?= $key ?>"><?php foreach($opts as $opt): ?><option <?= $t[$key]===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
<label>Assigned to<select name="assignee_id"><option value="">Unassigned</option><?php if($t['assignee_id'] && !isset($names[$t['assignee_id']])): ?><option selected value="">Previous assignee no longer staff — unassign on save</option><?php endif; ?><?php foreach($people as $c): ?><option value="<?= (int)$c['character_id'] ?>" <?= (string)$t['assignee_id']===(string)$c['character_id']?'selected':'' ?>><?= eve_e($c['character_name']) ?></option><?php endforeach; ?></select></label>
<label>Due date (optional)<input name="due_date" type="date" value="<?= eve_e($t['due_date']??'') ?>"></label><label>Blocked reason (optional)<input name="blocked_reason" maxlength="500" value="<?= eve_e($t['blocked_reason']) ?>"></label>
<?php
$subtaskItems=json_decode($t['subtasks_json'],true)?:[];
if($error && ($_POST['action']??'')==='save') {
 if(($_POST['subtasks_format']??'')==='json') $subtaskItems=json_decode((string)($_POST['subtasks']??'[]'),true)?:[];
 else { $subtaskItems=[];foreach(explode("\n",(string)($_POST['subtasks']??'')) as $line) {if(trim($line)!=='')$subtaskItems[]=['text'=>preg_replace('/^\[(?:x| )\]\s*/i','',trim($line)),'done'=>preg_match('/^\[x\]/i',trim($line))===1];} }
}
$subtaskItems=array_values(array_filter(is_array($subtaskItems)?$subtaskItems:[],fn($s)=>is_array($s)&&is_string($s['text']??null)&&is_bool($s['done']??null)));
?>
<section class="wide" data-subtasks data-items="<?= eve_e(json_encode($subtaskItems,JSON_THROW_ON_ERROR)) ?>"><h3>Subtasks</h3><textarea name="subtasks" rows="5"><?= eve_e(implode("\n",array_map(fn($s)=>($s['done']?'[x] ':'[ ] ').$s['text'],$subtaskItems))) ?></textarea><noscript>One item per line. Prefix completed items with [x].</noscript></section>
<div class="wide"><h3>Ticket attachments</h3><?php if($ticket) tickets_attachment_links($ticketId); ?><label>Add files<input type="file" name="attachments[]" multiple data-attachments accept=".csv,.xls,.xlsx,.xlsm,.png,.jpeg,.jpg,.gif,.doc,.docx,.txt,.md,.json,.pdf"></label><p class="ticket-meta">Up to 5 files · 10 MB each · 25 MB total. Saved with the ticket. Files must be selected again if saving fails.</p></div>
<button class="button">Save ticket</button><a href="?id=<?= $ticketId ?>">Reload saved version</a></form></section>
<?php if($ticket):
$discussionTab=($_GET['tab']??'comments')==='history'?'history':'comments';
$q=eve_db()->prepare('SELECT a.*,c.character_name FROM guristas_ticket_activity a LEFT JOIN eve_characters c ON c.character_id=a.author_id WHERE ticket_id=? ORDER BY a.id DESC');
$q->execute([$ticketId]);$activity=$q->fetchAll();
$commentCount=count(array_filter($activity,fn($a)=>$a['kind']==='comment'));
$historyCount=count($activity)-$commentCount;
?><section class="ticket-panel"><h2>Discussion & history</h2>
<div class="ticket-discussion-tabs" role="tablist" aria-label="Ticket discussion" data-discussion-tabs>
<a id="comments-tab" role="tab" aria-selected="<?= $discussionTab==='comments'?'true':'false' ?>" aria-controls="comments-panel" data-discussion-tab="comments" href="?id=<?= $ticketId ?>&amp;tab=comments#comments-panel">Comments (<?= $commentCount ?>)</a>
<a id="history-tab" role="tab" aria-selected="<?= $discussionTab==='history'?'true':'false' ?>" aria-controls="history-panel" data-discussion-tab="history" href="?id=<?= $ticketId ?>&amp;tab=history#history-panel">History (<?= $historyCount ?>)</a>
</div><div id="comments-panel" role="tabpanel" aria-labelledby="comments-tab" <?= $discussionTab==='comments'?'':'hidden' ?>>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= eve_csrf() ?>"><input type="hidden" name="action" value="comment"><input type="hidden" name="id" value="<?= $ticketId ?>"><?php
$editorId='ticket-comment';$editorName='body';$editorLabel='Add comment';$editorValue=$error && ($_POST['action']??'')==='comment' ? (string)($_POST['body']??'') : '';$editorFormat=$error && ($_POST['action']??'')==='comment' ? (string)($_POST['body_format']??'plain') : 'plain';
require $root.'/app/views/partials/rich-editor.php';
?><label>Comment attachments<input type="file" name="attachments[]" multiple data-attachments accept=".csv,.xls,.xlsx,.xlsm,.png,.jpeg,.jpg,.gif,.doc,.docx,.txt,.md,.json,.pdf"></label><p class="ticket-meta">Up to 5 files · 10 MB each · 25 MB total. You can post files without a message.</p><button class="button">Post comment</button></form>
<?php
$shown=0;
foreach($activity as $a):
if ($a['kind']!=='comment') continue;
$shown++;
?><article class="ticket-activity"><strong><?= eve_e($a['character_name']??'Former character') ?></strong> · <time><?= eve_e($a['created_at']) ?> UTC</time><div class="g-rich-render"><?= guristas_rich_show($a['body'],$a['body_format']??'plain') ?></div><?php if($a['kind']==='comment') tickets_attachment_links($ticketId,(int)$a['id']); ?></article><?php endforeach;
if(!$shown): ?><p class="ticket-meta">No comments yet. Start the discussion above.</p><?php endif; ?></div>
<div id="history-panel" role="tabpanel" aria-labelledby="history-tab" <?= $discussionTab==='history'?'':'hidden' ?>>
<?php foreach($activity as $a): if($a['kind']==='comment') continue; ?>
<article class="ticket-activity"><strong><?= eve_e($a['character_name']??'Former character') ?></strong> · <time><?= eve_e($a['created_at']) ?> UTC</time><div class="g-rich-render"><?= guristas_rich_show($a['body'],$a['body_format']??'plain') ?></div></article>
<?php endforeach; if(!$historyCount): ?><p class="ticket-meta">No history recorded yet.</p><?php endif; ?>
</div></section><?php endif; ?>

<?php else:
 $search=trim((string)($_GET['q']??'')); $status=(string)($_GET['status']??''); $assigned=(string)($_GET['assigned']??''); $priority=(string)($_GET['priority']??'');
 $list=($_GET['view']??'board')==='list';
 $where=[]; $params=[];
 if (!$list && $status !== 'Cancelled') $where[]="status <> 'Cancelled'";
 if($search!=='') { $where[]='(title LIKE ? OR summary LIKE ? OR CONCAT("GURI-",LPAD(id,3,"0")) LIKE ?)'; $params=array_fill(0,3,'%'.$search.'%'); }
 if(in_array($status,$statuses,true)) { $where[]='status=?';$params[]=$status; }
 if(in_array($priority,['Low','Normal','High','Urgent'],true)) { $where[]='priority=?';$params[]=$priority; }
 if($assigned==='me') { $where[]='assignee_id=?';$params[]=$actor; } elseif($assigned==='none') $where[]='assignee_id IS NULL';
 $q=eve_db()->prepare('SELECT * FROM guristas_tickets'.($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY FIELD(priority,'Urgent','High','Normal','Low'),created_at,id"); $q->execute($params); $rows=$q->fetchAll(); $list=($_GET['view']??'board')==='list';
?>
<form class="ticket-toolbar" method="get"><input type="hidden" name="view" value="<?= $list?'list':'board' ?>"><label>Search<input name="q" value="<?= eve_e($search) ?>" placeholder="Title, summary, or GURI-001"></label><label>Status<select name="status"><option value="">All</option><?php foreach($statuses as $s): ?><option <?= $s===$status?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></label><label>Priority<select name="priority"><option value="">All</option><?php foreach(['Urgent','High','Normal','Low'] as $s): ?><option <?= $s===$priority?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></label><label>Assignment<select name="assigned"><option value="">Everyone</option><option value="me" <?= $assigned==='me'?'selected':'' ?>>Mine</option><option value="none" <?= $assigned==='none'?'selected':'' ?>>Unassigned</option></select></label><button class="button">Filter</button><a href="?view=<?= $list?'list':'board' ?>">Clear</a></form>
<p class="ticket-meta"><?= count($rows) ?> tickets · Highest priority first, oldest first within each priority</p>
<div class="<?= $list?'ticket-list':'ticket-board' ?>">
<?php foreach($list?['All tickets']:($status==='Cancelled'?['Cancelled']:array_values(array_diff($statuses,['Cancelled']))) as $column): ?><section class="ticket-column"><h2><?= $column ?> <small>(<?= count(array_filter($rows,fn($t)=>$list||$t['status']===$column)) ?>)</small></h2>
<?php foreach($rows as $t): if(!$list && $t['status']!==$column) continue; $tasks=json_decode($t['subtasks_json'],true); ?><a class="ticket-card" href="?id=<?= (int)$t['id'] ?>"><div class="ticket-meta">GURI-<?= str_pad((string)$t['id'],3,'0',STR_PAD_LEFT) ?> · <span class="priority-<?= strtolower($t['priority']) ?>"><?= eve_e($t['priority']) ?></span> · <?= eve_e($t['category']) ?></div><h3><?= eve_e($t['title']) ?></h3><p><?= eve_e($names[$t['assignee_id']]??($t['assignee_id']?'Former staff':'Unassigned')) ?> · <?= eve_e($t['status']) ?></p><p class="ticket-meta">Opened <?= age($t['created_at']) ?> ago · In status <?= age($t['status_since']) ?></p><?php if($t['due_date']): ?><p class="<?= !in_array($t['status'],['Done','Cancelled'],true) && $t['due_date']<gmdate('Y-m-d')?'ticket-error':'' ?>">Due <?= eve_e($t['due_date']) ?></p><?php endif; ?><?php if($t['blocked_reason']): ?><p class="ticket-error">Blocked: <?= eve_e($t['blocked_reason']) ?></p><?php endif; ?><?php if($tasks): ?><p class="ticket-meta"><?= count(array_filter($tasks,fn($s)=>$s['done'])) ?>/<?= count($tasks) ?> subtasks complete</p><?php endif; ?></a><?php endforeach; ?></section><?php endforeach; ?></div>
<?php endif; ?></main>
<?php require $root.'/app/views/partials/login-modal.php'; ?></body></html>
