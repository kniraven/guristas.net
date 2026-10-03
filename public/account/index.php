<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/services/EveAuth.php';
eve_session();
$viewer = eve_require_user();


function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$siteVersion = '0.3.0';
$currentYear = (int) date('Y');
require_once dirname(__DIR__, 2) . '/app/services/SiteTheme.php';
$themes = SiteTheme::LABELS;
$initialTheme = SiteTheme::initial();
$shipsFile = dirname(__DIR__) . '/assets/data/ships.json';
$shipData = is_file($shipsFile) ? json_decode((string)file_get_contents($shipsFile), true) : [];
$shipOptions = [];
foreach ($shipData['ships'] ?? [] as $ship) {
    if (isset($ship['id'], $ship['name'])) $shipOptions[(int)$ship['id']] = (string)$ship['name'];
}
asort($shipOptions, SORT_NATURAL | SORT_FLAG_CASE);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    eve_require_csrf($_POST['csrf'] ?? null);
    if (($_POST['action'] ?? '') === 'save') {
        $theme = (string)($_POST['theme'] ?? '');
        $favorite = (string)($_POST['favorite_ship'] ?? '');
        if (!array_key_exists($theme, $themes) || ($favorite !== '' && (!ctype_digit($favorite) || !array_key_exists((int)$favorite, $shipOptions)))) {
            http_response_code(400); exit('Invalid setting.');
        }
        $stmt = eve_db()->prepare('UPDATE eve_character_settings SET preferred_theme = ?, favorite_ship_id = ? WHERE character_id = ?');
        $stmt->execute([$theme, $favorite === '' ? null : (int)$favorite, $viewer['character_id']]);
        setcookie('guristas_theme', $theme, ['expires'=>time()+31536000, 'path'=>'/', 'samesite'=>'Lax', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    } elseif (($_POST['action'] ?? '') === 'refresh') {
        eve_store_character((int)$viewer['character_id'], $viewer['character_name']);
    } else { http_response_code(400); exit('Invalid action.'); }
    header('Location: /account/?saved=1', true, 303); exit;
}
?>
<!doctype html>
<html lang="en" data-operation="raid" data-theme="<?= escape($initialTheme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#080503">
    <meta name="description" content="Manage your Guristas.net character profile and site settings.">
    <title>Account Settings // Guristas.net</title>
    <link rel="stylesheet" href="/assets/css/structure.css?v=<?= filemtime(__DIR__ . '/../assets/css/structure.css') ?>">
    <link rel="stylesheet" href="/assets/css/themes.css?v=<?= filemtime(__DIR__ . '/../assets/css/themes.css') ?>">
    <script src="/assets/js/themes.js?v=<?= filemtime(__DIR__ . '/../assets/js/themes.js') ?>" defer></script>
    <script src="/assets/js/site.js?v=<?= filemtime(__DIR__ . '/../assets/js/site.js') ?>" defer></script>
    <link rel="stylesheet" href="/assets/css/auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/auth.css') ?>">
    <script>window.guristasAccount = <?= json_encode(['signedIn' => (bool)$viewer, 'csrf' => $viewer ? eve_csrf() : null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="/assets/js/auth.js?v=<?= filemtime(__DIR__ . '/../assets/js/auth.js') ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#account">Skip to account</a>
<div class="ambient-grid" aria-hidden="true"></div>
<div class="screen-noise" aria-hidden="true"></div>
<div class="scanlines" aria-hidden="true"></div>
<?php
$navActive = 'account';
$headerClass = 'site-header';
$headerSubtitle = 'Pirate Command Network';
require dirname(__DIR__, 2) . '/app/views/partials/site-header.php';
?>


<main id="account" class="account-page"><div class="shell">
<p class="eyebrow">GURISTAS.NET // CAPSULEER PROFILE</p>
<h1>Account settings</h1>
<?php if (isset($_GET['saved'])): ?><p class="saved" role="status">Settings saved.</p><?php endif; ?>
<div class="account-grid">
<section class="account-panel" aria-labelledby="profile-heading">
<h2 id="profile-heading">Character</h2>
<div class="account-profile"><img src="https://images.evetech.net/characters/<?= (int)$viewer['character_id'] ?>/portrait?size=128" alt="Portrait of <?= escape($viewer['character_name']) ?>" width="96" height="96"><strong><?= escape($viewer['character_name']) ?></strong></div>
<dl>
<dt>Character ID</dt><dd><?= (int)$viewer['character_id'] ?></dd>
<dt>Origin race</dt><dd><?= escape($viewer['race_name'] ?: ($viewer['race_id'] ? 'Race ID ' . $viewer['race_id'] : 'Not available')) ?></dd>
<dt>Bloodline</dt><dd><?= escape($viewer['bloodline_name'] ?: ($viewer['bloodline_id'] ? 'Bloodline ID ' . $viewer['bloodline_id'] : 'Not available')) ?></dd>
<dt>Ancestry</dt><dd><?= escape($viewer['ancestry_name'] ?: ($viewer['ancestry_id'] ? 'Ancestry ID ' . $viewer['ancestry_id'] : 'Not available')) ?></dd>
<dt>Corporation</dt><dd><?= escape($viewer['corporation_name'] ?: 'Not available') ?></dd>
<dt>Alliance</dt><dd><?= escape($viewer['alliance_name'] ?: 'None or not available') ?></dd>
<dt>Born</dt><dd><?= escape($viewer['birthday'] ?: 'Not available') ?> UTC</dd>
</dl>
<p class="note">Origin shows character creation choices. It does not prove present faction allegiance. Public EVE details update when you sign in or refresh them here.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= escape(eve_csrf()) ?>"><button type="submit" name="action" value="refresh">Refresh EVE details</button></form>
</section>
<section class="account-panel" aria-labelledby="settings-heading">
<h2 id="settings-heading">Site preferences</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?= escape(eve_csrf()) ?>">
<label>Preferred theme
<select name="theme">
<?php foreach ($themes as $key => $label): ?><option value="<?= escape($key) ?>" <?= $viewer['preferred_theme'] === $key ? 'selected' : '' ?>><?= escape($label) ?></option><?php endforeach; ?>
</select></label>
<label>Favorite ship
<select name="favorite_ship"><option value="">None</option>
<?php foreach ($shipOptions as $id => $shipName): ?><option value="<?= (int)$id ?>" <?= (int)$viewer['favorite_ship_id'] === (int)$id ? 'selected' : '' ?>><?= escape($shipName) ?></option><?php endforeach; ?>
</select></label>
<button type="submit" name="action" value="save">Save settings</button>
</form>
<p class="note">Your ship table's visible columns, order, and locked columns also sync to this character while signed in.</p>
<form action="/auth/logout.php" method="post"><input type="hidden" name="csrf" value="<?= escape(eve_csrf()) ?>"><button type="submit">Log out</button></form>
</section></div></div></main>
    <footer class="site-footer">
        <div class="shell footer-grid">
            <div>
                <strong>
                    GURISTAS.NET
                </strong>

                <p>
                    Unofficial EVE Online Guristas community network.
                </p>
            </div>

            <div class="footer-status">
                <span>
                    NETWORK VERSION
                </span>

                <strong>
                    <?= escape($siteVersion) ?>
                </strong>
            </div>

            <div class="footer-status">
                <span>
                    YEAR
                </span>

                <strong>
                    <?= $currentYear ?>
                </strong>
            </div>
        </div>

        <div class="shell legal">
            <p>
                EVE Online and all related marks are the property of
                CCP hf. Guristas.net is not affiliated with or endorsed
                by CCP.
            </p>
        </div>
    </footer>
<?php require dirname(__DIR__, 2) . '/app/views/partials/login-modal.php'; ?>
</body>
</html>
