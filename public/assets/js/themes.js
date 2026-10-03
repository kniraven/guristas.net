"use strict";

/*
|--------------------------------------------------------------------------
| GURISTAS.NET — THEME SYSTEM
|--------------------------------------------------------------------------
|
| This file controls:
| - Theme selection
| - Theme persistence
| - Theme-specific loading-screen text
| - Broadcast-takeover transitions
| - Theme-specific particles
| - Theme-specific glitch effects
| - Ambient visual effects
|
| Universal site behavior remains in site.js.
|
*/

/* =========================================================
   THEME DEFINITIONS
   ========================================================= */

const themeProfiles = {
    commando: {
        label: "Commando Guri",

        code:
            "COMMANDO GURI FIELD NETWORK",

        themeColor:
            "#17140e",

        shipImage:
            "assets/images/ships/gila.png",

        bootLines: [
            "Locating Commando Guri field relay",
            "Injecting warzone command protocols",
            "Synchronizing tactical camouflage feed",
            "Broadcast secured by Guristas operators"
        ],

        transitionLines: [
            "Field channel intercepted",
            "Tactical overlays deployed",
            "Commando Guri command authority established"
        ],

        ambientMinimum:
            4800,

        ambientMaximum:
            8500,

        glitchMinimum:
            9000,

        glitchMaximum:
            15000
    },

    cryptic: {
        label:
            "Cryptic Ecdysis",

        code:
            "CRYPTIC ECDYSIS DAMAGE NETWORK",

        themeColor:
            "#080503",

        shipImage:
            "assets/images/ships/Gila_Cryptic_Ecdysis.png",

        bootLines: [
            "Recovering damaged transmission fragments",
            "Reconstructing breached hull telemetry",
            "Injecting unstable holographic handshake",
            "Broadcast identity forcibly overwritten"
        ],

        transitionLines: [
            "Hull breach detected",
            "Damaged signal reconstruction active",
            "Cryptic Ecdysis control established"
        ],

        ambientMinimum:
            2800,

        ambientMaximum:
            5400,

        glitchMinimum:
            6000,

        glitchMaximum:
            11000
    },

    cozen: {
        label:
            "Cozen Corp",

        code:
            "COZEN CORP HYBRID BROADCAST",

        themeColor:
            "#0d0a0f",

        shipImage:
            "assets/images/ships/gila_cozen_corp.png",

        bootLines: [
            "Contacting Cozen Corp broker relay",
            "Synchronizing black-market routing tables",
            "Combining stolen military and civilian channels",
            "Broadcast acquisition contract fulfilled"
        ],

        transitionLines: [
            "Unauthorized merger initiated",
            "Pirate and corporate channels combined",
            "Cozen Corp network control established"
        ],

        ambientMinimum:
            3600,

        ambientMaximum:
            6800,

        glitchMinimum:
            7200,

        glitchMaximum:
            12500
    },

    kniraven: {
        label:
            "Galnet",

        code:
            "GALNET PUBLIC INFORMATION NETWORK",

        themeColor:
            "#f6f3fa",

        shipImage:
            "assets/images/ships/gila_galnet.png",

        bootLines: [
            "Establishing Galnet public uplink",
            "Synchronizing New Eden relay geometry",
            "Indexing public broadcast channels",
            "Galnet interface brought online"
        ],

        transitionLines: [
            "Public relay handshake accepted",
            "Galnet visual layer synchronized",
            "Galnet network established"
        ],

        ambientMinimum:
            3200,

        ambientMaximum:
            5900,

        glitchMinimum:
            7800,

        glitchMaximum:
            13500
    }
};

const validThemes =
    Object.keys(themeProfiles);

const reducedMotion =
    window.matchMedia(
        "(prefers-reduced-motion: reduce)"
    ).matches;

/* =========================================================
   RUNTIME STATE
   ========================================================= */

let activeTheme =
    getInitialTheme();

let effectLayer =
    null;

let transitionOverlay =
    null;

let ambientTimer =
    null;

