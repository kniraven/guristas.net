<?php
declare(strict_types=1);
$root=dirname(__DIR__,3); require_once $root.'/app/services/TicketService.php';
$viewer=eve_require_user(); $actor=(int)$viewer['character_id'];
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
if (!tickets_staff($actor)) { http_response_code(403); exit('Staff access required. Contact the site owner.'); }
$owner=$actor===tickets_owner(); $error=''; $id=(int)($_POST['id']??$_GET['id']??0);
$themes=['commando'=>'Commando Guri','cryptic'=>'Cryptic Ecdysis','cozen'=>'Cozen Corp','kniraven'=>'Galnet'];
$initialTheme=$_COOKIE['guristas_theme']??'cryptic'; if (!isset($themes[$initialTheme])) $initialTheme='cryptic';
function escape(string $v):string { return eve_e($v); }
function age(string $time):string { $s=max(0,time()-strtotime($time.' UTC')); return $s<3600 ? floor($s/60).'m' : ($s<86400 ? floor($s/3600).'h' : floor($s/86400).'d'); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
 eve_require_csrf(is_string($_POST['csrf']??null)?$_POST['csrf']:null);
 try {
  $action=$_POST['action']??'';
  if ($action==='save') $id=tickets_write($_POST,$actor);
  elseif ($action==='comment') {
   $id=(int)($_POST['id']??0); $body=trim((string)($_POST['body']??''));
   if ($body==='' || strlen($body)>10000) throw new InvalidArgumentException('Enter a comment under 10,000 bytes.');
   $q=eve_db()->prepare('SELECT id FROM guristas_tickets WHERE id=?'); $q->execute([$id]); if (!$q->fetch()) throw new InvalidArgumentException('Ticket not found.');
   eve_db()->beginTransaction(); try { $q=eve_db()->prepare("INSERT INTO guristas_ticket_activity(ticket_id,author_id,kind,body,created_at) VALUES (?,?,'comment',?,UTC_TIMESTAMP())"); $q->execute([$id,$actor,$body]); $q=eve_db()->prepare('UPDATE guristas_tickets SET updated_at=UTC_TIMESTAMP() WHERE id=?'); $q->execute([$id]); eve_db()->commit(); } catch(Throwable $e) { eve_db()->rollBack(); throw $e; }
  } elseif ($action==='grant' || $action==='revoke') {
   if (!$owner) { http_response_code(403); exit('Owner access required.'); }
   $person=(int)($_POST['character_id']??0);
   if ($person===tickets_owner()) throw new InvalidArgumentException('Owner access is managed in configuration.');
   $q=eve_db()->prepare('SELECT character_id FROM eve_characters WHERE character_id=?'); $q->execute([$person]); if (!$q->fetch()) throw new InvalidArgumentException('That character must log in to Guristas.net first.');
   $q=eve_db()->prepare($action==='grant' ? 'INSERT IGNORE INTO guristas_staff(character_id,granted_by) VALUES (?,?)' : 'DELETE FROM guristas_staff WHERE character_id=?'); $q->execute($action==='grant'?[$person,$actor]:[$person]); $id=0;
  } else throw new InvalidArgumentException('Unknown action.');
  header('Location: /admin/tickets/'.($id?'?id='.$id:'?staff=1'),true,303); exit;
 } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
 catch (Throwable $e) { error_log('Tickets: '.$e->getMessage()); $error='Unable to save. Please retry or contact the owner.'; }
}
$people=tickets_people(); $names=array_column($people,'character_name','character_id');
$q=eve_db()->prepare('SELECT * FROM guristas_tickets WHERE id=?'); $q->execute([$id]); $ticket=$q->fetch();
if ($id && !$ticket) { http_response_code(404); exit('Ticket not found.'); }
$statuses=['Backlog','Ready','In Progress','Review','Done'];
<!doctype html><html lang="en" data-operation="raid" data-theme="<?= eve_e($initialTheme) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Staff Tickets // Guristas.net</title>
<?php foreach (['structure','themes','auth','tickets'] as $asset): ?><link rel="stylesheet" href="/assets/css/<?= $asset ?>.css?v=<?= filemtime($root.'/public/assets/css/'.$asset.'.css') ?>"><?php endforeach; ?>
<?php foreach (['themes','site','auth'] as $asset): ?><script defer src="/assets/js/<?= $asset ?>.js?v=<?= filemtime($root.'/public/assets/js/'.$asset.'.js') ?>"></script><?php endforeach; ?>
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
 $t=$ticket?:['id'=>0,'version'=>0,'title'=>'','summary'=>'','status'=>'Backlog','priority'=>'Normal','category'=>'Feature','assignee_id'=>'','due_date'=>'','blocked_reason'=>'','subtasks_json'=>'[]'];
 if($error && ($_POST['action']??'')==='save') { foreach(['title','summary','status','priority','category','assignee_id','due_date','blocked_reason'] as $k) $t[$k]=(string)($_POST[$k]??''); }
