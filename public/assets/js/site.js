"use strict";

(() => {

/*
|--------------------------------------------------------------------------
| GURISTAS.NET — UNIVERSAL SITE BEHAVIOR
|--------------------------------------------------------------------------
|
| This file controls:
| - Loading sequence timing
| - Mobile navigation
| - Operation selection
| - Signal scanner
| - Clipboard actions
| - Ship-image fallback
| - Scroll reveal behavior
|
| Theme selection and theme-specific visual effects are handled
| separately by themes.js.
|
*/

/* =========================================================
   SAFE LOCAL STORAGE
   ========================================================= */

const storage = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            console.warn(
                "Local storage is unavailable.",
                error
            );

            return null;
        }
    },

    set(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
            console.warn(
                "Local storage is unavailable.",
                error
            );
        }
    }
};

/* =========================================================
   SITE DATA
   ========================================================= */

const operationDefinitions = {
    raid: {
        label: "RAID // CORRUPT THE WARZONE",
        target: "war-room"
    },

    build: {
        label: "BUILD // FABRICATE GURISTAS HULLS",
        target: "industry-preview"
    },

    trade: {
        label: "TRADE // SUPPLY THE FULCRUM",
        target: "fulcrum"
    },

    lore: {
        label: "INVESTIGATE // RECOVER THE HISTORY",
        target: "lore"
    },

    signals: {
        label: "INTERCEPT // SEIZE THE BROADCAST",
        target: "signals"
    },

    join: {
        label: "JOIN // BECOME PART OF THE OPERATION",
        target: "join"
    }
};

const signalBands = [
    {
        maximum: 12,
        href: "/missions/",
        label: "Find a Guristas contact",

        message:
            "Encrypted Caldari Navy traffic detected. Signal discipline remains annoyingly competent."
    },

    {
        maximum: 26,
        href: "/ships/",
        label: "Inspect the ship archive",

        message:
            "Megacorporate logistics channel detected. Cargo manifests appear valuable."
    },

    {
        maximum: 42,
        href: "/signals/#radio",
        label: "Play Black Rabbit Radio",

        message:
            "Weak Guristas music carrier detected. Black Rabbit Radio may be nearby."
    },

    {
        maximum: 58,
        href: "/signals/#archive",
        label: "Browse intercepted broadcasts",

        message:
            "Federation Frontline Report detected. Federal confidence levels appear artificially inflated."
    },

    {
        maximum: 74,
        href: "/venal/systems/",
        label: "Open Venal intelligence",

        message:
            "Deathless relay handshake detected. Zarzakh routing solution partially recovered."
    },

    {
        maximum: 88,
        href: "/war/guristas/",
        label: "Read the campaign report",

        message:
            "Commando Guri tactical broadcast detected. Insurgency operators are assembling."
    },

    {
        maximum: 100,
        href: "/join/",
        label: "Answer the Guristas signal",

        message:
            "Priority Guristas signal locked. The State made a Fatal mistake."
    }
];

/* =========================================================
   DOCUMENT REFERENCES
   ========================================================= */

const bootScreen = document.querySelector(
    "[data-boot]"
);

const skipBootButton = document.querySelector(
    "[data-skip-boot]"
);

const bootSequenceLines = [
    ...document.querySelectorAll(
        ".boot-sequence span"
    )
];

const menuButton = document.querySelector(
    "[data-menu-button]"
);

const navigation = document.querySelector(
    "[data-navigation]"
);

const operationButtons = [
    ...document.querySelectorAll(
        "[data-operation]"
    )
];

const operationCards = [
    ...document.querySelectorAll(
        "[data-operation-card]"
    )
];

const operationLinks = [
    ...document.querySelectorAll(
        "[data-operation-link]"
    )
];

const selectedOperationLabel =
    document.querySelector(
        "#selectedOperationLabel"
    );

const selectedOperationPanel =
    selectedOperationLabel?.closest(
        ".selected-operation"
    );

const heroShip = document.querySelector(
    "#heroShip"
);

const shipFrame = document.querySelector(
    "[data-ship-frame]"
);

const frequencyInput = document.querySelector(
    "#signalFrequency"
);

const frequencyOutput = document.querySelector(
    "#frequencyOutput"
);

