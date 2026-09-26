<?php if ($viewer): ?>
    <a class="nav-profile-link" href="/account/" aria-label="Account settings for <?= eve_e($viewer['character_name']) ?>"><?= eve_e($viewer['character_name']) ?></a>
<?php else: ?>
    <button class="nav-login-button" type="button" data-eve-login-open>Log in</button>
<?php endif; ?>
