<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Guristas.net // Stream Command Overlay</title>
    <link rel="stylesheet" href="/assets/css/stream-notifications.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/stream-notifications.css') ?>">
    <link rel="stylesheet" href="/assets/css/stream-overlay.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/stream-overlay.css') ?>">
</head>
<body>
<div class="viewport">
    <main class="stream-overlay" id="streamOverlay" aria-label="Guristas stream intelligence overlay">
        <div class="overlay-grid" aria-hidden="true"></div>
        <div class="edge-glow" aria-hidden="true"></div>

        <canvas id="streamWebgl" width="1920" height="1080" aria-hidden="true"></canvas>
        <div class="map-label-layer" id="mapLabelLayer" aria-hidden="true"></div>

        <section class="zarzakh-zone" aria-label="Zarzakh status">
            <div class="zarzakh-mark" id="zarzakhMark">
                <div class="zarzakh-core" id="zarzakhCore"></div>
                <div class="zarzakh-copy">
                    <strong>ZARZAKH</strong>
                    <span>THE FULCRUM</span>
                    <span class="zarzakh-kills" id="zarzakhKills"></span>
                </div>
            </div>
        </section>

        <div class="notification-layer" id="notificationLayer" aria-live="polite"></div>

        <div class="overlay-scan" aria-hidden="true"></div>
        <div class="tech-lines" aria-hidden="true">
            <div class="tech-line one"></div>
            <div class="tech-line two"></div>
            <div class="tech-line three"></div>
        </div>
        <div class="vertical-data" aria-hidden="true"></div>
        <div class="frame-layer" aria-hidden="true">
            <div class="top-bar"></div>
            <div class="bottom-bar"></div>
            <div class="corner tl"></div>
            <div class="corner tr"></div>
            <div class="corner bl"></div>
            <div class="corner br"></div>
        </div>
        <div class="stream-error" id="streamError"></div>
    </main>
</div>
<script type="module" src="/assets/js/stream/overlay.js?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/js/stream/overlay.js') ?>"></script>
</body>
</html>
