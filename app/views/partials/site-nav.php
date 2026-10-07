<?php
// Shared primary navigation.
$isCommandPage = ($navActive ?? '') === 'command';

$navItems = [
    ['command', 'Command', $isCommandPage ? '#command' : '/#command'],
    ['venal', 'Venal', '/venal/'],
    ['ships', 'Ships', '/ships/'],
    ['war', 'War', '/war/guristas/'],
    ['missions', 'Missions', '/missions/'],
    ['build', 'Build', '/industry/'],
    ['lore', 'Lore', $isCommandPage ? '#lore' : '/#lore'],
    ['signals', 'Signals', '/signals/'],
];
?>
<nav id="site-navigation"
     class="site-nav"
     aria-label="Primary navigation"
     data-navigation>
    <?php foreach ($navItems as [$id, $label, $href]): ?>
        <?php $isSectionLink = strpos($href, '#') !== false; ?>
        <a href="<?= eve_e($href) ?>"
           title="<?= eve_e($isSectionLink
               ? ($isCommandPage ? 'Jump to ' . $label . ' on this page' : 'Open ' . $label . ' on the homepage')
               : 'Open ' . $label) ?>"<?= ($navActive ?? '') === $id
            ? ' aria-current="page"'
            : '' ?>><?= eve_e($label) ?></a>
    <?php endforeach; ?>

    <a class="nav-cta"
       href="/join/">Join</a>

    <?php require __DIR__ . '/account-nav.php'; ?>
</nav>