?>
<section class="ticket-panel"><h2><?= $ticket?'GURI-'.str_pad((string)$id,3,'0',STR_PAD_LEFT):'New ticket' ?></h2>
<?php if($ticket): ?><p class="ticket-meta">Opened <?= age($ticket['created_at']) ?> ago · In <?= eve_e($ticket['status']) ?> for <?= age($ticket['status_since']) ?> · Updated <?= age($ticket['updated_at']) ?> ago</p><?php endif; ?>
<form method="post" class="ticket-form"><input type="hidden" name="csrf" value="<?= eve_csrf() ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="version" value="<?= (int)$t['version'] ?>">
<label class="wide">Title<input name="title" required maxlength="180" value="<?= eve_e($t['title']) ?>"></label><label class="wide">Summary / definition of done<textarea name="summary" rows="5" maxlength="20000"><?= eve_e($t['summary']) ?></textarea></label>
<?php foreach(['status'=>$statuses,'priority'=>['Low','Normal','High','Urgent'],'category'=>['Feature','Bug','Improvement','Research']] as $key=>$opts): ?><label><?= ucfirst($key) ?><select name="<?= $key ?>"><?php foreach($opts as $opt): ?><option <?= $t[$key]===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
<label>Assigned to<select name="assignee_id"><option value="">Unassigned</option><?php if($t['assignee_id'] && !isset($names[$t['assignee_id']])): ?><option selected value="">Previous assignee no longer staff — unassign on save</option><?php endif; ?><?php foreach($people as $c): ?><option value="<?= (int)$c['character_id'] ?>" <?= (string)$t['assignee_id']===(string)$c['character_id']?'selected':'' ?>><?= eve_e($c['character_name']) ?></option><?php endforeach; ?></select></label>
<label>Due date (optional)<input name="due_date" type="date" value="<?= eve_e($t['due_date']??'') ?>"></label><label>Blocked reason (optional)<input name="blocked_reason" maxlength="500" value="<?= eve_e($t['blocked_reason']) ?>"></label>
<label class="wide">Subtasks — one per line; mark completed items with [x]<textarea name="subtasks" rows="5"><?= eve_e($error && ($_POST['action']??'')==='save' ? (string)$_POST['subtasks'] : implode("\n",array_map(fn($s)=>($s['done']?'[x] ':'[ ] ').$s['text'],json_decode($t['subtasks_json'],true)))) ?></textarea></label><button class="button">Save ticket</button><a href="?id=<?= $id ?>">Reload saved version</a></form></section>
<?php if($ticket): ?><section class="ticket-panel"><h2>Comments & activity</h2><form method="post"><input type="hidden" name="csrf" value="<?= eve_csrf() ?>"><input type="hidden" name="action" value="comment"><input type="hidden" name="id" value="<?= $id ?>"><label>Add comment<textarea name="body" required maxlength="10000" rows="3"></textarea></label><button class="button">Post comment</button></form>
<?php $q=eve_db()->prepare('SELECT a.*,c.character_name FROM guristas_ticket_activity a LEFT JOIN eve_characters c ON c.character_id=a.author_id WHERE ticket_id=? ORDER BY a.id DESC'); $q->execute([$id]); foreach($q as $a): ?><article class="ticket-activity"><strong><?= eve_e($a['character_name']??'Former character') ?></strong> · <?= eve_e($a['kind']) ?> · <time><?= eve_e($a['created_at']) ?> UTC</time><p><?= nl2br(eve_e($a['body'])) ?></p></article><?php endforeach; ?></section><?php endif; ?>
<?php else:
 $search=trim((string)($_GET['q']??'')); $status=(string)($_GET['status']??''); $assigned=(string)($_GET['assigned']??''); $priority=(string)($_GET['priority']??'');
 $where=[]; $params=[];
 if($search!=='') { $where[]='(title LIKE ? OR summary LIKE ? OR CONCAT("GURI-",LPAD(id,3,"0")) LIKE ?)'; $params=array_fill(0,3,'%'.$search.'%'); }
 if(in_array($status,$statuses,true)) { $where[]='status=?';$params[]=$status; }
 if(in_array($priority,['Low','Normal','High','Urgent'],true)) { $where[]='priority=?';$params[]=$priority; }
 if($assigned==='me') { $where[]='assignee_id=?';$params[]=$actor; } elseif($assigned==='none') $where[]='assignee_id IS NULL';
 $q=eve_db()->prepare('SELECT * FROM guristas_tickets'.($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY FIELD(priority,'Urgent','High','Normal','Low'),created_at,id"); $q->execute($params); $rows=$q->fetchAll(); $list=($_GET['view']??'board')==='list';
?>
<form class="ticket-toolbar" method="get"><input type="hidden" name="view" value="<?= $list?'list':'board' ?>"><label>Search<input name="q" value="<?= eve_e($search) ?>" placeholder="Title, summary, or GURI-001"></label><label>Status<select name="status"><option value="">All</option><?php foreach($statuses as $s): ?><option <?= $s===$status?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></label><label>Priority<select name="priority"><option value="">All</option><?php foreach(['Urgent','High','Normal','Low'] as $s): ?><option <?= $s===$priority?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></label><label>Assignment<select name="assigned"><option value="">Everyone</option><option value="me" <?= $assigned==='me'?'selected':'' ?>>Mine</option><option value="none" <?= $assigned==='none'?'selected':'' ?>>Unassigned</option></select></label><button class="button">Filter</button><a href="?view=<?= $list?'list':'board' ?>">Clear</a></form>
<p class="ticket-meta"><?= count($rows) ?> tickets · Highest priority first, oldest first within each priority</p>
<div class="<?= $list?'ticket-list':'ticket-board' ?>">
<?php foreach($list?['All tickets']:$statuses as $column): ?><section class="ticket-column"><h2><?= $column ?> <small>(<?= count(array_filter($rows,fn($t)=>$list||$t['status']===$column)) ?>)</small></h2>
<?php foreach($rows as $t): if(!$list && $t['status']!==$column) continue; $tasks=json_decode($t['subtasks_json'],true); ?><a class="ticket-card" href="?id=<?= (int)$t['id'] ?>"><div class="ticket-meta">GURI-<?= str_pad((string)$t['id'],3,'0',STR_PAD_LEFT) ?> · <span class="priority-<?= strtolower($t['priority']) ?>"><?= eve_e($t['priority']) ?></span> · <?= eve_e($t['category']) ?></div><h3><?= eve_e($t['title']) ?></h3><p><?= eve_e($names[$t['assignee_id']]??($t['assignee_id']?'Former staff':'Unassigned')) ?> · <?= eve_e($t['status']) ?></p><p class="ticket-meta">Opened <?= age($t['created_at']) ?> ago · In status <?= age($t['status_since']) ?></p><?php if($t['due_date']): ?><p class="<?= $t['status']!=='Done' && $t['due_date']<gmdate('Y-m-d')?'ticket-error':'' ?>">Due <?= eve_e($t['due_date']) ?></p><?php endif; ?><?php if($t['blocked_reason']): ?><p class="ticket-error">Blocked: <?= eve_e($t['blocked_reason']) ?></p><?php endif; ?><?php if($tasks): ?><p class="ticket-meta"><?= count(array_filter($tasks,fn($s)=>$s['done'])) ?>/<?= count($tasks) ?> subtasks complete</p><?php endif; ?></a><?php endforeach; ?></section><?php endforeach; ?></div>
<?php endif; ?></main>
<?php require $root.'/app/views/partials/login-modal.php'; ?></body></html>
