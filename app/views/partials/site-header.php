<?php
// Set $navActive, $headerClass and $headerSubtitle before including this partial.
// Uses the page theme choices and initial theme, plus the signed-in viewer.
?>
    <header class="<?= eve_e($headerClass) ?>">
        <div class="shell header-inner">
            <a
                class="brand"
                href="/"
                aria-label="Guristas.net command deck"
            >
                <span
                    class="brand-mark"
                    aria-hidden="true"
                >
                    <svg viewBox="0 0 64 64">
                        <path
                            d="M17 6 29 24l-8 5-11-10L17 6Z"
                        ></path>

                        <path
                            d="M47 6 35 24l8 5 11-10L47 6Z"
                        ></path>

                        <path
                            d="M15 28c4-6 10-9 17-9s13 3 17 9l-3 19-8 10H26l-8-10-3-19Z"
                        ></path>

                        <path
                            class="brand-mark-cut"
                            d="m21 34 9 2-3 8-8-4 2-6Zm22 0-9 2 3 8 8-4-2-6ZM29 49h6l-3 5-3-5Z"
                        ></path>
                    </svg>
                </span>

                <span class="brand-copy">
                    <strong>
                        GURISTAS.NET
                    </strong>

                    <span>
                        <?= eve_e($headerSubtitle) ?>
                    </span>
                </span>
            </a>

            <div
                class="theme-switcher"
                role="group"
                aria-label="Select Guristas.net visual theme"
                data-theme-switcher
            >
                <?php foreach ($themes as $themeKey => $themeName): ?>
                    <button
                        class="theme-option"
                        type="button"
                        data-theme-option="<?= escape($themeKey) ?>"
                        aria-pressed="<?= $themeKey === $initialTheme ? 'true' : 'false' ?>"
                        title="<?= escape($themeName) ?>"
                    >
                        <span
                            class="theme-swatch"
                            data-theme-preview="<?= escape($themeKey) ?>"
                            aria-hidden="true"
                        ></span>

                        <span class="theme-option-name">
                            <?= escape($themeName) ?>
                        </span>
                    </button>
                <?php endforeach; ?>

                <span
                    id="themeAnnouncement"
                    class="sr-only"
                    aria-live="polite"
                ></span>
            </div>

            <button
                class="menu-button"
                type="button"
                aria-expanded="false"
                aria-controls="site-navigation"
                data-menu-button
            >
                <span class="menu-button-label">
                    Menu
                </span>

                <span
                    class="menu-lines"
                    aria-hidden="true"
                >
                    <i></i>
                    <i></i>
                    <i></i>
                </span>
            </button>

            <?php require __DIR__ . '/site-nav.php'; ?>
        </div>
    </header>