let glitchTimer =
    null;

let themeChanging =
    false;

/* =========================================================
   SAFE STORAGE
   ========================================================= */

function safeStorageGet(key) {
    try {
        return window.localStorage.getItem(
            key
        );
    } catch (error) {
        console.warn(
            "Unable to read the saved theme.",
            error
        );

        return null;
    }
}

function safeStorageSet(
    key,
    value
) {
    try {
        window.localStorage.setItem(
            key,
            value
        );
    } catch (error) {
        console.warn(
            "Unable to save the theme preference.",
            error
        );
    }
}

function getInitialTheme() {
    const documentTheme = document.documentElement.dataset.theme;

    if (validThemes.includes(documentTheme)) {
        safeStorageSet("guristas.theme", documentTheme);
        return documentTheme;
    }

    return "cryptic";
}

function saveTheme(themeKey) {
    safeStorageSet(
        "guristas.theme",
        themeKey
    );

    document.cookie = [
        `guristas_theme=${encodeURIComponent(themeKey)}`,
        "path=/",
        "max-age=31536000",
        "SameSite=Lax"
    ].join("; ");
}

/* =========================================================
   GENERAL UTILITIES
   ========================================================= */

function randomBetween(
    minimum,
    maximum
) {
    return (
        Math.random() *
        (maximum - minimum) +
        minimum
    );
}

function randomInteger(
    minimum,
    maximum
) {
    return Math.floor(
        randomBetween(
            minimum,
            maximum + 1
        )
    );
}

function isElementVisible(element) {
    if (!element) {
        return false;
    }

    const rectangle =
        element.getBoundingClientRect();

    return (
        rectangle.bottom > 0 &&
        rectangle.top <
            window.innerHeight &&
        rectangle.right > 0 &&
        rectangle.left <
            window.innerWidth
    );
}

function restartClassAnimation(
    element,
    className
) {
    if (!element) {
        return;
    }

    element.classList.remove(
        className
    );

    void element.offsetWidth;

    element.classList.add(
        className
    );
}

function getProfile() {
    return themeProfiles[
        activeTheme
    ];
}

function updateThemeShip(
    themeKey
) {
    const heroShip =
        document.querySelector(
            "[data-theme-ship]"
        );

    const profile =
        themeProfiles[themeKey];

    if (
        !heroShip ||
        !profile?.shipImage
    ) {
        return;
    }

    const nextSource =
        profile.shipImage;

    if (
        heroShip.getAttribute("src") !==
        nextSource
    ) {
        heroShip.setAttribute(
            "src",
            nextSource
        );
    }
}

/* =========================================================
   APPLY THEME
   ========================================================= */

function applyThemeImmediately(
    themeKey
) {
    const resolvedTheme =
        validThemes.includes(themeKey)
            ? themeKey
            : "cryptic";

    activeTheme =
        resolvedTheme;

    document.documentElement.dataset.theme =
        resolvedTheme;

    updateThemeShip(
        resolvedTheme
    );

    const themeColorMeta =
        document.querySelector(
            'meta[name="theme-color"]'
        );

    if (themeColorMeta) {
        themeColorMeta.setAttribute(
            "content",
            themeProfiles[
                resolvedTheme
            ].themeColor
        );
    }
}

/*
 * Apply the saved theme as soon as this deferred script runs.
 */
applyThemeImmediately(
    activeTheme
);

/* =========================================================
   GENERATED EFFECT CONTAINERS
   ========================================================= */

function ensureEffectLayer() {
    if (
        effectLayer ||
        reducedMotion
    ) {
        return effectLayer;
    }

    effectLayer =
        document.createElement("div");

    effectLayer.className =
        "theme-fx-layer";

    effectLayer.setAttribute(
        "aria-hidden",
        "true"
    );

    document.body.appendChild(
        effectLayer
    );

    return effectLayer;
}