const signalMessage = document.querySelector(
    "#signalMessage"
);

const scanSignalButton = document.querySelector(
    "#scanSignalButton"
);

const copyDirectiveButton = document.querySelector(
    "#copyDirective"
);

const copyDirectiveStatus = document.querySelector(
    "#copyDirectiveStatus"
);

const reducedMotion = window.matchMedia(
    "(prefers-reduced-motion: reduce)"
).matches;

let bootTimers = [];
let bootCompleting = false;

/* =========================================================
   CUSTOM SITE EVENTS
   ========================================================= */

/**
 * Universal behavior emits custom events.
 *
 * themes.js listens for these events and adds visual effects
 * appropriate to the active theme.
 */
function emitSiteEvent(name, detail = {}) {
    document.dispatchEvent(
        new CustomEvent(
            `guristas:${name}`,
            {
                detail
            }
        )
    );
}

/* =========================================================
   GENERAL UTILITIES
   ========================================================= */

function restartClassAnimation(
    element,
    className
) {
    if (!element) {
        return;
    }

    element.classList.remove(className);

    void element.offsetWidth;

    element.classList.add(className);
}

function clearBootTimers() {
    bootTimers.forEach(timerId => {
        window.clearTimeout(timerId);
    });

    bootTimers = [];
}

/* =========================================================
   BOOT SCREEN
   ========================================================= */

function resetBootLines() {
    bootSequenceLines.forEach(line => {
        line.classList.remove(
            "is-visible",
            "is-current",
            "is-complete"
        );
    });
}

function showBootLine(index) {
    const line = bootSequenceLines[index];

    if (!line || bootCompleting) {
        return;
    }

    if (index > 0) {
        const previousLine =
            bootSequenceLines[index - 1];

        previousLine?.classList.remove(
            "is-current"
        );

        previousLine?.classList.add(
            "is-complete"
        );
    }

    line.classList.add(
        "is-visible",
        "is-current"
    );

    emitSiteEvent(
        "bootstep",
        {
            index,
            line
        }
    );
}

function completeFinalBootLine() {
    bootSequenceLines.forEach(line => {
        line.classList.add(
            "is-visible",
            "is-complete"
        );

        line.classList.remove(
            "is-current"
        );
    });
}

function completeBootSequence() {
    if (bootCompleting) {
        return;
    }

    bootCompleting = true;

    if (!bootScreen) {
        emitSiteEvent("bootcomplete");

        return;
    }

    clearBootTimers();
    completeFinalBootLine();

    bootScreen.classList.add(
        "is-unlocking"
    );

    emitSiteEvent(
        "boottakeover",
        {
            bootScreen
        }
    );

    const finishTimer = window.setTimeout(
        () => {
            bootScreen.classList.add(
                "is-complete"
            );

            bootScreen.setAttribute(
                "aria-hidden",
                "true"
            );

            storage.set(
                "guristas.bootSeen",
                "1"
            );

            const removalTimer =
                window.setTimeout(
                    () => {
                        bootScreen.remove();

                        emitSiteEvent(
                            "bootcomplete"
                        );
                    },
                    reducedMotion
                        ? 0
                        : 650
                );

            bootTimers.push(removalTimer);
        },
        reducedMotion
            ? 0
            : 620
    );

    bootTimers.push(finishTimer);
}

function initializeBootSequence() {
    if (!bootScreen) {
        emitSiteEvent("bootcomplete");

        return;
    }

    resetBootLines();

    if (reducedMotion) {
        completeBootSequence();

        return;
    }

    const bootSeen =
        storage.get("guristas.bootSeen") === "1";

    /*
     * First visit:
     * 0.55 seconds
     * 1.45 seconds
     * 2.35 seconds
     * 3.25 seconds
     * Complete at 4.15 seconds
     *
     * Returning visit:
     * 0.25 seconds
     * 0.70 seconds
     * 1.15 seconds
     * 1.60 seconds
     * Complete at 2.05 seconds
     */
    const lineTimings = bootSeen
        ? [
            250,
            700,
            1150,
            1600
        ]
        : [
            550,
            1450,
            2350,
            3250
        ];

    lineTimings.forEach(
        (delay, index) => {
            const timerId =
                window.setTimeout(
                    () => {
                        showBootLine(index);
                    },
                    delay
                );

            bootTimers.push(timerId);
        }
    );

    const completionDelay = bootSeen
        ? 2050
        : 4150;

    const completionTimer =
        window.setTimeout(
            () => {
                completeBootSequence();
            },
            completionDelay
        );

    bootTimers.push(completionTimer);
}

