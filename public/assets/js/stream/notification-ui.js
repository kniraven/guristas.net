"use strict";

function cleanHex(value, fallback = "#ffffff") {
    const text = String(value || "").trim().toLowerCase();
    return /^#[0-9a-f]{6}$/.test(text) ? text : fallback;
}

function hexRgb(hex) {
    const safe = cleanHex(hex).slice(1);
    return `${parseInt(safe.slice(0,2),16)}, ${parseInt(safe.slice(2,4),16)}, ${parseInt(safe.slice(4,6),16)}`;
}

export function fallbackType() {
    return {
        id: "system",
        name: "System Message",
        accent: "#ff6a00",
        secondary: "#ffffff",
        background: "#100804",
        border: "#ff8737",
        glow: "#ff6a00",
        meta: "#ffc599",
        default_badge: "NETWORK",
        default_priority: "info",
        cooldown_seconds: 5,
        enabled: true
    };
}

export function applyNoticeType(element, typeInput = {}) {
    const type = { ...fallbackType(), ...typeInput };
    const accent = cleanHex(type.accent, "#ff6a00");
    const secondary = cleanHex(type.secondary, "#ffffff");
    const background = cleanHex(type.background, "#100804");
    const border = cleanHex(type.border, accent);
    const glow = cleanHex(type.glow, accent);
    const meta = cleanHex(type.meta, secondary);

    element.style.setProperty("--notice-accent", accent);
    element.style.setProperty("--notice-secondary", secondary);
    element.style.setProperty("--notice-border", border);
    element.style.setProperty("--notice-glow", glow);
    element.style.setProperty("--notice-meta", meta);
    element.style.setProperty("--notice-accent-rgb", hexRgb(accent));
    element.style.setProperty("--notice-secondary-rgb", hexRgb(secondary));
    element.style.setProperty("--notice-bg-rgb", hexRgb(background));
    element.style.setProperty("--notice-glow-rgb", hexRgb(glow));
    return type;
}

export function createNoticeElement(messageInput = {}, typeInput = {}, options = {}) {
    const type = { ...fallbackType(), ...typeInput };
    const message = {
        title: "GURISTAS RELAY",
        badge: type.default_badge || "NETWORK",
        body: "Transmission received.",
        meta: "OPEN BAND",
        priority: type.default_priority || "ambient",
        ...messageInput
    };
    if (!String(message.badge || "").trim()) message.badge = type.default_badge || "NETWORK";
    if (!String(message.meta || "").trim()) message.meta = "OPEN BAND";

    const panel = document.createElement("article");
    panel.className = "stream-notice";
    if (options.preview) panel.classList.add("stream-notice-preview", "is-visible");
    if (message.priority === "priority") panel.classList.add("is-priority");
    if (message.priority === "critical") panel.classList.add("is-priority", "is-critical");
    panel.dataset.type = String(type.id || "system");

    applyNoticeType(panel, type);

    const header = document.createElement("div");
    header.className = "stream-notice-header";
    const title = document.createElement("div");
    title.className = "stream-notice-title";
    title.textContent = String(message.title || "GURISTAS RELAY");
    const badge = document.createElement("div");
    badge.className = "stream-notice-badge";
    badge.textContent = String(message.badge || type.default_badge || "NETWORK");
    header.append(title, badge);

    const body = document.createElement("div");
    body.className = "stream-notice-body";
    body.textContent = String(message.body || "Transmission received.");

    const meta = document.createElement("div");
    meta.className = "stream-notice-meta";
    meta.textContent = String(message.meta || "OPEN BAND");

    panel.append(header, body, meta);
    return panel;
}
