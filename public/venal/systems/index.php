<?php
$reference=json_decode((string)file_get_contents(dirname(__DIR__,3).'/app/data/venal-systems.json'),true,512,JSON_THROW_ON_ERROR);
$query=is_string($_GET['q']??null)?substr(trim($_GET['q']),0,120):'';
$systems=array_filter($reference['systems'],fn($s)=>$query==='' || stripos($s['name'].' '.$s['constellation'],$query)!==false);
$pageTitle='Venal System Directory';$pageDescription='Find a system without loading the 3D map. Static geography remains available when the live relay or graphics renderer fails.';$navActive='venal';
require dirname(__DIR__,3).'/app/views/partials/public-tool-header.php';
?>
<p><a href="/venal/">Open the 3D map</a> · <a href="/missions/">Find mission contacts</a> · <a href="/lore/?dossier=venal">Read the Venal dossier</a></p>
<form class="tool-controls" method="get"><label>System or constellation<input name="q" value="<?= escape($query) ?>" maxlength="120"></label><button class="secondary-button">Find systems</button><a href="/venal/systems/">Reset</a></form>
<p><?= count($systems) ?> matching systems. Geography: <?= escape($reference['source']) ?>, retrieved <?= escape($reference['retrieved_at']) ?>.</p>
<p class="tool-note">Venal is null security space. Reported activity does not show pilots waiting at gates and cannot prove a safe route. Plan travel in EVE.</p>
<div data-venal-directory><button class="secondary-button" type="button" data-venal-refresh>Load hourly activity</button><p role="status" data-venal-list-status>Activity has not been requested. Geography works without JavaScript.</p>
<div class="tool-table-wrap"><table class="tool-table"><caption>Venal systems, with optional reported hourly activity</caption><thead><tr><th>System</th><th>Constellation</th><th>Security</th><th>Gates</th><th>Ship jumps</th><th>Ship kills</th><th>Pod kills</th></tr></thead><tbody><?php foreach($systems as $system): ?><tr data-system-id="<?= (int)$system['id'] ?>"><th><?= escape($system['name']) ?></th><td><?= escape($system['constellation']) ?></td><td><?= number_format($system['security'],2) ?></td><td><?= (int)$system['gates'] ?></td><?php foreach(['ship_jumps','ship_kills','pod_kills'] as $field): ?><td data-activity="<?= $field ?>">Not loaded</td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></div>
<script src="/assets/js/venal-directory.js?v=<?= filemtime(dirname(__DIR__,2).'/assets/js/venal-directory.js') ?>" defer></script>
<?php require dirname(__DIR__,3).'/app/views/partials/public-tool-footer.php'; ?>