function createTransitionOverlay() {
    if (transitionOverlay) {
        return transitionOverlay;
    }

    transitionOverlay =
        document.createElement("div");

    transitionOverlay.className =
        "theme-transition";

    transitionOverlay.setAttribute(
        "aria-hidden",
        "true"
    );

    transitionOverlay.innerHTML = `
        <div class="theme-transition-panel">
            <span class="theme-transition-code"></span>

            <h2></h2>

            <p class="theme-transition-status"></p>
        </div>
    `;

    document.body.appendChild(
        transitionOverlay
    );

    return transitionOverlay;
}

/* =========================================================
   GLITCH TEXT
   ========================================================= */

function initializeGlitchText() {
    const targets = [
        document.querySelector(
            ".boot-terminal h1"
        ),

        document.querySelector(
            ".brand-copy strong"
        ),

        document.querySelector(
            ".hero h1"
        ),

        ...document.querySelectorAll(
            ".section-heading h2"
        ),

        ...document.querySelectorAll(
            ".feature-copy h2"
        ),

        ...document.querySelectorAll(
            ".join-content h2"
        )
    ].filter(Boolean);

    targets.forEach(element => {
        const text =
            element.textContent
                .replace(/\s+/g, " ")
                .trim();

        element.classList.add(
            "glitch-text"
        );

        element.dataset.glitch =
            text;
    });
}

function glitchText(element) {
    if (
        !element ||
        reducedMotion
    ) {
        return;
    }

    restartClassAnimation(
        element,
        "is-text-glitching"
    );

    window.setTimeout(
        () => {
            element.classList.remove(
                "is-text-glitching"
            );
        },
        470
    );
}

function glitchVisibleHeading() {
    const headings = [
        ...document.querySelectorAll(
            ".glitch-text"
        )
    ].filter(isElementVisible);

    if (headings.length === 0) {
        return;
    }

    const randomHeading =
        headings[
            Math.floor(
                Math.random() *
                headings.length
            )
        ];

    glitchText(
        randomHeading
    );
}

/* =========================================================
   THEME-SPECIFIC BOOT TEXT
   ========================================================= */

function updateBootText() {
    const profile =
        getProfile();

    const bootLines = [
        ...document.querySelectorAll(
            ".boot-sequence span"
        )
    ];

    bootLines.forEach(
        (line, index) => {
            const replacement =
                profile.bootLines[index];

            if (replacement) {
                line.textContent =
                    replacement;
            }
        }
    );
}

/* =========================================================
   THEME SELECTOR BUTTONS
   ========================================================= */

function updateThemeButtons() {
    const buttons = [
        ...document.querySelectorAll(
            "[data-theme-option]"
        )
    ];

    buttons.forEach(button => {
        const selected =
            button.dataset.themeOption ===
            activeTheme;

        button.setAttribute(
            "aria-pressed",
            selected
                ? "true"
                : "false"
        );
    });

    const announcement =
        document.querySelector(
            "#themeAnnouncement"
        );

    if (announcement) {
        announcement.textContent =
            `${getProfile().label} theme selected.`;
    }
}

function initializeThemeControls() {
    const buttons = [
        ...document.querySelectorAll(
            "[data-theme-option]"
        )
    ];

    buttons.forEach(button => {
        button.addEventListener(
            "click",
            () => {
                const themeKey =
                    button.dataset.themeOption;

                changeTheme(
                    themeKey
                );
            }
        );
    });

    updateThemeButtons();
}

/* =========================================================
   BASIC PARTICLES
   ========================================================= */