skipBootButton?.addEventListener(
    "click",
    event => {
        event.preventDefault();

        completeBootSequence();
    }
);

/* =========================================================
   MOBILE NAVIGATION
   ========================================================= */

function setNavigationState(isOpen) {
    if (!menuButton || !navigation) {
        return;
    }

    menuButton.setAttribute(
        "aria-expanded",
        isOpen ? "true" : "false"
    );

    navigation.classList.toggle(
        "is-open",
        isOpen
    );

    if (isOpen) {
        emitSiteEvent(
            "menuopen",
            {
                navigation
            }
        );
    }
}

menuButton?.addEventListener(
    "click",
    () => {
        const currentlyOpen =
            menuButton.getAttribute(
                "aria-expanded"
            ) === "true";

        setNavigationState(
            !currentlyOpen
        );
    }
);

navigation?.addEventListener(
    "click",
    event => {
        if (event.target.closest("a")) {
            setNavigationState(false);
        }
    }
);

document.addEventListener(
    "keydown",
    event => {
        if (event.key === "Escape") {
            const returnFocus = navigation?.classList.contains("is-open") &&
                navigation.contains(document.activeElement);
            setNavigationState(false);
            if (returnFocus) {
                menuButton?.focus();
            }
        }
    }
);

document.addEventListener(
    "click",
    event => {
        if (!navigation || !menuButton) {
            return;
        }

        const clickedInsideNavigation =
            navigation.contains(
                event.target
            );

        const clickedMenuButton =
            menuButton.contains(
                event.target
            );

        if (
            !clickedInsideNavigation &&
            !clickedMenuButton
        ) {
            setNavigationState(false);
        }
    }
);

/* =========================================================
   OPERATION SELECTION
   ========================================================= */

function animateOperationSelection(
    operationKey
) {
    const selectedButton =
        operationButtons.find(
            button =>
                button.dataset.operation ===
                operationKey
        );

    const selectedCard =
        operationCards.find(
            card =>
                card.dataset.operationCard ===
                operationKey
        );

    restartClassAnimation(
        selectedButton,
        "is-chip-glitching"
    );

    restartClassAnimation(
        selectedOperationPanel,
        "is-updating"
    );

    emitSiteEvent(
        "operationchange",
        {
            operationKey,
            selectedButton,
            selectedCard,
            selectedOperationPanel,
            shipFrame
        }
    );

    window.setTimeout(
        () => {
            selectedButton?.classList.remove(
                "is-chip-glitching"
            );

            selectedOperationPanel?.classList.remove(
                "is-updating"
            );
        },
        450
    );
}

function selectOperation(
    operationKey,
    shouldStore = true,
    shouldAnimate = true
) {
    const definition =
        operationDefinitions[operationKey];

    if (!definition) {
        return;
    }

    document.documentElement.dataset.operation =
        operationKey;

    operationButtons.forEach(button => {
        const selected =
            button.dataset.operation ===
            operationKey;

        button.setAttribute(
            "aria-pressed",
            selected ? "true" : "false"
        );
    });

    operationCards.forEach(card => {
        card.classList.toggle(
            "is-selected",
            card.dataset.operationCard ===
                operationKey
        );
    });

    if (selectedOperationLabel) {
        selectedOperationLabel.textContent =
            definition.label;
    }

    if (shouldStore) {
        storage.set(
            "guristas.preferredOperation",
            operationKey
        );
    }

    if (shouldAnimate) {
        animateOperationSelection(
            operationKey
        );
    }
}

operationButtons.forEach(button => {
    button.addEventListener(
        "click",
        () => {
            const operationKey =
                button.dataset.operation;

            const targetId =
                button.dataset.target;

            selectOperation(operationKey);

            const target =
                document.getElementById(
                    targetId
                );

            if (target) {
                window.setTimeout(
                    () => {
                        target.scrollIntoView({
                            behavior:
                                reducedMotion
                                    ? "auto"
                                    : "smooth",

                            block: "start"
                        });
                    },
                    180
                );
            }
        }
    );
});

