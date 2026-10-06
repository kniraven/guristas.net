<?php
declare(strict_types=1);
/** Guristas entities use Criminal Connections for positive raw standings.
 * Diplomacy applies to negative standings; exact zero receives no bonus.
 * Input is active (not trained) levels. Never round before threshold checks.
 */
function eve_guristas_effective(float $raw, array $skills): array
{
    $id = $raw < 0 ? 3357 : ($raw > 0 ? 3361 : null);
    $level = $id === null ? 0 : ($skills[$id] ?? 0);
    if (!is_int($level) || $level < 0 || $level > 5 || !is_finite($raw) || abs($raw) > 10) {
        throw new InvalidArgumentException('Invalid standings calculation input.');
    }
    return ['raw' => $raw, 'effective' => $raw + (10 - $raw) * .04 * $level,
        'skill' => $id === 3357 ? 'Diplomacy' : ($id === 3361 ? 'Criminal Connections' : 'No bonus at zero'), 'level' => $level];
}