function spawnParticle({
    left =
        randomBetween(
            0,
            window.innerWidth
        ),

    top =
        window.innerHeight + 20,

    size =
        randomBetween(4, 14),

    duration =
        randomBetween(2.5, 5),

    travelX =
        randomBetween(-60, 60),

    travelY =
        -randomBetween(140, 300)
} = {}) {
    if (reducedMotion) {
        return;
    }

    const layer =
        ensureEffectLayer();

    if (!layer) {
        return;
    }

    const particle =
        document.createElement(
            "span"
        );

    particle.className =
        "fx-particle";

    particle.style.left =
        `${left}px`;

    particle.style.top =
        `${top}px`;

    particle.style.width =
        `${size}px`;

    particle.style.height =
        `${size}px`;

    particle.style.setProperty(
        "--travel-x",
        `${travelX}px`
    );

    particle.style.setProperty(
        "--travel-y",
        `${travelY}px`
    );

    particle.style.animation =
        `particle-rise ${duration}s linear forwards`;

    layer.appendChild(
        particle
    );

    window.setTimeout(
        () => {
            particle.remove();
        },
        duration * 1000 + 150
    );
}

function spawnShard({
    centerX =
        window.innerWidth * 0.5,

    centerY =
        window.innerHeight * 0.4
} = {}) {
    if (reducedMotion) {
        return;
    }

    const layer =
        ensureEffectLayer();

    if (!layer) {
        return;
    }

    const shard =
        document.createElement(
            "span"
        );

    const width =
        randomBetween(22, 76);

    const height =
        randomBetween(2, 8);

    const duration =
        randomBetween(0.8, 1.9);

    shard.className =
        "fx-shard";

    shard.style.left =
        `${
            centerX +
            randomBetween(-130, 130)
        }px`;

    shard.style.top =
        `${
            centerY +
            randomBetween(-100, 100)
        }px`;

    shard.style.width =
        `${width}px`;

    shard.style.height =
        `${height}px`;

    shard.style.setProperty(
        "--travel-x",
        `${randomBetween(-180, 180)}px`
    );

    shard.style.setProperty(
        "--travel-y",
        `${randomBetween(-30, 30)}px`
    );

    shard.style.animation =
        `shard-travel ${duration}s linear forwards`;

    layer.appendChild(
        shard
    );

    window.setTimeout(
        () => {
            shard.remove();
        },
        duration * 1000 + 150
    );
}

function spawnNumber() {
    if (reducedMotion) {
        return;
    }

    const layer =
        ensureEffectLayer();

    if (!layer) {
        return;
    }

    const number =
        document.createElement(
            "span"
        );

    number.className =
        "fx-number";

    number.textContent =
        Math.random() > 0.5
            ? String(
                randomInteger(
                    100101,
                    999999
                )
            )
            : randomInteger(
                1000,
                9999
            ).toString(2);

    number.style.left =
        `${randomBetween(4, 94)}%`;

    number.style.top =
        `${randomBetween(15, 90)}%`;

    number.style.fontSize =
        `${randomBetween(0.55, 0.9)}rem`;

    const duration =
        randomBetween(2.2, 4.2);

    number.style.animation =
        `number-drift ${duration}s ease-out forwards`;

    layer.appendChild(
        number
    );

    window.setTimeout(
        () => {
            number.remove();
        },
        duration * 1000 + 150
    );
}

function spawnPortalRing({
    centerX =
        window.innerWidth * 0.5,

    centerY =
        window.innerHeight * 0.5
} = {}) {
    if (reducedMotion) {
        return;
    }

    const layer =
        ensureEffectLayer();

    if (!layer) {
        return;
    }

    const ring =
        document.createElement(
            "span"
        );

    const size =
        randomBetween(70, 220);

    const duration =
        randomBetween(1.8, 3.2);

    ring.className =
        "fx-ring";

    ring.style.width =
        `${size}px`;

    ring.style.height =
        `${size}px`;

    ring.style.left =
        `${centerX - size / 2}px`;

    ring.style.top =
        `${centerY - size / 2}px`;

    ring.style.animation =
        `portal-expand ${duration}s ease-out forwards`;

    layer.appendChild(
        ring
    );

    window.setTimeout(
        () => {
            ring.remove();
        },
        duration * 1000 + 150
    );
}

