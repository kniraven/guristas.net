<?php
// Rendered for verified hostile militia credentials or explicit owner visual preview.
$csrf = eve_csrf();
$isGallente = ($factionStatus['posture'] ?? '') === 'gallente';
$preview = !empty($factionStatus['preview']);
?>
<style>
.guri-lock-screen{min-height:75vh;display:grid;place-items:center;padding:3rem 1rem;color:#efe4ee;background:repeating-linear-gradient(0deg,rgba(248,99,58,.035) 0,rgba(248,99,58,.035) 1px,transparent 2px,transparent 6px),#120c14;font-family:Consolas,monospace}
.guri-lock-card{width:min(100%,680px);border:2px solid #e28b65;box-shadow:0 0 0 7px #241420,0 0 38px #a53b2b66;padding:clamp(1.4rem,4vw,3rem);background:#21101d}
.guri-lock-brow{color:#ffb474;letter-spacing:.14em;font-size:.75rem;font-weight:700}
.guri-lock-card h1{color:#ffe0c2;font-size:clamp(1.7rem,5vw,3rem);line-height:1.05;margin:1rem 0}
.guri-lock-card p{line-height:1.65;color:#e5ccd7}.guri-lock-card form{margin-top:1.8rem}.guri-lock-card button{cursor:pointer;border:1px solid #ffa65c;color:#ffe0b3;background:#3b2432;padding:.85rem 1.2rem;font:italic bold 1rem Consolas,monospace}
.guri-lock-card button:hover,.guri-lock-card button:focus-visible{background:#714034;outline:2px solid #ffd198;outline-offset:3px}
</style>
<main class="guri-lock-screen" id="command" role="main"><section class="guri-lock-card" aria-labelledby="guri-lock-title">
<p class="guri-lock-brow">☠ GURISTAS FIREWALL // HOSTILE CREDENTIALS DETECTED</p>
<h1 id="guri-lock-title"><?= $isGallente ? 'FEDERATION LOYALIST DETECTED' : 'STATE LOYALIST DETECTED' ?></h1>
<p><?= $isGallente ? 'Gallente militia credentials detected. The Federation is not invited into our command network. Enjoy your freedom somewhere else.' : 'Caldari State militia credentials detected. The Guristas command network has no intention of handing its operations to the State’s obedient little errand-runner.' ?></p>
<p><?= $preview ? 'ADMIN PREVIEW / Simulation only. Actual authorization is unchanged.' : 'Verified faction-warfare identity.' ?></p>
<p>Security status: <strong>ACCESS DENIED</strong>. Your next move is to return without that uniform.</p>
<form method="post" action="/auth/logout.php"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>"><button type="submit">☞ DISCONNECT / LOG OUT</button></form>
<?php if ($preview): ?><p><a style="color:#ffbc85" href="/admin/faction-preview/">☞ EXIT PREVIEW / ADMIN CONSOLE</a></p><?php endif; ?>
</section></main>
<?php if ($preview) require __DIR__ . '/faction-preview-switch.php'; ?>
</body></html>
