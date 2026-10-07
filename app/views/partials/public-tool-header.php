<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/EveAuth.php';
require_once dirname(__DIR__, 2) . '/services/SiteTheme.php';
eve_session();
$viewer = eve_current_user();
function escape(string $value): string { return eve_e($value); }
$themes = SiteTheme::LABELS;
$initialTheme = SiteTheme::initial();
$headerClass = 'site-header';
$headerSubtitle = 'Pirate Command Network';
?>
<!doctype html><html lang="en" data-theme="<?= escape($initialTheme) ?>" data-operation="raid">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= escape($pageTitle) ?> // Guristas.net</title><meta name="description" content="<?= escape($pageDescription) ?>">
<?php foreach (['structure', 'themes', 'auth', 'public-tools'] as $sheet): ?><link rel="stylesheet" href="/assets/css/<?= $sheet ?>.css?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/css/' . $sheet . '.css') ?>"><?php endforeach; ?>
<?php foreach (['themes', 'site', 'auth', 'public-tools'] as $script): ?><script src="/assets/js/<?= $script ?>.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/' . $script . '.js') ?>" defer></script><?php endforeach; ?>
</head><body><a class="skip-link" href="#field-tool">Skip to field tool</a><div class="ambient-grid" aria-hidden="true"></div>
<?php require __DIR__ . '/site-header.php'; ?>
<main class="public-tool shell" id="field-tool"><header class="tool-heading"><p class="eyebrow">GURISTAS FIELD NETWORK // OPEN ACCESS</p><h1><?= escape($pageTitle) ?></h1><p><?= escape($pageDescription) ?></p></header>
<nav class="field-tabs" aria-label="Public field tools"><a href="/war/guristas/" <?= $navActive === 'war' ? 'aria-current="page"' : '' ?>>War Room</a><a href="/missions/" <?= $navActive === 'missions' ? 'aria-current="page"' : '' ?>>Missions &amp; Agents</a><a href="/signals/" <?= $navActive === 'signals' ? 'aria-current="page"' : '' ?>>Signals</a><a href="/industry/" <?= $navActive === 'build' ? 'aria-current="page"' : '' ?>>Supply</a><a href="/lore/">Lore</a><a href="/operations/">Operations</a><a href="/community/">Transmissions</a><a href="/ships/">Ships</a><a href="/venal/">Venal</a><a href="/join/" <?= $navActive === 'join' ? 'aria-current="page"' : '' ?>>Enlist</a></nav>