function spawnMilitaryScan() {
    if (reducedMotion) {
        return;
    }

    const layer =
        ensureEffectLayer();

    if (!layer) {
        return;
    }

    const scan =
        document.createElement(
            "span"
        );

    scan.className =
        "fx-scan-bar";

    scan.style.top =
        `${randomBetween(10, 90)}%`;

    scan.style.height =
        `${randomBetween(2, 12)}px`;

    scan.style.animation =
        "military-scan 1.3s ease-out forwards";

    layer.appendChild(
        scan
    );

    window.setTimeout(
        () => {
            scan.remove();
        },
        1500
    );
}

function spawnGlitchLines(
    intensity = "normal"
) {
    if (reducedMotion) {
        return;
    }

    const layer =
        ensureEffectLayer();

    if (!layer) {
        return;
    }

    const count =
        intensity === "hard"
            ? 8
            : 4;

    for (
        let index = 0;
        index < count;
        index += 1
    ) {
        const line =
            document.createElement(
                "span"
            );

        line.className =
            "fx-line";

        if (Math.random() > 0.55) {
            line.classList.add(
                "is-alternate"
            );
        }

        line.style.top =
            `${randomBetween(2, 97)}%`;

        line.style.height =
            `${randomBetween(1, 9)}px`;

        line.style.animationDelay =
            `${randomBetween(0, 100)}ms`;

        layer.appendChild(
            line
        );

        window.setTimeout(
            () => {
                line.remove();
            },
            650
        );
    }
}

/* =========================================================
   TARGET POSITION
   ========================================================= */

function getElementCenter(element) {
    if (!element) {
        return {
            x:
                window.innerWidth *
                0.5,

            y:
                window.innerHeight *
                0.45
        };
    }

    const rectangle =
        element.getBoundingClientRect();

    return {
        x:
            rectangle.left +
            rectangle.width / 2,

        y:
            rectangle.top +
            rectangle.height / 2
    };
}

/* =========================================================
   CRYPTIC ECDYSIS EFFECTS
   ========================================================= */

function spawnCrypticBurst(
    target,
    intensity
) {
    const center =
        getElementCenter(target);

    const particleCount =
        intensity === "hard"
            ? 18
            : 9;

    const shardCount =
        intensity === "hard"
            ? 9
            : 4;

    for (
        let index = 0;
        index < particleCount;
        index += 1
    ) {
        spawnParticle({
            left:
                center.x +
                randomBetween(
                    -180,
                    180
                ),

            top:
                center.y +
                randomBetween(
                    30,
                    150
                ),

            size:
                randomBetween(
                    4,
                    17
                ),

            travelX:
                randomBetween(
                    -90,
                    90
                ),

            travelY:
                -randomBetween(
                    120,
                    310
                )
        });
    }

    for (
        let index = 0;
        index < shardCount;
        index += 1
    ) {
        spawnShard({
            centerX:
                center.x,

            centerY:
                center.y
        });
    }
}

/* =========================================================
   COMMANDO GURI EFFECTS
   ========================================================= */

function spawnCommandoBurst(
    target,
    intensity
) {
    const center =
        getElementCenter(target);

    const particleCount =
        intensity === "hard"
            ? 12
            : 6;

    for (
        let index = 0;
        index < particleCount;
        index += 1
    ) {
        spawnParticle({
            left:
                center.x +
                randomBetween(
                    -220,
                    220
                ),

            top:
                center.y +
                randomBetween(
                    -80,
                    120
                ),

            size:
                randomBetween(
                    2,
                    8
                ),

            duration:
                randomBetween(
                    1.8,
                    3.6
                ),

            travelX:
                randomBetween(
                    -40,
                    40
                ),

            travelY:
                -randomBetween(
                    50,
                    140
                )
        });
    }

    spawnMilitaryScan();

    if (intensity === "hard") {
        window.setTimeout(
            spawnMilitaryScan,
            150
        );
    }
}

/* =========================================================
   COZEN EFFECTS
   ========================================================= */

