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
    <meta name="data-source" content="CCP ESI via Guristas.net">

    <title>Venal Stream Overlay</title>

    <link
        rel="stylesheet"
        href="/assets/css/venal-overlay.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/venal-overlay.css') ?>"
    >

    <script type="importmap">
    {
        "imports": {
            "three": "https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js",
            "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.180.0/examples/jsm/"
        }
    }
    </script>

    <script
        type="module"
        src="/assets/js/venal-overlay.js?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/js/venal-overlay.js') ?>"
    ></script>
</head>
<body>
    <div id="venalOverlay" aria-label="Venal ship kills in the last hour"></div>
</body>
</html>
