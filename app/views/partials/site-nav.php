<?php
// Shared primary navigation. Set $navActive on each page before including this partial.
$navItems = [
    ['command', 'Command', '/'],
    ['venal', 'Venal', '/venal/'],
    ['ships', 'Ships', '/ships/'],
    ['war', 'War', '/#war-room'],
    ['build', 'Build', '/#industry-preview'],
    ['lore', 'Lore', '/#lore'],
    ['signals', 'Signals', '/#signals'],
];
?>
<nav id="site-navigation" class="site-nav" aria-label="Primary navigation" data-navigation>
    <?php foreach ($navItems as [$id, $label, $href]): ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= ($navActive ?? '') === $id ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
    <a class="nav-cta" href="/#join">Join the operation</a>
    <?php require __DIR__ . '/account-nav.php'; ?>
</nav>