function spawnCozenBurst(
    target,
    intensity
) {
    const center =
        getElementCenter(target);

    const particleCount =
        intensity === "hard"
            ? 14
            : 7;

    const shardCount =
        intensity === "hard"
            ? 7
            : 3;

    for (
        let index = 0;
        index < particleCount;
        index += 1
    ) {
        spawnParticle({
            left:
                center.x +
                randomBetween(
                    -190,
                    190
                ),

            top:
                center.y +
                randomBetween(
                    10,
                    140
                ),

            size:
                randomBetween(
                    4,
                    13
                ),

            travelX:
                randomBetween(
                    -110,
                    110
                ),

            travelY:
                -randomBetween(
                    100,
                    260
                )
        });
    }

    for (
        let index = 0;
        index < shardCount;
        index += 1
    ) {
        spawnShard({
            centerX:
                center.x,

            centerY:
                center.y
        });
    }

    if (Math.random() > 0.4) {
        spawnNumber();
    }
}

/* =========================================================
   KNIRAVEN EFFECTS
   ========================================================= */

function spawnKniravenBurst(
    target,
    intensity
) {
    const center =
        getElementCenter(target);

    const ringCount =
        intensity === "hard"
            ? 4
            : 2;

    const numberCount =
        intensity === "hard"
            ? 9
            : 4;

    const shardCount =
        intensity === "hard"
            ? 5
            : 2;

    for (
        let index = 0;
        index < ringCount;
        index += 1
    ) {
        window.setTimeout(
            () => {
                spawnPortalRing({
                    centerX:
                        center.x +
                        randomBetween(
                            -70,
                            70
                        ),

                    centerY:
                        center.y +
                        randomBetween(
                            -50,
                            50
                        )
                });
            },
            index * 130
        );
    }

    for (
        let index = 0;
        index < numberCount;
        index += 1
    ) {
        window.setTimeout(
            spawnNumber,
            index * 55
        );
    }

    for (
        let index = 0;
        index < shardCount;
        index += 1
    ) {
        spawnShard({
            centerX:
                center.x,

            centerY:
                center.y
        });
    }
}

/* =========================================================
   SELECT ACTIVE THEME EFFECT
   ========================================================= */

function spawnThemeBurst(
    target = null,
    intensity = "normal"
) {
    switch (activeTheme) {
        case "commando":
            spawnCommandoBurst(
                target,
                intensity
            );

            break;

        case "cozen":
            spawnCozenBurst(
                target,
                intensity
            );

            break;

        case "kniraven":
            spawnKniravenBurst(
                target,
                intensity
            );

            break;

        case "cryptic":
        default:
            spawnCrypticBurst(
                target,
                intensity
            );

            break;
    }
}

/* =========================================================
   GLOBAL GLITCH
   ========================================================= */

function triggerGlitch({
    intensity = "normal",
    target = null,
    includeText = true
} = {}) {
    if (reducedMotion) {
        return;
    }

    const bodyClass =
        intensity === "hard"
            ? "is-hard-glitching"
            : "is-glitching";

    document.body.classList.remove(
        "is-glitching",
        "is-hard-glitching"
    );

    void document.body.offsetWidth;

    document.body.classList.add(
        bodyClass
    );

    spawnGlitchLines(
        intensity
    );

    spawnThemeBurst(
        target,
        intensity
    );

    if (includeText) {
        glitchVisibleHeading();
    }

    if (target) {
        restartClassAnimation(
            target,
            "is-panel-glitching"
        );

        window.setTimeout(
            () => {
                target.classList.remove(
                    "is-panel-glitching"
                );
            },
            480
        );
    }

    window.setTimeout(
        () => {
            document.body.classList.remove(
                bodyClass
            );
        },
        intensity === "hard"
            ? 720
            : 450
    );
}

function pulseTarget(target) {
    if (
        !target ||
        reducedMotion
    ) {
        return;
    }

    restartClassAnimation(
        target,
        "is-theme-pulsing"
    );

    window.setTimeout(
        () => {
            target.classList.remove(
                "is-theme-pulsing"
            );
        },
        2600
    );
}

/* =========================================================
   AMBIENT EFFECT SCHEDULING
   ========================================================= */

