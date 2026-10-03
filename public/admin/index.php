<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/TicketService.php';

$viewer = eve_require_user();
$actor = (int) $viewer['character_id'];

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!tickets_staff($actor)) {
    http_response_code(403);
    exit('Staff access required. Contact the site owner.');
}

$themes = [
    'commando' => 'Commando Guri',
    'cryptic' => 'Cryptic Ecdysis',
    'cozen' => 'Cozen Corp',
    'kniraven' => 'Galnet',
];

$initialTheme = $_COOKIE['guristas_theme'] ?? 'cryptic';
if (!is_string($initialTheme) || !isset($themes[$initialTheme])) {
    $initialTheme = 'cryptic';
}

function escape(string $value): string
{
    return eve_e($value);
}
?>
<!doctype html>
<html lang="en" data-theme="<?= escape($initialTheme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Administration // Guristas.net</title>

    <?php foreach (['structure', 'themes', 'auth', 'tickets'] as $asset): ?>
        <link rel="stylesheet"
              href="/assets/css/<?= $asset ?>.css?v=<?= filemtime($root . '/public/assets/css/' . $asset . '.css') ?>">
    <?php endforeach; ?>

    <?php foreach (['themes', 'site', 'auth'] as $asset): ?>
        <script defer
                src="/assets/js/<?= $asset ?>.js?v=<?= filemtime($root . '/public/assets/js/' . $asset . '.js') ?>"></script>
    <?php endforeach; ?>

    <script>
        window.guristasAccount = <?= json_encode(
            ['signedIn' => true, 'csrf' => eve_csrf()],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?>;
    </script>
</head>
<body>
    <a class="skip-link" href="#admin">Skip to administration</a>
    <div class="ambient-grid" aria-hidden="true"></div>
    <div class="screen-noise" aria-hidden="true"></div>
    <div class="scanlines" aria-hidden="true"></div>

    <?php
    $navActive = 'admin';
    $headerClass = 'site-header';
    $headerSubtitle = 'Pirate Command Network';
    require $root . '/app/views/partials/site-header.php';
    ?>

    <main id="admin" class="shell ticket-page">
        <div class="ticket-heading">
            <div>
                <p class="eyebrow">GURISTAS.NET // STAFF OPERATIONS</p>
                <h1>Administration</h1>
            </div>
        </div>

        <section class="ticket-panel" aria-labelledby="tickets-heading">
            <h2 id="tickets-heading">Tickets</h2>
            <p>Organize ideas, prioritize work and track progress.</p>
            <div class="ticket-toolbar">
                <a class="button" href="/admin/tickets/?view=board">Board</a>
                <a class="button" href="/admin/tickets/?view=list">List</a>
                <a class="button" href="/admin/tickets/?new=1">New ticket</a>
            </div>
        </section>

        <?php if ($actor === tickets_owner()): ?>
            <section class="ticket-panel" aria-labelledby="staff-heading">
                <h2 id="staff-heading">Staff access</h2>
                <p>Manage who can access staff tools.</p>
                <a class="button" href="/admin/tickets/?staff=1">Manage staff</a>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>