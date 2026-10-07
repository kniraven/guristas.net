<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/services/EveAuth.php';require_once dirname(__DIR__,2).'/app/services/CommunityNetwork.php';
header('Cache-Control: private, no-store');$user=eve_require_user();
try{$ledger=community_network()->read();}catch(Throwable $e){http_response_code(503);exit('The submission record is unavailable.');}
$pageTitle='Your Transmissions';$pageDescription='Track your submissions and review notes. Publication happens only after officer approval.';$navActive='community';require dirname(__DIR__,2).'/app/views/partials/public-tool-header.php';
$mine=array_filter($ledger['entries'],fn($entry)=>($entry['author']??0)===(int)$user['character_id']);
?>
<p><a href="/community/submit.php">Submit art or a comic</a> · <a href="/community/">Public archive</a></p>
<?php if(!$mine): ?><p>You have not submitted a transmission yet.</p><?php endif; ?>
<div class="tool-grid"><?php foreach($mine as $entry): ?><article class="tool-card"><h2><?= escape($entry['title']) ?></h2><p><?= escape(ucfirst($entry['status'])) ?></p><p><?= escape($entry['review_note']??'') ?></p><a href="/community/read.php?id=<?= escape($entry['id']) ?>&amp;preview=1">View your submission</a></article><?php endforeach; ?></div>
<?php require dirname(__DIR__,2).'/app/views/partials/public-tool-footer.php'; ?>