function stopAmbientEffects() {
    window.clearTimeout(
        ambientTimer
    );

    window.clearTimeout(
        glitchTimer
    );

    ambientTimer =
        null;

    glitchTimer =
        null;
}

function scheduleAmbientBurst() {
    if (
        reducedMotion ||
        document.hidden
    ) {
        return;
    }

    const profile =
        getProfile();

    ambientTimer =
        window.setTimeout(
            () => {
                const visibleTargets = [
                    ...document.querySelectorAll(
                        ".cut-panel, .ship-frame, .join-section"
                    )
                ].filter(
                    isElementVisible
                );

                const target =
                    visibleTargets.length > 0
                        ? visibleTargets[
                            Math.floor(
                                Math.random() *
                                visibleTargets.length
                            )
                        ]
                        : null;

                spawnThemeBurst(
                    target,
                    "normal"
                );

                if (
                    target &&
                    Math.random() > 0.55
                ) {
                    pulseTarget(
                        target
                    );
                }

                scheduleAmbientBurst();
            },
            randomBetween(
                profile.ambientMinimum,
                profile.ambientMaximum
            )
        );
}

function scheduleAmbientGlitch() {
    if (
        reducedMotion ||
        document.hidden
    ) {
        return;
    }

    const profile =
        getProfile();

    glitchTimer =
        window.setTimeout(
            () => {
                triggerGlitch({
                    intensity:
                        Math.random() > 0.82
                            ? "hard"
                            : "normal",

                    includeText:
                        true
                });

                scheduleAmbientGlitch();
            },
            randomBetween(
                profile.glitchMinimum,
                profile.glitchMaximum
            )
        );
}

function startAmbientEffects() {
    stopAmbientEffects();

    if (
        reducedMotion ||
        document.hidden
    ) {
        return;
    }

    scheduleAmbientBurst();
    scheduleAmbientGlitch();
}

document.addEventListener(
    "visibilitychange",
    () => {
        if (document.hidden) {
            stopAmbientEffects();

            return;
        }

        startAmbientEffects();
    }
);

/* =========================================================
   THEME-CHANGE OVERLAY
   ========================================================= */

function setTransitionText(
    themeKey,
    lineIndex = 0
) {
    const overlay =
        createTransitionOverlay();

    const profile =
        themeProfiles[themeKey];

    const code =
        overlay.querySelector(
            ".theme-transition-code"
        );

    const heading =
        overlay.querySelector("h2");

    const status =
        overlay.querySelector(
            ".theme-transition-status"
        );

    if (code) {
        code.textContent =
            profile.code;
    }

    if (heading) {
        heading.textContent =
            profile.label;
    }

    if (status) {
        status.textContent =
            profile.transitionLines[
                lineIndex
            ] ?? "";
    }
}

function showThemeTransition(
    themeKey
) {
    return new Promise(resolve => {
        const overlay =
            createTransitionOverlay();

        const panel =
            overlay.querySelector(
                ".theme-transition-panel"
            );

        setTransitionText(
            themeKey,
            0
        );

        overlay.classList.add(
            "is-active"
        );

        overlay.setAttribute(
            "aria-hidden",
            "false"
        );

        triggerGlitch({
            intensity:
                "hard",

            target:
                panel,

            includeText:
                false
        });

        window.setTimeout(
            () => {
                setTransitionText(
                    themeKey,
                    1
                );
            },
            420
        );

        window.setTimeout(
            () => {
                /*
                 * The new theme takes over while the transition
                 * overlay is still covering the page.
                 */
                applyThemeImmediately(
                    themeKey
                );

                saveTheme(
                    themeKey
                );

                updateBootText();
                updateThemeButtons();

                setTransitionText(
                    themeKey,
                    2
                );

                triggerGlitch({
                    intensity:
                        "hard",

                    target:
                        panel,

                    includeText:
                        false
                });
            },
            800
        );

        window.setTimeout(
            () => {
                overlay.classList.remove(
                    "is-active"
                );

                overlay.setAttribute(
                    "aria-hidden",
                    "true"
                );

                resolve();
            },
            1550
        );
    });
}

