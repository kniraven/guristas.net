<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/services/EveAuth.php';
eve_session();
$viewer = eve_current_user();

/**
 * Guristas.net Command Deck
 *
 * The command deck still contains several local placeholder feeds.
 * Public ESI infrastructure and the Venal intelligence map are now
 * connected through backend services; market, insurgency, corporation,
 * music, gallery, comic, and donation systems will follow.
 */

function escape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$siteVersion = '0.3.0';

require_once dirname(__DIR__) . '/app/services/SiteTheme.php';
$themes = SiteTheme::LABELS;

/**
 * The cookie allows PHP to render the visitor's last theme
 * before JavaScript loads, preventing an incorrect-theme flash.
 */
$initialTheme = SiteTheme::initial();

/**
 * The server renders the correct Gila image for the initial theme.
 * themes.js keeps the image synchronized when the visitor changes
 * themes without reloading the page.
 */
$themeShipImages = [
    'commando' => 'gila.png',
    'cryptic' => 'Gila_Cryptic_Ecdysis.png',
    'cozen' => 'gila_cozen_corp.png',
    'kniraven' => 'gila_galnet.png',
];

$initialShipImage =
    $themeShipImages[$initialTheme]
    ?? $themeShipImages['cryptic'];


$operations = [
    'raid' => [
        'number' => '01',
        'title' => 'Raid',
        'subtitle' => 'Corrupt the warzone',
        'description' => 'Learn Guristas insurgencies, locate active objectives, prepare a ship, and join a pirate fleet.',
        'target' => 'war-room',
        'tag' => 'Combat',
    ],

    'build' => [
        'number' => '02',
        'title' => 'Build',
        'subtitle' => 'Fabricate Guristas hulls',
        'description' => 'Calculate blueprints, source components, compare facilities, and prepare ships for The Fulcrum.',
        'target' => 'industry-preview',
        'tag' => 'Industry',
    ],

    'trade' => [
        'number' => '03',
        'title' => 'Trade',
        'subtitle' => 'Supply The Fulcrum',
        'description' => 'Identify market shortages and move profitable ships, modules, ammunition, drones, and materials.',
        'target' => 'fulcrum',
        'tag' => 'Market',
    ],

    'lore' => [
        'number' => '04',
        'title' => 'Investigate',
        'subtitle' => 'Recover the real history',
        'description' => 'Follow Fatal, Rabbit, Venal, Crielere, the Deathless, and the rise of the modern Guristas.',
        'target' => 'lore',
        'tag' => 'Intelligence',
    ],

    'signals' => [
        'number' => '05',
        'title' => 'Intercept',
        'subtitle' => 'Seize the broadcast',
        'description' => 'Monitor live streams, pirate music, propaganda, comics, transmissions, and enemy media.',
        'target' => 'signals',
        'tag' => 'Signals',
    ],

    'join' => [
        'number' => '06',
        'title' => 'Join',
        'subtitle' => 'Become part of the operation',
        'description' => 'Enlist with the Guristas, find a suitable corporation, or apply to Cozen Corp at The Fulcrum.',
        'target' => 'join',
        'tag' => 'Recruitment',
    ],
];

$commandStatuses = [
    [
        'label' => 'Transmission',
        'value' => 'Open channel',
        'detail' => 'Kniraven on Twitch; live status unverified',
        'tone' => 'quiet',
    ],

    [
        'label' => 'Insurgency',
        'value' => 'Check War Room',
        'detail' => 'Public campaign report; no login required',
        'tone' => 'warning',
    ],

    [
        'label' => 'The Fulcrum',
        'value' => 'Check listed stock',
        'detail' => 'Public station orders in the Supply Console',
        'tone' => 'active',
    ],

    [
        'label' => 'Network',
        'value' => 'Public Alpha',
        'detail' => 'Command Deck v' . $siteVersion,
        'tone' => 'active',
    ],
];

$currentYear = (int) date('Y');

?>
<!doctype html>

<html
    lang="en"
    data-operation="raid"
    data-theme="<?= escape($initialTheme) ?>"
