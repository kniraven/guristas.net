<?php
$entries=require dirname(__DIR__,2).'/app/data/guristas-lore.php';
$key=is_string($_GET['dossier']??null)?$_GET['dossier']:'';
if($key!=='' && !isset($entries[$key])) {http_response_code(404);$key='';}
$pageTitle=$key?$entries[$key]['title']:'Recovered Guristas Dossiers';
$pageDescription='Trace the founders, Venal, Crielere and the Deathless alliance. Short briefings with links to the official records.';
$navActive='lore';require dirname(__DIR__,2).'/app/views/partials/public-tool-header.php';
?>
<p class="tool-note">Fan-written summaries of official EVE lore, checked 7 October 2026. Field instructions and Cozen ambitions are separate from canon. Historical accounts sometimes differ; uncertain details remain uncertain.</p>
<nav class="field-tabs" aria-label="Dossiers"><?php foreach($entries as $id=>$entry): ?><a href="?dossier=<?= escape($id) ?>" <?= $key===$id?'aria-current="page"':'' ?>><?= escape(ucfirst($id)) ?></a><?php endforeach; ?></nav>
<div class="tool-grid"><?php foreach($key?[$key=>$entries[$key]]:$entries as $id=>$entry): ?><article class="tool-card cut-panel"><p class="eyebrow"><?= escape($entry['tag']) ?></p><h2><?= escape($entry['title']) ?></h2><p><?= escape($entry['text']) ?></p><p><a href="<?= escape($entry['source']) ?>" target="_blank" rel="noopener noreferrer">Read the full official record: <?= escape($entry['sourceLabel']) ?></a></p><div class="tool-buttons"><a href="<?= escape($entry['tool']) ?>"><?= escape($entry['toolLabel']) ?></a><a href="?dossier=<?= escape($entry['next']) ?>">Next dossier: <?= escape(ucfirst($entry['next'])) ?></a></div></article><?php endforeach; ?></div>
<?php if($key): ?><a href="/lore/">All dossiers</a><?php endif; ?>
<?php require dirname(__DIR__,2).'/app/views/partials/public-tool-footer.php'; ?>
