<?php
declare(strict_types=1);
$root = dirname(__DIR__, 3);
require_once $root . '/app/services/EveAuth.php';
eve_session();
$viewer = eve_current_user();
require_once $root . '/app/services/SiteTheme.php';
$themes = SiteTheme::LABELS;
$initialTheme = SiteTheme::initial();
header('Cache-Control: no-store');
function escape(string $value): string
{
    return eve_e($value);
}
?>
<!doctype html>
<html lang="en" data-theme="<?= eve_e($initialTheme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Guristas.net // Stream Messages</title>
    <?php foreach (['structure', 'themes', 'auth'] as $asset): ?>
        <link rel="stylesheet" href="/assets/css/<?= $asset ?>.css?v=<?= filemtime($root . '/public/assets/css/' . $asset . '.css') ?>">
        <script defer src="/assets/js/<?= $asset === 'structure' ? 'site' : $asset ?>.js?v=<?= filemtime($root . '/public/assets/js/' . ($asset === 'structure' ? 'site' : $asset) . '.js') ?>"></script>
    <?php endforeach; ?>
    <script>window.guristasAccount = <?= json_encode(['signedIn' => $viewer !== null, 'csrf' => eve_csrf()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <link rel="stylesheet" href="/assets/css/stream-notifications.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/stream-notifications.css') ?>">
    <link rel="stylesheet" href="/assets/css/stream-messages-admin.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/stream-messages-admin.css') ?>">
</head>
<body>
<a class="skip-link" href="#messages-admin">Skip to message editor</a>
<?php
$navActive = 'stream-messages';
$headerClass = 'site-header';
$headerSubtitle = 'Pirate Command Network';
require $root . '/app/views/partials/site-header.php';
?>
<main class="admin-shell" id="messages-admin">
    <header class="admin-header">
        <div>
            <div class="eyebrow">GURISTAS.NET // STREAM CONTROL</div>
            <h1>Message Network</h1>
        </div>
        <div class="header-actions">
            <input class="key-input" id="adminKey" type="password" autocomplete="off" placeholder="Admin key (not needed on localhost)">
            <button class="button secondary" id="reloadButton" type="button">Reload</button>
            <div class="status" id="status">Connecting…</div>
        </div>
    </header>

    <nav class="tabs" aria-label="Stream message editor sections">
        <button class="tab-button is-active" data-tab="messages" type="button">Messages</button>
        <button class="tab-button" data-tab="types" type="button">Message Types</button>
        <button class="tab-button" data-tab="settings" type="button">System Settings</button>
    </nav>

    <section class="tab-panel is-active" data-panel="messages">
        <div class="editor-grid">
            <aside class="panel">
                <div class="panel-head"><strong>Messages</strong><button class="button secondary" id="newMessage" type="button">New</button></div>
                <div class="panel-body"><div class="list" id="messageList"></div></div>
            </aside>
            <section class="panel">
                <div class="panel-head"><strong>Message Editor</strong><span class="eyebrow" id="messageIdLabel">NEW</span></div>
                <div class="panel-body">
                    <form id="messageForm" class="form-grid">
                        <input type="hidden" id="messageId">
                        <div class="field"><label for="messageType">Type</label><select id="messageType"></select></div>
                        <div class="field"><label for="messagePriority">Priority</label><select id="messagePriority"><option>ambient</option><option>info</option><option>priority</option><option>critical</option></select></div>
                        <div class="field full"><label for="messageTitle">Title</label><input id="messageTitle" maxlength="80"></div>
                        <div class="field"><label for="messageBadge">Badge</label><input id="messageBadge" maxlength="40"></div>
                        <div class="field"><label for="messageMeta">Meta line</label><input id="messageMeta" maxlength="90"></div>
                        <div class="field full"><label for="messageBody">Body</label><textarea id="messageBody" maxlength="360"></textarea></div>
                        <div class="field"><label for="messageWeight">Ambient weight</label><input id="messageWeight" type="number" min="0.05" max="20" step="0.05" value="1"></div>
                        <div class="field"><label for="messageSide">Preferred side</label><select id="messageSide"><option value="either">Either</option><option value="left">Left maps</option><option value="right">Venal / right</option></select></div>
                        <div class="field full"><label class="check-row"><input id="messageEnabled" type="checkbox" checked> Enabled for random ambient use</label></div>
                    </form>
                    <div class="actions">
                        <button class="button primary" id="saveMessage" type="button">Save message</button>
                        <button class="button secondary" id="duplicateMessage" type="button">Duplicate</button>
                        <button class="button danger" id="deleteMessage" type="button">Delete</button>
                    </div>
                </div>
            </section>
            <aside class="panel preview-wrap">
                <div class="panel-head"><strong>Live Preview</strong><span class="eyebrow">EXACT STREAM COMPONENT</span></div>
                <div class="preview-stage"><div class="preview-host" id="messagePreview"></div></div>
                <div class="panel-body preview-note">Preview updates immediately as you edit. The stream overlay uses this same notification renderer and the same type colors.</div>
            </aside>
        </div>
    </section>

    <section class="tab-panel" data-panel="types">
        <div class="editor-grid">
            <aside class="panel">
                <div class="panel-head"><strong>Message Types</strong><button class="button secondary" id="newType" type="button">New</button></div>
                <div class="panel-body"><div class="list" id="typeList"></div></div>
            </aside>
            <section class="panel">
                <div class="panel-head"><strong>Type Editor</strong><span class="eyebrow" id="typeIdLabel">NEW</span></div>
                <div class="panel-body">
                    <form id="typeForm" class="form-grid">
                        <input type="hidden" id="typeOriginalId">
                        <div class="field"><label for="typeId">Slug / ID</label><input id="typeId" maxlength="80" placeholder="fan-mail"></div>
                        <div class="field"><label for="typeName">Display name</label><input id="typeName" maxlength="64"></div>
                        <div class="field"><label for="typeAccent">Accent</label><input id="typeAccent" type="color" value="#ff6a00"></div>
                        <div class="field"><label for="typeSecondary">Text / secondary</label><input id="typeSecondary" type="color" value="#ffffff"></div>
                        <div class="field"><label for="typeBackground">Background</label><input id="typeBackground" type="color" value="#100804"></div>
                        <div class="field"><label for="typeBorder">Border</label><input id="typeBorder" type="color" value="#ff8737"></div>
                        <div class="field"><label for="typeGlow">Glow</label><input id="typeGlow" type="color" value="#ff6a00"></div>
                        <div class="field"><label for="typeMeta">Meta text</label><input id="typeMeta" type="color" value="#ffc599"></div>
                        <div class="field"><label for="typeBadge">Default badge</label><input id="typeBadge" maxlength="40"></div>
                        <div class="field"><label for="typePriority">Default priority</label><select id="typePriority"><option>ambient</option><option>info</option><option>priority</option><option>critical</option></select></div>
                        <div class="field"><label for="typeCooldown">Minimum cooldown (seconds)</label><input id="typeCooldown" type="number" min="0" max="3600" step="1" value="20"></div>
                        <div class="field"><label class="check-row"><input id="typeEnabled" type="checkbox" checked> Enabled</label></div>
                    </form>
                    <div class="actions">
                        <button class="button primary" id="saveType" type="button">Save type</button>
                        <button class="button secondary" id="duplicateType" type="button">Duplicate</button>
                        <button class="button danger" id="deleteType" type="button">Delete</button>
                    </div>
                </div>
            </section>
            <aside class="panel preview-wrap">
                <div class="panel-head"><strong>Live Type Preview</strong><span class="eyebrow">COLOR + STYLE</span></div>
                <div class="preview-stage"><div class="preview-host" id="typePreview"></div></div>
                <div class="panel-body preview-note">Every type shares one message-system layout. Colors, badge defaults, glow, border and background are the semantic differences.</div>
            </aside>
        </div>
    </section>

    <section class="tab-panel" data-panel="settings">
        <div class="panel">
            <div class="panel-head"><strong>Unified Message Scheduler</strong><span class="eyebrow">ONE QUEUE // LIVE + AMBIENT</span></div>
            <div class="panel-body">
                <div class="settings-grid" id="settingsForm"></div>
                <div class="actions"><button class="button primary" id="saveSettings" type="button">Save settings</button></div>
                <p class="help">Live map events and ambient fan mail share the same queue, placement engine, frequency rules, visual component, and fade behavior. Real events take precedence without creating a second independent popup system.</p>
            </div>
        </div>
    </section>
</main>
<script type="module" src="/assets/js/stream/messages-admin.js?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/js/stream/messages-admin.js') ?>"></script>
<?php require $root . '/app/views/partials/login-modal.php'; ?>
</body>
</html>
