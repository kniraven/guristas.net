<?php
$permissionGroups = require __DIR__ . '/permission-reasons.php';
$enabledScopes = require dirname(__DIR__, 3) . '/config/esi-scopes.php';
$enabledScopes = array_fill_keys($enabledScopes, true);
?>
<dialog class="eve-login-dialog" id="eveLoginDialog" aria-labelledby="eveLoginTitle">
    <button class="eve-login-close" type="button" data-eve-login-close aria-label="Close login">×</button>
    <p class="eyebrow">GURISTAS.NET // ACCOUNT ACCESS</p>
    <h2 id="eveLoginTitle">Log in with EVE Online</h2>
    <p>Choose a character through CCP. Your EVE password stays with CCP. We request permission to access the character data described below so we can build your profile and planned site tools.</p>
    <details class="eve-permission-details">
        <summary>Why we request EVE access (<?= count($enabledScopes) ?> permissions)</summary>
        <p class="eve-permission-intro">These are planned features. They are not all available yet. Granting access does not download every category of data at login. Some corporation tools also require in-game roles. These permissions include private mail, location, finances, and the ability to take selected in-game actions. CCP will show the official permission list before you approve.</p>
        <?php foreach ($permissionGroups as $app => $permissions): ?>
            <?php $shown = array_intersect_key($permissions, $enabledScopes); if (!$shown) continue; ?>
            <section class="eve-permission-app" aria-label="<?= htmlspecialchars($app, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <h3><?= htmlspecialchars($app, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                <ul>
                    <?php foreach ($shown as $scope => $reason): ?>
                        <li><code><?= htmlspecialchars($scope, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code><span><?= htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </details>
    <a href="/auth/start.php" class="eve-sso-button" aria-label="Continue to EVE Online sign in">
        <img src="https://web.ccpgamescdn.com/eveonlineassets/developers/eve-sso-login-black-large.png" alt="Log in with EVE Online" width="240" height="48">
    </a>
    <p class="eve-login-note">You can review the requested access before you approve it with CCP.</p>
</dialog>
