<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/services/EveAuth.php';
eve_session();
$viewer = eve_current_user();

// Public field tools and personal dossier share the command deck.

function escape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$siteVersion = '0.3.2';

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
        'description' => 'Check stock and demand, price fees and travel, then choose a batch worth hauling.',
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

<link rel="stylesheet" href="/assets/css/site-usability.css?v=<?= filemtime(__DIR__ . '/assets/css/site-usability.css') ?>">
<link rel="stylesheet" href="/assets/css/home-command.css?v=<?= filemtime(__DIR__ . '/assets/css/home-command.css') ?>">
<script src="/assets/js/home-command.js?v=<?= filemtime(__DIR__ . '/assets/js/home-command.js') ?>" defer></script>
</head>

<body class="command-page home-refresh">
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
                    Fly with Guristas.
                    <span>
                        Make your next move count.
                    </span>
                </h1>

                <p class="hero-lede">
                    Pirate warfare, profitable plans and stories worth stealing. Explore EVE’s Guristas network. Your next move is yours.
                </p>

                <details class="rookie-brief">
                    <summary>New to EVE? Start here</summary>
                    <p>EVE is a space sandbox. The Guristas are one of its pirate factions. You can explore this network without an account or corporation membership.</p>
                    <p>New character? Finish EVE’s tutorial and try the Career Agents to learn the controls before risking a ship in pirate warfare.</p>
                    <div class="home-links"><a href="/lore/">Meet the Guristas</a><a href="/join/">Understand enlistment</a><a href="/ships/">Explore the ships</a></div>
                </details>
                <div class="directive-terminal" id="operations">
                    <div class="terminal-label"><span>CHOOSE YOUR NEXT MOVE</span><span>PUBLIC ACCESS</span></div>
                    <div class="home-directives" role="group" aria-label="Choose a directive">
                    <?php
                    $directiveSubtitles = ['raid'=>'Pirate warfare', 'trade'=>'Markets & LP', 'build'=>'Ship production', 'lore'=>'Guristas history', 'signals'=>'Music & broadcasts', 'join'=>'Fly together'];
                    foreach (['raid','trade','build','lore','signals','join'] as $key): ?>
                        <button type="button" class="operation-chip" data-operation="<?= escape($key) ?>" aria-controls="directive-briefing" aria-pressed="<?= $key === 'raid' ? 'true' : 'false' ?>">
                            <b><?= escape($operations[$key]['title']) ?></b><small><?= escape($directiveSubtitles[$key]) ?></small>
                        </button>
                    <?php endforeach; ?>
                    </div>
                    <div class="selected-operation home-briefing" id="directive-briefing" aria-live="polite" aria-atomic="true">
                        <span class="system-label">DIRECTIVE LOADED // <span data-directive-code>01</span></span>
                        <strong id="selectedOperationLabel">RAID // CORRUPT THE WARZONE</strong>
                        <p data-directive-copy>Find the Guristas campaign, inspect reported system conditions and prepare your next sortie.</p>
                        <div class="home-links"><a class="primary-button" data-directive-action href="/war/guristas/">Open War Room →</a><a data-directive-help href="/join/">How pirate warfare works</a></div>
                    </div>
                    <noscript><p>Open a public destination: <a href="/war/guristas/">War</a> · <a href="/industry/?view=trade">Trade</a> · <a href="/industry/">Build</a> · <a href="/lore/">Lore</a> · <a href="/signals/">Signals</a> · <a href="/join/">Join</a>.</p></noscript>
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
                            CLASS // CRUISER
                        </span>
                    </div>
                </div>
                <a class="ship-archive-link" href="/ships/">Inspect the Guristas fleet →</a>
            </div>
        </section>

        <section class="home-intelligence shell" aria-label="Field intelligence">
            <article class="home-panel campaign-terminal" id="war-room">
                <div class="terminal-label"><span>01 // WARZONE RELAY</span><button type="button" class="text-control" data-home-campaign-refresh>Refresh report</button></div>
                <h2 data-home-campaign-title>Contacting the campaign relay</h2>
                <p data-home-campaign-summary role="status">Fetching the latest available Guristas report.</p>
                <label for="home-system">Inspect a reported system</label>
                <select id="home-system" disabled><option>Awaiting report</option></select>
                <div class="system-briefing" data-home-system-brief role="status">Corruption and suppression will appear here.</div>
                <p class="home-source" data-home-campaign-source>Source freshness pending.</p>
                <div class="home-links"><a class="primary-button" href="/war/guristas/">Open War Room →</a><a href="/missions/">Explore missions</a></div>
            </article>
            <article class="home-panel market-terminal" id="fulcrum">
                <div class="terminal-label"><span>02 // FULCRUM EXCHANGE</span><span>ZARZAKH</span></div>
                <h2>Price your next move.</h2>
                <p>Inspect listed hull stock before you undock. Compare other hubs and the costs of getting it here.</p>
                <div class="market-controls"><label for="home-market-type">Hull<select id="home-market-type"><option value="17930">Worm</option><option value="78367">Mamba</option><option value="17715" selected>Gila</option><option value="78366">Alligator</option><option value="17918">Rattlesnake</option></select></label><button type="button" class="secondary-button" data-home-market-refresh>Check stock</button></div>
                <div class="market-result" data-home-market-result role="status">Select a hull and check The Fulcrum’s public orders.</div>
                <p class="home-source" data-home-market-source>Low stock does not establish demand. Prices exclude fees and travel.</p>
                <div class="home-links"><a class="primary-button" data-home-market-link href="/industry/?view=fulcrum&amp;type=17715">Open station market →</a><a href="/industry/?view=lp">Value your LP</a></div>
            </article>
        </section>
        <section class="section shell home-production" id="industry-preview" aria-labelledby="production-title">
            <div><p class="eyebrow">INDUSTRY // ACQUIRE. BUILD. SELL.</p><h2 id="production-title">Make the numbers work.</h2><p>Choose a Guristas hull. Open its actual recipe, calculate the materials and price your production run.</p></div>
            <div class="home-panel"><label for="home-build-hull">Production target</label><select id="home-build-hull"><option value="17930">Worm</option><option value="78367">Mamba</option><option value="17715" selected>Gila</option><option value="78366">Alligator</option><option value="17918">Rattlesnake</option></select><p data-home-build-summary role="status">Gila blueprint: open the production console to enter your job assumptions.</p><a class="primary-button" data-home-build-link href="/industry/?bp=17716">Calculate Gila production →</a></div>
        </section>

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
                        Recover the Guristas story
                    </h2>
                </div>

                <p>
                    Read the short briefing, then open sourced dossiers on the founders, Venal, Crielere and the Deathless alliance.
                </p>
            </div>

            <p class="feature-actions"><a class="primary-button" href="/lore/">Open the recovered dossiers</a><a class="secondary-button" href="/lore/?dossier=fatal">Start with Fatal</a></p><div class="lore-layout">
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
                            <strong><a href="/lore/?dossier=fatal">Octopus Squadron →</a></strong>

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
                            <strong><a href="/lore/?dossier=rabbit">The Desertion →</a></strong>

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
                            <strong><a href="/lore/?dossier=venal">Venal →</a></strong>

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
                            <strong><a href="/lore/?dossier=deathless">The Deathless →</a></strong>

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
                            <strong><a href="/lore/?dossier=insurgencies">Capsuleer Insurgencies →</a></strong>

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

            <article class="home-radio receiver reveal" aria-label="Guristas communications receiver">
                <div class="receiver-plate"><span>GURI // SIGNAL ACQUISITION UNIT</span><span>RX-06 · FIELD MODIFIED</span></div>
                <div class="receiver-main">
                    <div class="receiver-screen">
                        <div class="receiver-readout"><span>INTERCEPTED SIGNAL</span><strong data-home-radio-state>STANDBY</strong></div>
                        <div class="receiver-channel"><output id="home-frequency-output" for="home-frequency">01</output><span>CHANNEL<br>BLACK RABBIT NETWORK</span></div>
                        <h3 data-home-radio-title>Fatal Mistake</h3>
                        <p data-home-radio-description>Guristas alternate rock demo.</p>
                        <div class="receiver-meter" aria-label="Audio level"><span>OUTPUT</span><div class="radio-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div></div>
                        <p data-home-radio-status role="status">Channel selected. Press Play to listen.</p>
                        <div data-home-twitch-player></div>
                        <div class="receiver-timeline"><label for="receiver-seek">TRACK POSITION</label><output data-radio-time>0:00 / 0:00</output></div>
                        <input id="receiver-seek" type="range" min="0" max="100" value="0" step="0.1" disabled aria-label="Track position">
                    </div>
                    <div class="receiver-tuner">
                        <label for="home-frequency">CHANNEL SELECT</label>
                        <div class="receiver-dial"><span class="dial-pointer"></span><input id="home-frequency" type="range" min="0" max="3" step="1" value="0" aria-label="Tune channel" aria-valuetext="Fatal Mistake"></div>
                        <div class="receiver-step"><button type="button" data-radio-prev aria-label="Previous channel">◀</button><button type="button" data-home-radio-next aria-label="Next channel">▶</button></div>
                        <span class="receiver-stamp">GURISTAS<br>PROPERTY</span>
                    </div>
                </div>
                <div class="receiver-presets" role="group" aria-label="Station presets">
                    <button type="button" data-radio-preset="0" aria-pressed="true"><small>01 // MUSIC</small>Fatal Mistake</button>
                    <button type="button" data-radio-preset="1" aria-pressed="false"><small>02 // MUSIC</small>Black Rabbits</button>
                    <button type="button" data-radio-preset="2" aria-pressed="false"><small>03 // BROADCAST</small>Kniraven</button>
                    <button type="button" data-radio-preset="3" aria-pressed="false"><small>04 // ENEMY</small>Frontline Report</button>
                </div>
                <div class="receiver-controls">
                    <button type="button" class="receiver-play" data-home-radio-play>Play transmission</button>
                    <button type="button" class="receiver-play" data-home-load-twitch hidden>Connect to Twitch</button>
                    <label class="receiver-volume" for="receiver-volume">VOLUME <input id="receiver-volume" type="range" min="0" max="1" step="0.01" value="0.7"></label>
                    <label class="motion-control"><input type="checkbox" data-home-radio-motion checked> Audio meter</label>
                </div>
                <div class="receiver-footer"><a data-home-radio-link href="/signals/#radio">Track archive →</a><a href="/signals/">All transmissions →</a></div>
                <audio data-home-radio-player preload="none" src="/assets/audio/fatal-mistake-demo.mp3"></audio>
                <noscript><p>Use the audio player or <a href="/signals/">open transmissions</a>.</p><audio controls src="/assets/audio/fatal-mistake-demo.mp3"></audio></noscript>
            </article>
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
                    <a class="secondary-button" href="/operations/">Find a posted operation</a><a class="secondary-button" href="/community/">Art &amp; comics</a>

                    <a
                        class="secondary-button"
                        href="#fulcrum"
                    >
                        Supply The Fulcrum
                    </a>
                </div>
            </div>

            <aside class="cozen-card">
                <span>
                    KNIRAVEN’S CORPORATION
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
                    PUBLIC ALPHA // VERSION
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