>
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="theme-color"
        content="#080503"
    >

    <meta
        name="description"
        content="Guristas.net is an interactive EVE Online Guristas command network for lore, insurgencies, industry, propaganda, community art, and The Fulcrum."
    >

    <title>Guristas.net // Pirate Command Network</title>

    <link
        rel="stylesheet"
        href="assets/css/structure.css?v=<?= filemtime(__DIR__ . '/assets/css/structure.css') ?>"
    >

    <link
        rel="stylesheet"
        href="assets/css/themes.css?v=<?= filemtime(__DIR__ . '/assets/css/themes.css') ?>"
    >

    <script
        src="assets/js/themes.js?v=<?= filemtime(__DIR__ . '/assets/js/themes.js') ?>"
        defer
    ></script>

    <script
        src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"
        defer
    ></script>
    <link rel="stylesheet" href="/assets/css/auth.css?v=<?= filemtime(__DIR__ . '/assets/css/auth.css') ?>">
    
    <script src="/assets/js/auth.js?v=<?= filemtime(__DIR__ . '/assets/js/auth.js') ?>" defer></script>
<script src="/assets/js/public-tools.js?v=<?= filemtime(__DIR__ . '/assets/js/public-tools.js') ?>" defer></script>
</head>

<body>
    <a
        class="skip-link"
        href="#command"
    >
        Skip to command deck
    </a>

    <div
        class="ambient-grid"
        aria-hidden="true"
    ></div>

    <div
        class="screen-noise"
        aria-hidden="true"
    ></div>

    <div
        class="scanlines"
        aria-hidden="true"
    ></div>

    

    <section
        class="boot-screen"
        data-boot
        aria-label="Establishing connection to Guristas.net"
    >
        <div class="boot-terminal">
            <div
                class="boot-mark"
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
                        class="boot-mark-cut"
                        d="m21 34 9 2-3 8-8-4 2-6Zm22 0-9 2 3 8 8-4-2-6ZM29 49h6l-3 5-3-5Z"
                    ></path>
                </svg>
            </div>

            <p class="boot-kicker">
                GURISTAS.NET
            </p>

            <h1>
                Pirate command network
            </h1>

            <div
                class="boot-sequence"
                aria-live="polite"
            >
                <span>
                    Recovering damaged transmission fragments
                </span>

                <span>
                    Reconstructing breached hull telemetry
                </span>

                <span>
                    Injecting unstable holographic handshake
                </span>

                <span>
                    Broadcast identity forcibly overwritten
                </span>
            </div>

            <button
                class="text-button"
                type="button"
                data-skip-boot
            >
                Skip connection sequence
            </button>
        </div>
    </section>

