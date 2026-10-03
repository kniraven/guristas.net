<?php
declare(strict_types=1);
require_once __DIR__ . '/EveAuth.php';
require_once __DIR__ . '/RichText.php';
require_once __DIR__ . '/TicketAttachments.php';
function tickets_owner(): int {
 $file = dirname(__DIR__, 2) . '/config/tickets.local.php';
 $config = is_file($file) ? require $file : [];
 return (int)($config['owner_character_id'] ?? 0);
}
function tickets_staff(int $id): bool {
 if ($id > 0 && $id === tickets_owner()) return true;
 $q = eve_db()->prepare('SELECT character_id FROM guristas_staff WHERE character_id = ?');
 $q->execute([$id]); return (bool)$q->fetch();
}
function tickets_people(): array {
 $q = eve_db()->prepare('SELECT character_id, character_name FROM eve_characters WHERE character_id = ? OR character_id IN (SELECT character_id FROM guristas_staff) ORDER BY character_name');
 $q->execute([tickets_owner()]); return $q->fetchAll();
}
function tickets_write(array $b, int $actor, array $uploads = []): int {
 $statuses = ['Backlog','To Do','In Progress','Review','Done','Cancelled'];
 $priorities = ['Low','Normal','High','Urgent'];
 $categories = ['Feature','Bug','Improvement','Research'];
 $title = trim((string)($b['title'] ?? ''));
 $summary = trim((string)($b['summary'] ?? ''));
 $summary = guristas_rich_clean($summary);
 $blocked = trim((string)($b['blocked_reason'] ?? ''));
 if ($title === '' || strlen($title)>180 || strlen($summary)>60000 || strlen($blocked)>500) throw new InvalidArgumentException('Check the title, summary, and blocked reason lengths.');
 foreach (['status'=>$statuses,'priority'=>$priorities,'category'=>$categories] as $key=>$options) if (!in_array($b[$key] ?? '',$options,true)) throw new InvalidArgumentException('Invalid '.$key.'.');
 $assignee = empty($b['assignee_id']) ? null : (int)$b['assignee_id'];
 if ($assignee !== null && !tickets_staff($assignee)) throw new InvalidArgumentException('Assign tickets to current staff only.');
 $due = trim((string)($b['due_date'] ?? ''));
 if ($due !== '') { $d = DateTimeImmutable::createFromFormat('!Y-m-d',$due); if (!$d || $d->format('Y-m-d') !== $due) throw new InvalidArgumentException('Invalid due date.'); }
 $tasks=[];
 if (($b['subtasks_format'] ?? '') === 'json') {
  try { $incoming=json_decode((string)($b['subtasks']??'[]'),true,128,JSON_THROW_ON_ERROR); } catch(Throwable $e) { throw new InvalidArgumentException('Invalid subtask list.'); }
  if (!is_array($incoming) || count($incoming)>100) throw new InvalidArgumentException('Use up to 100 subtasks.');
  foreach ($incoming as $task) {
   if (!is_array($task) || !is_string($task['text']??null) || !is_bool($task['done']??null)) throw new InvalidArgumentException('Invalid subtask.');
   $text=trim($task['text']); if ($text==='') continue;
   if (strlen($text)>300) throw new InvalidArgumentException('Keep each subtask under 300 bytes.');
   $tasks[]=['text'=>$text,'done'=>$task['done']];
  }
 } else {
  $lines=explode("\n",(string)($b['subtasks']??''));
  if(count($lines)>100) throw new InvalidArgumentException('Use up to 100 subtasks.');
  foreach($lines as $line) { $line=trim($line);if($line==='')continue;
   $done=preg_match('/^\[x\]\s*/i',$line)===1;$text=preg_replace('/^\[(?:x| )\]\s*/i','',$line);
   if(strlen($text)>300)throw new InvalidArgumentException('Keep each subtask under 300 bytes.');
   $tasks[]=['text'=>$text,'done'=>$done];
  }
 }
 $moved=[];
 $db=eve_db(); $db->beginTransaction();
 try {
  $id=(int)($b['id'] ?? 0); $old=null;
  if ($id) { $q=$db->prepare('SELECT * FROM guristas_tickets WHERE id=? FOR UPDATE'); $q->execute([$id]); $old=$q->fetch(); if (!$old) throw new InvalidArgumentException('Ticket not found.'); if ((int)$old['version']!==(int)($b['version']??0)) throw new InvalidArgumentException('Someone updated this ticket. Reload before saving your changes.'); }
  $values=[$title,$summary,$b['status'],$b['priority'],$b['category'],$assignee,$due?:null,$blocked,json_encode($tasks,JSON_THROW_ON_ERROR)];
  if ($old) {
   $q=$db->prepare('UPDATE guristas_tickets SET title=?,summary=?,status=?,priority=?,category=?,assignee_id=?,due_date=?,blocked_reason=?,subtasks_json=?,status_since=IF(? <> ?,UTC_TIMESTAMP(),status_since),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?');
   $q->execute([...$values,$old['status'],$b['status'],$id]);
   $changes=[]; foreach (['title','summary','status','priority','category','assignee_id','due_date','blocked_reason'] as $k) { $new=match($k) {'title'=>$title,'summary'=>$summary,'assignee_id'=>$assignee,'due_date'=>$due?:null,'blocked_reason'=>$blocked,default=>$b[$k]}; if ((string)$old[$k] !== (string)$new) $changes[]=in_array($k,['summary','blocked_reason']) ? $k.' changed' : $k.': '.($old[$k]?:'none').' → '.($new?:'none'); }
   if ($old['subtasks_json']!==$values[8]) $changes=array_merge($changes,tickets_subtask_changes(json_decode($old['subtasks_json'],true)?:[],$tasks));
   $body=$changes ? implode("\n",$changes) : 'Ticket saved';
  } else {
   $q=$db->prepare("INSERT INTO guristas_tickets (title,summary,status,priority,category,assignee_id,due_date,blocked_reason,subtasks_json,creator_id,summary_format,created_at,status_since,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,'html',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
   $q->execute([...$values,$actor]); $id=(int)$db->lastInsertId(); $body='Ticket opened';
  }
  if ($uploads) $body .= "\n".count($uploads).' attachment(s) added';
  tickets_upload_store($uploads,$id,null,$actor,$moved);
  $q=$db->prepare('INSERT INTO guristas_ticket_activity(ticket_id,author_id,kind,body,created_at) VALUES (?,?,?, ?,UTC_TIMESTAMP())'); $q->execute([$id,$actor,'change',$body]);
  $db->commit(); return $id;
 } catch (Throwable $e) { $db->rollBack(); tickets_upload_cleanup($moved); throw $e; }
}

function tickets_comment(int $id,string $body,int $actor,array $uploads=[]):void {
 $body=guristas_rich_clean($body);
 if(strlen($body)>60000 || (trim(html_entity_decode(strip_tags($body),ENT_QUOTES,'UTF-8'))==='' && !$uploads)) throw new InvalidArgumentException('Enter a comment or attach a file (60 KB text maximum).');
 $db=eve_db();$moved=[];$db->beginTransaction();
 try {
  $q=$db->prepare('SELECT id FROM guristas_tickets WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetch())throw new InvalidArgumentException('Ticket not found.');
  $q=$db->prepare("INSERT INTO guristas_ticket_activity(ticket_id,author_id,kind,body,body_format,created_at) VALUES (?,?,'comment',?,'html',UTC_TIMESTAMP())");$q->execute([$id,$actor,$body]);$activity=(int)$db->lastInsertId();
  tickets_upload_store($uploads,$id,$activity,$actor,$moved);
  $q=$db->prepare('UPDATE guristas_tickets SET updated_at=UTC_TIMESTAMP() WHERE id=?');$q->execute([$id]);$db->commit();
 } catch(Throwable $e) {$db->rollBack();tickets_upload_cleanup($moved);throw $e;}
}

/** Match descriptions (including duplicates) to distinguish completion from additions. */
function tickets_subtask_changes(array $before,array $after):array {
 $changes=[];$matched=[];
 foreach($after as $task) {
  $found=null;
  foreach($before as $i=>$previous) if(!isset($matched[$i]) && $previous['text']===$task['text']) {$found=$i;break;}
  if($found===null) {$changes[]='Subtask added: '.$task['text'];if($task['done'])$changes[]='Subtask completed: '.$task['text'];}
  else {$matched[$found]=true;if((bool)$before[$found]['done']!==$task['done'])$changes[]=($task['done']?'Subtask completed: ':'Subtask reopened: ').$task['text'];}
 }
 foreach($before as $i=>$task) if(!isset($matched[$i]))$changes[]='Subtask removed: '.$task['text'];
 if(!$changes)$changes[]='Subtasks reordered';
 return $changes;
}