operationLinks.forEach(link => {
    link.addEventListener(
        "click",
        () => {
            selectOperation(
                link.dataset.operationLink
            );
        }
    );
});

function restorePreferredOperation() {
    const savedOperation =
        storage.get(
            "guristas.preferredOperation"
        );

    if (
        savedOperation &&
        operationDefinitions[savedOperation]
    ) {
        selectOperation(
            savedOperation,
            false,
            false
        );

        return;
    }

    selectOperation(
        "raid",
        false,
        false
    );
}

/* =========================================================
   SHIP IMAGE
   ========================================================= */

function markShipImageAvailable() {
    if (!shipFrame || !heroShip) {
        return;
    }

    shipFrame.classList.remove(
        "is-missing"
    );

    emitSiteEvent(
        "shipload",
        {
            heroShip,
            shipFrame
        }
    );
}

function markShipImageMissing() {
    shipFrame?.classList.add(
        "is-missing"
    );
}

function initializeShipImage() {
    if (!heroShip || !shipFrame) {
        return;
    }

    heroShip.addEventListener(
        "load",
        markShipImageAvailable
    );

    heroShip.addEventListener(
        "error",
        markShipImageMissing
    );

    /*
     * The image may already be loaded before this script runs.
     */
    if (heroShip.complete) {
        if (heroShip.naturalWidth > 0) {
            markShipImageAvailable();
        } else {
            markShipImageMissing();
        }
    }
}

/* =========================================================
   PIRATE SIGNAL SCANNER
   ========================================================= */

function calculateFrequency(inputValue) {
    const minimumFrequency = 104.1;
    const frequencyRange = 83.7;

    return (
        minimumFrequency +
        (
            Number(inputValue) / 100
        ) *
        frequencyRange
    ).toFixed(1);
}

function getSignalMessage(inputValue) {
    const numericValue =
        Number(inputValue);

    return (
        signalBands.find(
            band =>
                numericValue <=
                band.maximum
        )?.message ??
        signalBands[
            signalBands.length - 1
        ].message
    );
}

function updateSignalScanner({
    animate = false
} = {}) {
    if (
        !frequencyInput ||
        !frequencyOutput ||
        !signalMessage
    ) {
        return;
    }

    const numericFrequency =
        Number(frequencyInput.value);

    frequencyOutput.value =
        calculateFrequency(
            numericFrequency
        );

    signalMessage.textContent =
        getSignalMessage(
            numericFrequency
        );

    const destination = document.querySelector('[data-signal-destination]');
    const band = signalBands.find(item => numericFrequency <= item.maximum) || signalBands[signalBands.length - 1];
    if (destination) { destination.href = band.href; destination.textContent = band.label; }

    if (!animate) {
        return;
    }

    restartClassAnimation(
        frequencyOutput,
        "is-frequency-glitching"
    );

    restartClassAnimation(
        signalMessage,
        "is-signal-glitching"
    );

    emitSiteEvent(
        "signalscan",
        {
            frequency: numericFrequency,
            frequencyOutput,
            signalMessage,

            signalPanel:
                signalMessage.closest(
                    ".cut-panel"
                )
        }
    );

    window.setTimeout(
        () => {
            frequencyOutput.classList.remove(
                "is-frequency-glitching"
            );

            signalMessage.classList.remove(
                "is-signal-glitching"
            );
        },
        500
    );
}

frequencyInput?.addEventListener(
    "input",
    () => {
        updateSignalScanner();
    }
);

frequencyInput?.addEventListener(
    "change",
    () => {
        updateSignalScanner({
            animate: true
        });
    }
);

scanSignalButton?.addEventListener(
    "click",
    () => {
        if (!frequencyInput) {
            return;
        }

        const randomFrequency =
            Math.floor(
                Math.random() * 101
            );

        frequencyInput.value =
            String(randomFrequency);

        updateSignalScanner({
            animate: true
        });

        frequencyInput.focus();
    }
);

/* =========================================================
   FULCRUM DIRECTIVE
   ========================================================= */