<?php
$navActive = 'command';
$headerClass = 'site-header';
$headerSubtitle = 'Pirate Command Network';
require dirname(__DIR__) . '/app/views/partials/site-header.php';
?>

    <main id="command">
        <section class="hero shell">
            <div class="hero-copy reveal">
                <p class="eyebrow">
                    VENAL RELAY // PUBLIC ACCESS
                </p>

                <h1>
                    The State made
                    <span>
                        a Fatal mistake.
                    </span>
                </h1>

                <p class="hero-lede">
                    Learn the history. Build the ships. Corrupt the
                    warzone. Supply The Fulcrum. Guristas.net is an
                    independent command network for every capsuleer
                    interested in the Guristas.
                </p>

                <div class="hero-actions">
                    <a
                        class="primary-button"
                        href="/join/"
                    >
                        New pilot? Start here
                    </a>

                    <a
                        class="secondary-button"
                        href="/war/guristas/"
                    >
                        Open War Room
                    </a>
                </div>

                <div
                    class="selected-operation"
                    aria-live="polite"
                >
                    <span>
                        Selected operation
                    </span>

                    <strong id="selectedOperationLabel">
                        RAID // CORRUPT THE WARZONE
                    </strong>
                </div>
            </div>

            <div class="hero-visual reveal">
                <div
                    class="ship-frame"
                    data-ship-frame
                >
                    <div
                        class="ship-orbit orbit-one"
                        aria-hidden="true"
                    ></div>

                    <div
                        class="ship-orbit orbit-two"
                        aria-hidden="true"
                    ></div>

                    <img
                        id="heroShip"
                        src="assets/images/ships/<?= escape($initialShipImage) ?>"
                        alt="Guristas Gila cruiser"
                        data-theme-ship
                    >

                    <div
                        class="target-lock"
                        aria-hidden="true"
                    >
                        <span></span>
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>

                    <div class="ship-readout">
                        <span>
                            HULL // GILA
                        </span>

                        <span>
                            FACTION // GURISTAS
                        </span>

                        <span>
                            STATUS // COMBAT READY
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <section
            class="command-status shell reveal"
            aria-label="Current network status"
        >
            <?php foreach ($commandStatuses as $status): ?>
                <article
                    class="status-item status-<?= escape($status['tone']) ?>"
                >
                    <span class="status-label">
                        <?= escape($status['label']) ?>
                    </span>

                    <strong <?= $status['label'] === 'Insurgency' ? 'data-public-campaign-status' : '' ?>>
                        <?= escape($status['value']) ?>
                    </strong>

                    <small <?= $status['label'] === 'Insurgency' ? 'data-public-campaign-detail' : '' ?>>
                        <?= escape($status['detail']) ?>
                    </small>
                </article>
            <?php endforeach; ?>
        </section>

        <section
            id="operations"
            class="section shell"
            aria-labelledby="operations-title"
        >
            <div class="section-heading reveal">
                <div>
                    <p class="eyebrow">
                        SELECT A DIRECTIVE
                    </p>

                    <h2 id="operations-title">
                        New pilot? Start here
                    </h2>
                </div>

                <p>
                    Guristas.net remembers your selection and
                    prioritizes related tools whenever you return.
                </p>
            </div>

            <div class="operation-selector reveal">
                <?php foreach ($operations as $key => $operation): ?>
                    <button
                        class="operation-chip"
                        type="button"
                        data-operation="<?= escape($key) ?>"
                        data-target="<?= escape($operation['target']) ?>"
                        aria-pressed="<?= $key === 'raid' ? 'true' : 'false' ?>"
                    >
                        <span>
                            <?= escape($operation['number']) ?>
                        </span>

                        <?= escape($operation['title']) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="operation-grid">
                <?php foreach ($operations as $key => $operation): ?>
                    <article
                        class="operation-card cut-panel reveal"
                        data-operation-card="<?= escape($key) ?>"
                    >
                        <div class="operation-card-top">
                            <span class="card-number">
                                <?= escape($operation['number']) ?>
                            </span>

                            <span class="card-tag">
                                <?= escape($operation['tag']) ?>
                            </span>
                        </div>

                        <h3>
                            <?= escape($operation['title']) ?>
                        </h3>

                        <strong class="operation-subtitle">
                            <?= escape($operation['subtitle']) ?>
                        </strong>

                        <p>
                            <?= escape($operation['description']) ?>
                        </p>

                        <a
                            href="#<?= escape($operation['target']) ?>"
                            data-operation-link="<?= escape($key) ?>"
                        >
                            Open directive

                            <span aria-hidden="true">
                                →
                            </span>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section id="war-room" class="section shell feature-layout" aria-labelledby="war-room-title">
            <article class="feature-copy reveal"><p class="eyebrow">INSURGENCY WAR ROOM</p><h2 id="war-room-title">Find the campaign. Make your sortie count.</h2><p>Read the public campaign report, compare systems and learn how to earn your cut while helping the Guristas advance. Start with an eligible objective and a ship you can afford to replace.</p><div class="feature-actions"><a class="primary-button" href="/war/guristas/">Open live War Room</a><a class="secondary-button" href="/missions/">Find a mission contact</a></div></article>
            <aside class="war-console cut-panel reveal"><div class="console-header"><span>YOUR FIRST SORTIE</span><strong>OPEN FIELD GUIDE</strong></div><div class="war-objectives"><article><span>01</span><div><strong>Check access</strong><p>Use the enlistment guide. Joining Cozen is optional.</p><a href="/join/">Start here →</a></div></article><article><span>02</span><div><strong>Prepare</strong><p>Compare hulls, check site restrictions and plan your exit.</p><a href="/ships/">Explore ships →</a></div></article><article><span>03</span><div><strong>Choose a manageable objective</strong><p>Check campaign systems, local activity and personal contribution in EVE.</p><a href="/war/guristas/">Read the report →</a></div></article></div></aside>
        </section>

        <section id="industry-preview" class="section shell" aria-labelledby="industry-title"><div class="section-heading reveal"><div><p class="eyebrow">GURISTAS SUPPLY CONSOLE</p><h2 id="industry-title">Price the job before committing.</h2></div><p>Use public recipes, LP offers and station orders to compare a realistic batch. No personal account access required.</p></div><div class="industry-preview cut-panel reveal"><div class="blueprint-display"><span class="blueprint-code">RECIPES // CCP STATIC DATA</span><div class="blueprint-shape" aria-hidden="true"><span></span><span></span><span></span></div></div><div class="industry-copy"><span class="system-label">MAKE YOUR MATERIALS COUNT</span><h3>Build, convert LP or compare markets.</h3><p>Calculate materials and production time with the efficiencies you enter. Price the complete material quantities, include fees and hauling, and compare the result with station buy-order demand.</p><div class="feature-actions"><a class="primary-button" href="/industry/">Open production console</a><a class="secondary-button" href="/industry/?view=lp">Compare Commando Guri offers</a><a class="secondary-button" href="/industry/?view=trade">Compare station orders</a></div></div></div></section>
        <section id="fulcrum" class="section shell feature-layout feature-layout-reverse" aria-labelledby="fulcrum-title"><article class="fulcrum-console cut-panel reveal"><div class="console-header"><span>THE FULCRUM INITIATIVE</span><strong>PUBLIC MARKET CHECK</strong></div><div class="initiative-list"><div><span>01</span><p>Check listed stock and immediate buy-order demand.</p></div><div><span>02</span><p>Price your cargo, travel and expected losses.</p></div><div><span>03</span><p>Verify availability in EVE before hauling.</p></div></div></article><article class="feature-copy reveal"><p class="eyebrow">SUPPLY THE FULCRUM</p><h2 id="fulcrum-title">A pirate capital needs useful stock.</h2><p>Check the public station market before moving ships or materials to Zarzakh. Thin stock can suggest a gap, but it does not prove demand or promise a profitable sale.</p><p>Supply contracts and verified contribution credit require organizer-published jobs and delivery evidence. Those workflows remain separate from these market quotes.</p><div class="feature-actions"><a class="primary-button" href="/industry/?view=fulcrum">Check The Fulcrum</a><a class="secondary-button" href="/industry/?view=trade">Compare sourcing stations</a></div></article></section>

        <section
            id="lore"
            class="section shell"
            aria-labelledby="lore-title"
        >
            <div class="section-heading reveal">
                <div>
                    <p class="eyebrow">
                        RECOVERED DOSSIERS
                    </p>

                    <h2 id="lore-title">
                        The complete Guristas story
                    </h2>
                </div>

                <p>
                    Read a sixty-second briefing, follow the visual
                    timeline, or open complete sourced dossiers.
                </p>
            </div>

            <div class="lore-layout">
                <article class="lore-brief cut-panel reveal">
                    <span class="system-label">
                        60-SECOND BRIEFING
                    </span>

                    <h3>
                        They were Caldari before they became Guristas.
                    </h3>

                    <p>
                        Fatal and the Rabbit were Caldari Navy officers.
                        After betrayal, blame, and a stolen escape, they
                        built a professional pirate organization in
                        Venal. The Guristas became raiders, smugglers,
                        spies, counterfeiters, kidnappers, industrialists,
                        and black-market soldiers.
                    </p>

                    <p>
                        The State remains personal. The Federation
                        remains profitable. Every empire remains prey.
                    </p>
                </article>

                <ol class="timeline reveal">
                    <li>
                        <span>
                            01
                        </span>

                        <div>
                            <strong>
                                Octopus Squadron
                            </strong>

                            <p>
                                Fatal and Rabbit serve the Caldari Navy.
                            </p>
                        </div>
                    </li>

                    <li>
                        <span>
                            02
                        </span>

                        <div>
                            <strong>
                                The Desertion
                            </strong>

                            <p>
                                Two stolen Condors begin a criminal empire.
                            </p>
                        </div>
                    </li>

                    <li>
                        <span>
                            03
                        </span>

                        <div>
                            <strong>
                                Venal
                            </strong>

                            <p>
                                The gang becomes an organized pirate power.
                            </p>
                        </div>
                    </li>

                    <li>
                        <span>
                            04
                        </span>

                        <div>
                            <strong>
                                The Deathless
                            </strong>

                            <p>
                                Zarzakh and The Fulcrum reshape pirate warfare.
                            </p>
                        </div>
                    </li>

                    <li>
                        <span>
                            05
                        </span>

                        <div>
                            <strong>
                                Capsuleer Insurgencies
                            </strong>

                            <p>
                                The Guristas recruit immortal operators.
                            </p>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

        <section
            id="signals"
            class="section shell"
            aria-labelledby="signals-title"
        >
            <div class="section-heading reveal">
                <div>
                    <p class="eyebrow">
                        INTERCEPTED SIGNALS
                    </p>

                    <h2 id="signals-title">
                        Tune the pirate network
                    </h2>
                </div>

                <p>
                    Streams, music, comics, propaganda, community art,
                    enemy broadcasts, and encrypted transmissions.
                </p>
            </div>

            <div class="signals-grid">
                <article class="signal-scanner cut-panel reveal">
                    <div class="console-header">
                        <span>
                            BLACK RABBIT RADIO
                        </span>

                        <strong>
                            SCANNING
                        </strong>
                    </div>

                    <div class="frequency-display">
                        <span>
                            FREQUENCY
                        </span>

                        <output
                            id="frequencyOutput"
                            for="signalFrequency"
                        >
                            130.9
                        </output>
                    </div>

                    <input
                        id="signalFrequency"
                        type="range"
                        min="0"
                        max="100"
                        value="32"
                        aria-label="Scan pirate radio frequencies"
                    >

                    <div
                        id="signalMessage"
                        class="signal-message"
                        aria-live="polite"
                    >
                        Weak Guristas music carrier detected. This is a fictional discovery scanner, not live intercepted traffic.
                    </div><p class="note">Fictional signal scanner. Discover real tools and fan transmissions; this does not intercept live EVE traffic.</p><a class="secondary-button" data-signal-destination href="/signals/#radio">Open the radio relay</a>

                    <button
                        id="scanSignalButton"
                        class="secondary-button"
                        type="button"
                    >
                        Scan random frequency
                    </button>
                </article>

                <article class="media-card cut-panel reveal">
                    <span class="system-label">
                        LIVE TRANSMISSION
                    </span>

                    <h3>
                        Kniraven on Twitch
                    </h3>

                    <p class="note">Live status is shown by Twitch when you open the channel.</p>

                    <p>
                        Watch Kniraven on Twitch for broadcasts, recent videos
                        and the channel schedule.
                    </p>

                    <a
                        class="secondary-button"
                        href="https://www.twitch.tv/kniraven"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Open Twitch channel
                    </a>
                </article>

                <article class="media-card cut-panel reveal">
                    <span class="system-label">
                        ENEMY TRANSMISSION
                    </span>

                    <h3>
                        Federation Frontline Report
                    </h3>

                    <p>
                        A Gallente-aligned warzone broadcast and recurring
                        target of Guristas signal correction.
                    </p>

                    <a class="secondary-button" href="/signals/#archive">Open broadcast archives</a><div class="enemy-classification">
                        <span>
                            CLASSIFICATION
                        </span>

                        <strong>
                            NEMESIS // THEATRICAL
                        </strong>
                    </div>
                </article>

                <article class="media-card cut-panel reveal">
                    <span class="system-label">
                        PIRATE AUDIO ARCHIVE
                    </span>

                    <h3>
                        Guristas album
                    </h3>

                    <p>
                        Play the recovered Fatal Mistake and Black Rabbits
                        demos, read the companion lyrics and choose an optional
                        playback animation. No login required.
                    </p>

                    <a class="secondary-button" href="/signals/#radio">Play Black Rabbit Radio</a><div class="track-list">
                        <span>
                            Fatal Mistake
                        </span>

                        <span>
                            Black Rabbits of Venal
                        </span>


                    </div>
                </article>
            </div>
        </section>

        <section
            id="join"
            class="section shell join-section reveal"
            aria-labelledby="join-title"
        >
            <div
                class="join-background"
                aria-hidden="true"
            ></div>

            <div class="join-content">
                <p class="eyebrow">
                    ANSWER THE SIGNAL
                </p>

                <h2 id="join-title">
                    Build something the empires cannot ignore.
                </h2>

                <p>
                    Join Guristas warfare, supply The Fulcrum, submit
                    art, follow the broadcasts, support the site, or
                    apply to Cozen Corp in EVE Online.
                </p>

                <div class="join-actions"><a class="primary-button" href="/join/">Enlistment guide</a><a class="secondary-button" href="/join/#cozen">Meet Cozen Corp</a>
                    <button
                        class="primary-button"
                        type="button"
                    >
                        Search “Cozen Corp” in EVE
                    </button>

                    <a
                        class="secondary-button"
                        href="#fulcrum"
                    >
                        Fund The Fulcrum
                    </a>
                </div>
            </div>

            <aside class="cozen-card">
                <span>
                    RECOMMENDED GURISTAS CORPORATION
                </span>

                <strong>
                    COZEN CORP
                </strong>

                <p>
                    Based at The Fulcrum. Focused on pirate warfare,
                    Guristas industry, recruitment, and building a
                    useful market in Zarzakh.
                </p>
            </aside>
        </section>
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
<?php require dirname(__DIR__) . '/app/views/partials/login-modal.php'; ?>
</body>
</html>