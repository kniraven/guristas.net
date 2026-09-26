<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/services/EveAuth.php';
eve_session();
$viewer = eve_current_user();


function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$siteVersion = '0.3.0';
$currentYear = (int) date('Y');
$themes = [
    'commando' => 'Commando Guri',
    'cryptic' => 'Cryptic Ecdysis',
    'cozen' => 'Cozen Corp',
    'kniraven' => 'Galnet',
];
$initialTheme = $_COOKIE['guristas_theme'] ?? 'cryptic';
if (!array_key_exists($initialTheme, $themes)) {
    $initialTheme = 'cryptic';
}
$navigation = [
    ['label' => 'Command', 'href' => '/'],
    ['label' => 'Venal', 'href' => '/venal/'],
    ['label' => 'Ships', 'href' => '/ships/'],
    ['label' => 'War', 'href' => '/#war-room'],
    ['label' => 'Build', 'href' => '/#industry-preview'],
    ['label' => 'Lore', 'href' => '/#lore'],
    ['label' => 'Signals', 'href' => '/#signals'],
];
?>
<!doctype html>
<html lang="en" data-operation="raid" data-theme="<?= escape($initialTheme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#080503">
    <meta name="description" content="Compare EVE Online ship hull attributes from CCP's Static Data Export.">
    <title>Ship Explorer // Guristas.net</title>
    <link rel="stylesheet" href="/assets/css/structure.css?v=<?= filemtime(__DIR__ . '/../assets/css/structure.css') ?>">
    <link rel="stylesheet" href="/assets/css/themes.css?v=<?= filemtime(__DIR__ . '/../assets/css/themes.css') ?>">
    <link rel="stylesheet" href="/assets/css/ship-explorer.css?v=<?= filemtime(__DIR__ . '/../assets/css/ship-explorer.css') ?>">
    <script src="/assets/js/themes.js?v=<?= filemtime(__DIR__ . '/../assets/js/themes.js') ?>" defer></script>
    <script src="/assets/js/site.js?v=<?= filemtime(__DIR__ . '/../assets/js/site.js') ?>" defer></script>
    <script src="/assets/js/ship-explorer.js?v=<?= filemtime(__DIR__ . '/../assets/js/ship-explorer.js') ?>" defer></script>
    <link rel="stylesheet" href="/assets/css/auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/auth.css') ?>">
    <script>window.guristasAccount = <?= json_encode(['signedIn' => (bool)$viewer, 'csrf' => $viewer ? eve_csrf() : null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="/assets/js/auth.js?v=<?= filemtime(__DIR__ . '/../assets/js/auth.js') ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#ship-explorer">Skip to ship explorer</a>
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

            <nav
                id="site-navigation"
                class="site-nav"
                aria-label="Primary navigation"
                data-navigation
            >
                <?php foreach ($navigation as $item): ?>
                    <a href="<?= escape($item['href']) ?>">
                        <?= escape($item['label']) ?>
                    </a>
                <?php endforeach; ?>

                <a
                    class="nav-cta"
                    href="/#join"
                >
                    Join the operation
                </a>
                <?php require dirname(__DIR__, 2) . '/app/views/partials/account-nav.php'; ?>
            </nav>
        </div>
    </header>


<main id="ship-explorer" class="ships-page">
<div class="ships-content"><p class="eyebrow">GURISTAS.NET // SHIP DATABASE</p><h1>Ship Explorer</h1><p class="lead">Compare base hull attributes from CCP’s Static Data Export. Select a size or type to see the low and high values for that group.</p>
<section class="controls" aria-label="Ship filters"><label>Search ships<input id="search" type="search" placeholder="Name or faction…" autocomplete="off"></label><details class="facet" data-field="size"><summary>Size: All</summary><div class="facet-options"></div></details><details class="facet" data-field="family"><summary>Family: All</summary><div class="facet-options"></div></details><details class="facet" data-field="type"><summary>Type: All</summary><div class="facet-options"></div></details><details class="facet" data-field="faction"><summary>Faction: All</summary><div class="facet-options"></div></details><details class="facet" data-field="tech"><summary>Tech: All</summary><div class="facet-options"></div></details><details class="facet" data-field="variant"><summary>Variant: All</summary><div class="facet-options"></div></details><label class="lock-control">Lock left columns<select id="lockColumns"><option value="0">None</option><option value="1">1 column</option><option value="2">2 columns</option><option value="3">3 columns</option></select></label><button id="columns" type="button">Columns & order</button><button id="reset" type="button">Reset view</button></section>
<div class="meta"><span id="count" role="status">Loading ships…</span><span id="source"></span><span>Ranges show matching ships; where one end is generally better, it is on the right.</span></div><div class="table-wrap" tabindex="0" aria-label="Scrollable ship comparison table"><table><thead id="head"></thead><tbody id="body"></tbody></table></div>
<p><small>Base, unfitted values. Resistances are damage resisted, not damage taken. Align time uses the 75% velocity threshold. Peak capacitor recharge is an estimate; excess capacitor recharge depends on an individual fit. Drone control range depends on pilot skills and modifiers, so only a hull’s specific range bonus is shown.</small></p>
<dialog id="dialog"><h2>Columns &amp; order</h2><p><small>Drag rows or use the arrow buttons to reorder. Changes save in this browser.</small></p><ul class="column-list" id="columnList"></ul><button id="close" type="button">Done</button></dialog>
</div>
</main>
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
