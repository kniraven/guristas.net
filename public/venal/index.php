<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/services/EveAuth.php';
eve_session();
$viewer = eve_current_user();

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once dirname(__DIR__, 2) . '/app/services/SiteTheme.php';
$themes = SiteTheme::LABELS;

$initialTheme = SiteTheme::initial();

$publicRoot = dirname(__DIR__);
?>
<!doctype html>
<html lang="en" data-theme="<?= escape($initialTheme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#080503">
    <meta name="description" content="Interactive 3D Venal intelligence map for Guristas.net, powered by public EVE Online data.">

    <title>Venal Intelligence // Guristas.net</title>

    <link rel="stylesheet" href="/assets/css/structure.css?v=<?= filemtime($publicRoot . '/assets/css/structure.css') ?>">
    <link rel="stylesheet" href="/assets/css/themes.css?v=<?= filemtime($publicRoot . '/assets/css/themes.css') ?>">
    <link rel="stylesheet" href="/assets/css/venal.css?v=<?= filemtime($publicRoot . '/assets/css/venal.css') ?>">

    <script src="/assets/js/themes.js?v=<?= filemtime($publicRoot . '/assets/js/themes.js') ?>" defer></script>
    <script src="/assets/js/site.js?v=<?= filemtime($publicRoot . '/assets/js/site.js') ?>" defer></script>

    <link rel="stylesheet" href="/assets/css/auth.css?v=<?= filemtime($publicRoot . '/assets/css/auth.css') ?>">
    <script>window.guristasAccount = <?= json_encode(['signedIn' => (bool)$viewer, 'csrf' => $viewer ? eve_csrf() : null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="/assets/js/auth.js?v=<?= filemtime($publicRoot . '/assets/js/auth.js') ?>" defer></script>

    <script type="importmap">
    {
        "imports": {
            "three": "https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js",
            "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.180.0/examples/jsm/"
        }
    }
    </script>
</head>
<body class="venal-body">
    <a class="skip-link" href="#venal-map-shell">Skip to Venal intelligence map</a>

    <div class="ambient-grid" aria-hidden="true"></div>
    <div class="screen-noise" aria-hidden="true"></div>
    <div class="scanlines" aria-hidden="true"></div>

<?php
$navActive = 'venal';
$headerClass = 'site-header venal-site-header';
$headerSubtitle = 'Venal Intelligence Network';
require dirname(__DIR__, 2) . '/app/views/partials/site-header.php';
?>

    <main id="venal-map-shell" class="venal-map-shell" data-venal-map-shell>
        <div id="map" class="venal-map-canvas" aria-label="Interactive 3D map of Venal"></div>

        <div class="venal-hud">
            <section id="titlePanel" class="venal-panel venal-title-panel">
                <p class="venal-kicker">VENAL // REGIONAL INTELLIGENCE</p>
                <h1>3D theater map</h1>
                <p id="status" class="venal-status" aria-live="polite">Establishing CCP ESI relay…</p>
            </section>

            <section id="controls" class="venal-panel venal-controls" aria-label="Venal map controls">
                <div class="venal-search-wrap">
                    <label class="sr-only" for="search">Find a Venal system</label>
                    <input id="search" type="search" autocomplete="off" placeholder="Find system (e.g. H-PA29)">
                    <button id="findBtn" type="button">Find</button>
                </div>

                <label class="venal-control-row">
                    <span>Auto rotate</span>
                    <input id="autoRotate" type="checkbox" checked>
                </label>

                <label class="venal-control-row">
                    <span>System labels</span>
                    <input id="showLabels" type="checkbox" checked>
                </label>

                <label class="venal-control-row">
                    <span>Gate network</span>
                    <input id="showGates" type="checkbox" checked>
                </label>

                <label class="venal-control-row">
                    <span>Regional exits</span>
                    <input id="showExits" type="checkbox">
                </label>

                <label class="venal-control-stack" for="activityLayer">
                    <span>Intelligence layer</span>
                    <select id="activityLayer">
                        <option value="none">None</option>
                        <optgroup label="Combat">
                            <option value="ship_kills">Ship kills</option>
                            <option value="pod_kills">Pod kills</option>
                        </optgroup>
                        <optgroup label="Activity">
                            <option value="npc_kills">NPC kills</option>
                            <option value="ship_jumps">Ship jumps</option>
                        </optgroup>
                    </select>
                </label>

                <label class="venal-control-stack" for="activityWindow">
                    <span>Time window</span>
                    <select id="activityWindow" disabled>
                        <option value="1">1 hour</option>
                        <option value="3">3 hours</option>
                        <option value="6">6 hours</option>
                        <option value="12">12 hours</option>
                        <option value="24">24 hours</option>
                        <option value="72">3 days</option>
                        <option value="168">7 days</option>
                        <option value="720">30 days</option>
                    </select>
                    <small>Longer windows use Guristas.net's rolling ESI history; coverage builds as snapshots are collected.</small>
                </label>

                <label class="venal-control-stack" for="zScale">
                    <span>Vertical exaggeration <output id="zScaleValue" for="zScale">1.00×</output></span>
                    <input id="zScale" type="range" min="0.35" max="3" value="1" step="0.05">
                    <small>1.00× preserves the true relative geometry.</small>
                </label>

                <div class="venal-control-row venal-reset-row">
                    <span>View</span>
                    <button id="resetBtn" type="button">Reset</button>
                </div>
            </section>

            <section id="info" class="venal-panel venal-info" aria-live="polite">
                <div class="venal-info-heading">
                    <strong id="infoName">SYSTEM</strong>
                    <span id="infoSec">0.0</span>
                </div>
                <div id="infoGrid" class="venal-info-grid"></div>
            </section>

            <section class="venal-panel venal-legend" aria-label="Map legend">
                <strong>NETWORK KEY</strong>
                <span><i class="legend-line legend-solid"></i> same constellation</span>
                <span><i class="legend-line legend-dashed"></i> constellation boundary</span>
                <span><i class="legend-line legend-dotted"></i> regional exit</span>
                <span id="activityLegend" class="venal-activity-legend">Intelligence layer: none</span>
                <div id="activityScale" class="venal-activity-scale" hidden>
                    <div class="venal-activity-scale-row">
                        <span>LOW</span>
                        <i id="activityScaleBar" class="venal-activity-scale-bar"></i>
                        <span>HIGH</span>
                    </div>
                    <small id="activityScaleMax">hottest system: 0</small>
                    <small>Bubble size + color show relative intensity; number is exact.</small>
                </div>
            </section>

            <div class="venal-help" aria-hidden="true">
                Drag: rotate · Wheel: zoom · Right-drag: pan<br>
                Hover: identify · Click: inspect
            </div>

            <div class="venal-source-wrap">
                <button id="sourceBtn" class="venal-source-button" type="button" aria-expanded="false" aria-controls="sourcePanel">
                    DATA // CCP ESI <span aria-hidden="true">ⓘ</span>
                </button>

                <section id="sourcePanel" class="venal-panel venal-source-panel" hidden>
                    <div class="venal-source-heading">
                        <strong>DATA PROVENANCE</strong>
                        <button id="sourceClose" type="button" aria-label="Close data source panel">×</button>
                    </div>
                    <div id="sourceList"></div>
                </section>
            </div>
        </div>

        <div id="tooltip" class="venal-tooltip" role="tooltip"></div>

        <section id="errorBox" class="venal-panel venal-error-box" role="alert" hidden>
            <h2>Venal intelligence relay unavailable</h2>
            <p>The map could not retrieve Guristas.net's Venal data feed.</p>
            <p id="errorDetail"></p>
        </section>
    </main>

    <script type="module" src="/assets/js/venal-map.js?v=<?= filemtime($publicRoot . '/assets/js/venal-map.js') ?>"></script>
<?php require dirname(__DIR__, 2) . '/app/views/partials/login-modal.php'; ?>
</body>
</html>
