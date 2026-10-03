<?php if ($viewer): ?>
    <a class="nav-profile-link"
       href="/account/"<?= ($navActive ?? '') === 'account' ? ' aria-current="page"' : '' ?>
       aria-label="Account settings for <?= eve_e($viewer['character_name']) ?>">
        <?= eve_e($viewer['character_name']) ?>
    </a>

    <?php
    require_once dirname(__DIR__, 2) . '/services/TicketService.php';
    if (tickets_staff((int) $viewer['character_id'])):
    ?>
        <a href="/admin/"<?= ($navActive ?? '') === 'admin'
            ? ' aria-current="page"'
            : '' ?>>Admin</a>
    <?php endif; ?>
<?php else: ?>
    <button class="nav-login-button"
            type="button"
            data-eve-login-open>Log in</button>
<?php endif; ?>