const fulcrumDirective = [
    "GURISTAS.NET // THE FULCRUM DIRECTIVE",
    "",
    "Help establish The Fulcrum in Zarzakh as a functioning pirate trade hub.",
    "",
    "Needed:",
    "- Guristas ships",
    "- Appropriate fittings",
    "- Missiles, drones, rigs, ammunition and charges",
    "- Manufacturing materials and faction components",
    "- Competitive local market orders",
    "",
    "Manufacture, haul, sell or contribute through EVE Online.",
    "Search for Cozen Corp for current operations."
].join("\n");

async function copyText(text) {
    /*
     * Modern clipboard API.
     */
    if (
        navigator.clipboard &&
        window.isSecureContext
    ) {
        await navigator.clipboard.writeText(
            text
        );

        return;
    }

    /*
     * Fallback for local HTTP development.
     */
    const temporaryTextArea =
        document.createElement(
            "textarea"
        );

    temporaryTextArea.value = text;

    temporaryTextArea.setAttribute(
        "readonly",
        ""
    );

    temporaryTextArea.style.position =
        "fixed";

    temporaryTextArea.style.top =
        "-9999px";

    temporaryTextArea.style.left =
        "-9999px";

    temporaryTextArea.style.opacity =
        "0";

    document.body.appendChild(
        temporaryTextArea
    );

    temporaryTextArea.select();

    const copied =
        document.execCommand("copy");

    temporaryTextArea.remove();

    if (!copied) {
        throw new Error(
            "The browser rejected the clipboard command."
        );
    }
}

copyDirectiveButton?.addEventListener(
    "click",
    async () => {
        if (!copyDirectiveStatus) {
            return;
        }

        copyDirectiveButton.disabled =
            true;

        try {
            await copyText(
                fulcrumDirective
            );

            copyDirectiveStatus.textContent =
                "Directive copied.";

            emitSiteEvent(
                "directivecopy",
                {
                    button:
                        copyDirectiveButton,

                    container:
                        copyDirectiveButton.closest(
                            ".feature-layout"
                        )
                }
            );

            window.setTimeout(
                () => {
                    copyDirectiveStatus.textContent =
                        "";
                },
                2400
            );
        } catch (error) {
            console.error(
                "Could not copy the directive.",
                error
            );

            copyDirectiveStatus.textContent =
                "Copy failed. Try again.";
        } finally {
            window.setTimeout(
                () => {
                    copyDirectiveButton.disabled =
                        false;
                },
                350
            );
        }
    }
);

/* =========================================================
   SCROLL REVEAL
   ========================================================= */

function initializeRevealAnimations() {
    const revealItems = [
        ...document.querySelectorAll(
            ".reveal"
        )
    ];

    if (
        reducedMotion ||
        !(
            "IntersectionObserver" in
            window
        )
    ) {
        revealItems.forEach(item => {
            item.classList.add(
                "is-visible"
            );
        });

        return;
    }

    const observer =
        new IntersectionObserver(
            entries => {
                entries.forEach(entry => {
                    if (
                        !entry.isIntersecting
                    ) {
                        return;
                    }

                    entry.target.classList.add(
                        "is-visible"
                    );

                    observer.unobserve(
                        entry.target
                    );
                });
            },
            {
                rootMargin:
                    "0px 0px -8% 0px",

                threshold: 0.08
            }
        );

    revealItems.forEach(item => {
        observer.observe(item);
    });
}

/* =========================================================
   EXTERNAL HASH NAVIGATION
   ========================================================= */

/**
 * When the page is opened with a hash, wait until the boot
 * sequence finishes before scrolling to that section.
 */
function scrollToInitialHash() {
    const hash =
        window.location.hash;

    if (!hash || hash === "#command") {
        return;
    }

    let target = null;

    try {
        target =
            document.querySelector(hash);
    } catch (error) {
        return;
    }

    if (!target) {
        return;
    }

    window.setTimeout(
        () => {
            target.scrollIntoView({
                behavior:
                    reducedMotion
                        ? "auto"
                        : "smooth",

                block: "start"
            });
        },
        120
    );
}

document.addEventListener(
    "guristas:bootcomplete",
    scrollToInitialHash,
    {
        once: true
    }
);

/* =========================================================
   INITIALIZATION
   ========================================================= */

restorePreferredOperation();
initializeShipImage();
updateSignalScanner();
initializeRevealAnimations();
initializeBootSequence();

})();