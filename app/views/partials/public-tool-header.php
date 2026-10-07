<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/services/EveAuth.php';
require_once dirname(__DIR__, 2) . '/services/SiteTheme.php';
eve_session();
$viewer = eve_current_user();
header('Cache-Control: private, no-store');
header('Vary: Cookie');
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
<link rel="stylesheet" href="/assets/css/site-usability.css?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/css/site-usability.css') ?>">
</head><body class="field-page"><a class="skip-link" href="#field-tool">Skip to field tool</a><div class="ambient-grid" aria-hidden="true"></div>
<?php require __DIR__ . '/site-header.php'; ?>
<main class="public-tool shell" id="field-tool"><header class="tool-heading"><p class="eyebrow"><?= escape($pageAccess ?? 'GURISTAS FIELD NETWORK // OPEN ACCESS') ?></p><h1><?= escape($pageTitle) ?></h1><p><?= escape($pageDescription) ?></p></header>
<nav class="field-network-nav" aria-label="Public field tools"><a href="/operations/" <?= $navActive === 'operations' ? 'aria-current="page"' : '' ?>>Operations</a><a href="/community/" <?= $navActive === 'community' ? 'aria-current="page"' : '' ?>>Transmissions</a><details><summary>All field tools</summary><div class="field-tabs"><?php foreach (['war'=>['War Room','/war/guristas/'],'missions'=>['Missions & Agents','/missions/'],'build'=>['Supply Console','/industry/'],'lore'=>['Lore','/lore/'],'signals'=>['Signals','/signals/'],'ships'=>['Ships','/ships/'],'venal'=>['Venal','/venal/'],'join'=>['Enlist','/join/']] as $fieldNavKey => [$fieldNavLabel,$fieldNavHref]): ?><a href="<?= escape($fieldNavHref) ?>" <?= $navActive === $fieldNavKey ? 'aria-current="page"' : '' ?>><?= escape($fieldNavLabel) ?></a><?php endforeach; ?></div></details></nav>