async function changeTheme(
    themeKey
) {
    if (
        !validThemes.includes(
            themeKey
        ) ||
        themeChanging
    ) {
        return;
    }

    /*
     * Clicking the active theme replays a small takeover glitch.
     */
    if (themeKey === activeTheme) {
        triggerGlitch({
            intensity:
                "normal",

            includeText:
                true
        });

        return;
    }

    themeChanging =
        true;

    stopAmbientEffects();

    await showThemeTransition(
        themeKey
    );

    /*
     * applyThemeImmediately already changed activeTheme,
     * but this assignment keeps the final state explicit.
     */
    activeTheme =
        themeKey;

    document.dispatchEvent(
        new CustomEvent(
            "guristas:themechange",
            {
                detail: {
                    themeKey,
                    profile:
                        getProfile()
                }
            }
        )
    );

    startAmbientEffects();

    themeChanging =
        false;
}

/* =========================================================
   UNIVERSAL SITE EVENT LISTENERS
   ========================================================= */

document.addEventListener(
    "guristas:bootstep",
    event => {
        const isFinalStep =
            event.detail.index === 3;

        triggerGlitch({
            intensity:
                isFinalStep
                    ? "hard"
                    : "normal",

            target:
                document.querySelector(
                    ".boot-terminal"
                ),

            includeText:
                true
        });
    }
);

document.addEventListener(
    "guristas:boottakeover",
    event => {
        const terminal =
            event.detail.bootScreen
                ?.querySelector(
                    ".boot-terminal"
                );

        triggerGlitch({
            intensity:
                "hard",

            target:
                terminal,

            includeText:
                true
        });
    }
);

document.addEventListener(
    "guristas:bootcomplete",
    () => {
        const currentShipFrame =
            document.querySelector(
                ".ship-frame"
            );

        pulseTarget(
            currentShipFrame
        );

        spawnThemeBurst(
            currentShipFrame,
            "hard"
        );

        startAmbientEffects();
    }
);

document.addEventListener(
    "guristas:menuopen",
    event => {
        triggerGlitch({
            intensity:
                "normal",

            target:
                event.detail.navigation,

            includeText:
                false
        });
    }
);

document.addEventListener(
    "guristas:operationchange",
    event => {
        const {
            selectedCard,
            shipFrame
        } = event.detail;

        triggerGlitch({
            intensity:
                "normal",

            target:
                selectedCard,

            includeText:
                true
        });

        pulseTarget(
            selectedCard
        );

        if (shipFrame) {
            restartClassAnimation(
                shipFrame,
                "is-ship-glitching"
            );

            spawnThemeBurst(
                shipFrame,
                "normal"
            );

            window.setTimeout(
                () => {
                    shipFrame.classList.remove(
                        "is-ship-glitching"
                    );
                },
                600
            );
        }
    }
);

document.addEventListener(
    "guristas:signalscan",
    event => {
        triggerGlitch({
            intensity:
                event.detail.frequency > 75
                    ? "hard"
                    : "normal",

            target:
                event.detail.signalPanel,

            includeText:
                false
        });

        pulseTarget(
            event.detail.signalPanel
        );
    }
);

document.addEventListener(
    "guristas:directivecopy",
    event => {
        const target =
            event.detail.container
                ?.querySelector(
                    ".cut-panel"
                ) ?? null;

        triggerGlitch({
            intensity:
                "normal",

            target,

            includeText:
                false
        });
    }
);

document.addEventListener(
    "guristas:shipload",
    event => {
        triggerGlitch({
            intensity:
                "normal",

            target:
                event.detail.shipFrame,

            includeText:
                false
        });

        pulseTarget(
            event.detail.shipFrame
        );
    }
);

/* =========================================================
   INITIALIZATION
   ========================================================= */

ensureEffectLayer();
createTransitionOverlay();

initializeGlitchText();
updateBootText();
initializeThemeControls();