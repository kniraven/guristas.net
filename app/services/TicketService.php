<?php
declare(strict_types=1);
require_once __DIR__ . '/EveAuth.php';
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
function tickets_write(array $b, int $actor): int {
 $statuses = ['Backlog','Ready','In Progress','Review','Done'];
 $priorities = ['Low','Normal','High','Urgent'];
 $categories = ['Feature','Bug','Improvement','Research'];
 $title = trim((string)($b['title'] ?? ''));
 $summary = trim((string)($b['summary'] ?? ''));
 $blocked = trim((string)($b['blocked_reason'] ?? ''));
 if ($title === '' || strlen($title)>180 || strlen($summary)>20000 || strlen($blocked)>500) throw new InvalidArgumentException('Check the title, summary, and blocked reason lengths.');
 foreach (['status'=>$statuses,'priority'=>$priorities,'category'=>$categories] as $key=>$options) if (!in_array($b[$key] ?? '',$options,true)) throw new InvalidArgumentException('Invalid '.$key.'.');
 $assignee = empty($b['assignee_id']) ? null : (int)$b['assignee_id'];
 if ($assignee !== null && !tickets_staff($assignee)) throw new InvalidArgumentException('Assign tickets to current staff only.');
 $due = trim((string)($b['due_date'] ?? ''));
 if ($due !== '') { $d = DateTimeImmutable::createFromFormat('!Y-m-d',$due); if (!$d || $d->format('Y-m-d') !== $due) throw new InvalidArgumentException('Invalid due date.'); }
 $tasks=[];
 foreach (array_slice(explode("\n",(string)($b['subtasks'] ?? '')),0,100) as $line) {
  $line=trim($line); if ($line==='') continue;
  $done=preg_match('/^\[x\]\s*/i',$line)===1;
  $text=preg_replace('/^\[(?:x| )\]\s*/i','',$line);
  if (strlen($text)>300) throw new InvalidArgumentException('Keep each subtask under 300 bytes.');
  $tasks[]=['text'=>$text,'done'=>$done];
 }
 $db=eve_db(); $db->beginTransaction();
 try {
  $id=(int)($b['id'] ?? 0); $old=null;
  if ($id) { $q=$db->prepare('SELECT * FROM guristas_tickets WHERE id=? FOR UPDATE'); $q->execute([$id]); $old=$q->fetch(); if (!$old) throw new InvalidArgumentException('Ticket not found.'); if ((int)$old['version']!==(int)($b['version']??0)) throw new InvalidArgumentException('Someone updated this ticket. Reload before saving your changes.'); }
  $values=[$title,$summary,$b['status'],$b['priority'],$b['category'],$assignee,$due?:null,$blocked,json_encode($tasks,JSON_THROW_ON_ERROR)];
  if ($old) {
   $q=$db->prepare('UPDATE guristas_tickets SET title=?,summary=?,status=?,priority=?,category=?,assignee_id=?,due_date=?,blocked_reason=?,subtasks_json=?,status_since=IF(? <> ?,UTC_TIMESTAMP(),status_since),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?');
   $q->execute([...$values,$old['status'],$b['status'],$id]);
   $changes=[]; foreach (['title','summary','status','priority','category','assignee_id','due_date','blocked_reason'] as $k) { $new=match($k) {'title'=>$title,'summary'=>$summary,'assignee_id'=>$assignee,'due_date'=>$due?:null,'blocked_reason'=>$blocked,default=>$b[$k]}; if ((string)$old[$k] !== (string)$new) $changes[]=in_array($k,['summary','blocked_reason']) ? $k.' changed' : $k.': '.($old[$k]?:'none').' → '.($new?:'none'); }
   if ($old['subtasks_json']!==$values[8]) $changes[]='Subtasks updated';
   $body=$changes ? implode("\n",$changes) : 'Ticket saved';
  } else {
   $q=$db->prepare('INSERT INTO guristas_tickets (title,summary,status,priority,category,assignee_id,due_date,blocked_reason,subtasks_json,creator_id,created_at,status_since,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())');
   $q->execute([...$values,$actor]); $id=(int)$db->lastInsertId(); $body='Ticket opened';
  }
  $q=$db->prepare('INSERT INTO guristas_ticket_activity(ticket_id,author_id,kind,body,created_at) VALUES (?,?,?, ?,UTC_TIMESTAMP())'); $q->execute([$id,$actor,'change',$body]);
  $db->commit(); return $id;
 } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
