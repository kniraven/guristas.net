<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/services/CommunityWeb.php';header('Cache-Control: private, no-store');
$id=is_string($_GET['id']??null)?$_GET['id']:'';
try{$ledger=community_network()->read();}catch(Throwable $e){http_response_code(503);exit('The operations archive is unavailable.');}
$entry=$ledger['entries'][$id]??null;
if(!$entry || !in_array($entry['type'],['fleet','supply','report','broadcast'],true) || !CommunityNetwork::visible($entry)){http_response_code(404);exit('Operation not found.');}
$pageTitle=$entry['title'];$pageDescription=$entry['type']==='report'?'A report from the field.':'Read the posted instructions before committing.';$navActive='operations';require dirname(__DIR__,2).'/app/views/partials/public-tool-header.php';
?>
<p><a href="<?= $entry['type']==='broadcast'?'/community/#broadcast':'/operations/#'.$entry['type'] ?>">Back to the board</a></p>
<?php if($entry['status']==='archived'): ?><p role="status">Archived operation. These instructions are retained for the historical record.</p><?php endif; ?>
<section class="tool-card"><p class="eyebrow"><?= escape(strtoupper($entry['type'])) ?> // POSTED <?= escape($entry['updated']) ?></p><p class="community-prose"><?= escape($entry['body']) ?></p>
<?php foreach(['location'=>'Destination','contact'=>'Contact','credit'=>'Credit','campaign'=>'Campaign / system context'] as $field=>$label): if(!empty($entry[$field])): ?><p><strong><?= escape($label) ?>:</strong> <?= escape($entry[$field]) ?></p><?php endif; endforeach; ?>
<?php if($entry['when']): ?><p><strong><?= $entry['type']==='fleet'?'Departure':'Deadline' ?>:</strong> <?= escape(str_replace('T',' ',$entry['when'])) ?> EVE time (UTC)</p><?php endif; ?>
<?php if(!empty($entry['campaign_url'])): ?><p><a href="<?= escape($entry['campaign_url']) ?>" target="_blank" rel="noopener noreferrer">Open campaign reference</a></p><?php endif; ?>
<?php if($entry['url']): ?><a href="<?= escape($entry['url']) ?>" target="_blank" rel="noopener noreferrer">Open the source or recording</a><?php endif; ?>
<?php if(!empty($entry['fleet']) && isset($ledger['entries'][$entry['fleet']]) && CommunityNetwork::visible($ledger['entries'][$entry['fleet']])): ?><p><a href="/operations/entry.php?id=<?= escape($entry['fleet']) ?>">Related fleet: <?= escape($ledger['entries'][$entry['fleet']]['title']) ?></a></p><?php endif; ?>
</section>
<?php if($entry['type']==='supply'): require dirname(__DIR__,2).'/app/views/partials/supply-job.php'; endif; ?>
<?php if($entry['type']==='fleet'): $reports=array_filter(CommunityNetwork::published($ledger,['report']),fn($e)=>($e['fleet']??'')===$entry['id']); if($reports): ?><section class="tool-section"><h2>Reports from this fleet</h2><?php foreach($reports as $report): ?><p><a href="/operations/entry.php?id=<?= escape($report['id']) ?>"><?= escape($report['title']) ?></a></p><?php endforeach; ?></section><?php endif; endif; ?>
<?php require dirname(__DIR__,2).'/app/views/partials/public-tool-footer.php'; ?>
