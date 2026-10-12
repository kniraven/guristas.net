<?php
// Owner-only presentation switcher. This MUST NOT authorize a tool or change EVE identity.
if (!empty($factionStatus['preview']) && isset($factionViewer) && is_array($factionViewer)):
    $choices = [
        'guest'=>'Logged out', 'neutral'=>'Independent', 'unknown'=>'Unverified',
        'guristas'=>'Guristas', 'angel'=>'Angel Cartel', 'caldari'=>'Caldari',
        'gallente'=>'Gallente', 'amarr'=>'Amarr', 'minmatar'=>'Minmatar',
    ];
    $current = (string)($factionStatus['posture'] ?? 'unknown');
    $returnPath = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if (!str_starts_with($returnPath, '/') || str_starts_with($returnPath, '//') || str_contains($returnPath, "\\")) $returnPath = '/';
    $h = static fn(string $v):string => htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
?>
<style>
.guri-preview-switch{position:fixed!important;z-index:2147483000;right:clamp(12px,2vw,28px);bottom:clamp(12px,2vw,25px);color:#ffddb5;background:#1b1221f5;border:1px solid #d69b63;border-radius:8px;padding:9px 12px;box-shadow:0 8px 28px #000a;font:12px Consolas,monospace;max-width:calc(100vw - 24px);box-sizing:border-box}
.guri-preview-switch label{display:block;letter-spacing:.12em;font-size:10px;font-weight:bold;margin-bottom:5px;color:#ffc17b}
.guri-preview-switch select{font:12px Consolas,monospace;background:#2d2032;color:#fff3e8;border:1px solid #b28a70;padding:6px 8px;max-width:190px;cursor:pointer}
.guri-preview-switch select:focus-visible,.guri-preview-switch a:focus-visible{outline:2px solid #ffe1a9;outline-offset:3px}
.guri-preview-switch a{margin-left:8px;color:#f5b987;font-size:11px}
@media(max-width:480px){.guri-preview-switch{right:10px;bottom:10px;padding:7px 9px}}
</style>
<div class="guri-preview-switch" role="region" aria-label="Owner faction preview selector">
<form method="post" action="/admin/faction-preview/">
<label for="guri-preview-quick">☠ OWNER PREVIEW // NOT LIVE</label>
<input type="hidden" name="csrf" value="<?= $h(eve_csrf()) ?>">
<input type="hidden" name="return" value="<?= $h($returnPath) ?>">
<select id="guri-preview-quick" name="preview" aria-label="Simulated militia" onchange="this.form.requestSubmit()">
<?php foreach ($choices as $key=>$label): ?>
<option value="<?= $h($key) ?>" <?= $current===$key?'selected':'' ?>><?= $h($label) ?></option>
<?php endforeach; ?>
<option value="off">Stop preview / live EVE</option>
</select>
<a href="/admin/faction-preview/" title="Open full admin preview settings">Settings ↗</a>
</form>
</div>
<?php endif; ?>
