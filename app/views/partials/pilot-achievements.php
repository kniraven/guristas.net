<?php
require_once dirname(__DIR__, 2) . '/services/GuristasDashboard.php';
$achievementDashboard = eve_guristas_dashboard($pilotData, eve_pilot_entity_catalog());
$achievementScope = $achievementScope ?? 'all';
$visibleBadges = array_values(array_filter($achievementDashboard['badges'], static function ($badge) use ($achievementScope) {
    return $achievementScope === 'all' || $achievementScope === 'overview' || $badge['section'] === $achievementScope;
}));

if ($achievementScope === 'fw') {
    $compact = []; $picked = [];
    foreach ($visibleBadges as $badge) {
        if (!isset($badge['metric'])) { $compact[] = $badge; continue; }
        if (!$badge['earned'] && !isset($picked[$badge['metric']])) { $compact[] = $badge; $picked[$badge['metric']] = true; }
    }
    foreach (['kills', 'victory_points'] as $metric) if (!isset($picked[$metric])) {
        $matching = array_values(array_filter($visibleBadges, static function ($badge) use ($metric) { return ($badge['metric'] ?? '') === $metric; }));
        if ($matching !== []) $compact[] = $matching[count($matching)-1];
    }
    $visibleBadges = $compact;
}
?>
<?php if ($achievementScope === 'overview'): ?>
<section class="dossier-recognition dossier-highlights" aria-label="Achievement highlights"><h3>Your accomplishments</h3><p><strong><?= count(array_filter($visibleBadges, static function ($badge) { return $badge['earned']; })) ?></strong> recognitions supported by current data.</p><ul><?php foreach (array_slice(array_values(array_filter($visibleBadges, static function ($badge) { return $badge['earned']; })), 0, 3) as $badge): ?><li>✦ <?= eve_e($badge['name']) ?></li><?php endforeach; ?></ul><a href="?view=achievements">Explore achievements and remaining goals →</a></section>
<?php else: ?>
<section class="dossier-recognition" aria-label="<?= eve_e($achievementScope) ?> achievements"><div class="dossier-section-title"><h3><?= $achievementScope === 'all' ? 'All achievements' : 'Section milestones' ?></h3><?php if ($achievementScope !== 'all'): ?><a href="?view=achievements">View all achievements →</a><?php endif; ?></div>
<div class="<?= $achievementScope === 'all' ? 'dossier-badges' : 'dossier-milestone-list' ?>"><?php foreach ($visibleBadges as $badge): ?><article class="dossier-badge <?= $badge['earned'] ? 'earned' : 'locked' ?>"><span class="dossier-badge-symbol" aria-hidden="true"><?= $badge['earned'] ? '✦' : '◇' ?></span><div><strong><?= eve_e($badge['name']) ?></strong><small><?= eve_e($badge['description']) ?></small><span><?= $badge['earned'] ? eve_e($badge['evidence_label'] ?? 'Earned · current evidence') : 'Not verified' ?></span>
<?php if (isset($badge['target']) && $badge['value'] !== null): $precision = in_array($badge['metric'], ['faction_standing', 'corporation_standing'], true) ? 2 : 0; ?><progress max="<?= $badge['target'] ?>" value="<?= max(0, min($badge['target'], $badge['value'])) ?>" aria-label="Progress toward <?= eve_e($badge['name']) ?>"></progress><small><?= number_format($badge['value'], $precision) ?> / <?= number_format($badge['target']) ?><?= !$badge['earned'] ? ' · ' . number_format($badge['remaining'], $precision) . ' to go' : '' ?><?= $badge['section'] === 'fw' && !$achievementDashboard['enlisted'] ? ' · Guristas enlistment also required' : '' ?></small><?php endif; ?>
</div></article><?php endforeach; ?></div>
<p class="note">Guristas.net recognition, separate from EVE’s in-game achievements. Standing and enlistment badges reflect current data. FW career totals can include other militias; victory points are neither LP nor personal insurgency contribution. Combat recognitions retain verified killmail evidence; Romance recognitions retain site story choices.</p></section>

<?php endif; ?>
