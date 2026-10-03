<?php
// Shared primary navigation.
$isCommandPage = ($navActive ?? '') === 'command';

$navItems = [
    ['command', 'Command', $isCommandPage ? '#command' : '/#command'],
    ['venal', 'Venal', '/venal/'],
    ['ships', 'Ships', '/ships/'],
    ['war', 'War', $isCommandPage ? '#war-room' : '/#war-room'],
    ['build', 'Build', $isCommandPage ? '#industry-preview' : '/#industry-preview'],
    ['lore', 'Lore', $isCommandPage ? '#lore' : '/#lore'],
    ['signals', 'Signals', $isCommandPage ? '#signals' : '/#signals'],
];
?>
<nav id="site-navigation"
     class="site-nav"
     aria-label="Primary navigation"
     data-navigation>
    <?php foreach ($navItems as [$id, $label, $href]): ?>
        <a href="<?= eve_e($href) ?>"<?= ($navActive ?? '') === $id
            ? ' aria-current="page"'
            : '' ?>><?= eve_e($label) ?></a>
    <?php endforeach; ?>

    <a class="nav-cta"
       href="<?= $isCommandPage ? '#join' : '/#join' ?>">Join</a>

    <?php require __DIR__ . '/account-nav.php'; ?>
</nav>