<?php
declare(strict_types=1);
header('Cache-Control: no-cache');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#00000000">
    <meta name="robots" content="noindex,nofollow">
    <meta name="data-source" content="EVE Online Frontlines War Report + CCP ESI via Guristas.net">
    <title>Guristas Corruption Stream Overlay</title>
    <link rel="stylesheet" href="/assets/css/guristas-corruption-overlay.css?v=<?= filemtime(dirname(__DIR__, 3) . '/assets/css/guristas-corruption-overlay.css') ?>">
    <script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.180.0/examples/jsm/"}}</script>
    <script type="module" src="/assets/js/guristas-corruption-overlay.js?v=<?= filemtime(dirname(__DIR__, 3) . '/assets/js/guristas-corruption-overlay.js') ?>"></script>
</head>
<body><div id="guristasCorruptionOverlay" aria-label="Current Guristas corruption stream overlay"></div></body>
</html>
