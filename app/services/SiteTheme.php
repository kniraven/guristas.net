<?php
declare(strict_types=1);

final class SiteTheme
{
    public const LABELS = [
        'commando' => 'Commando Guri',
        'cryptic' => 'Cryptic Ecdysis',
        'cozen' => 'Cozen Corp',
        'kniraven' => 'Galnet',
    ];
    public const DEFAULT_THEME = 'cryptic';

    public static function valid($theme): bool
    {
        return is_string($theme) && isset(self::LABELS[$theme]);
    }

    public static function initial(): string
    {
        $theme = $_COOKIE['guristas_theme'] ?? null;
        return self::valid($theme) ? $theme : self::DEFAULT_THEME;
    }
}
