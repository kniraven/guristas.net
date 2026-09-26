"use strict";

import { createNoticeElement, fallbackType } from "/assets/js/stream/notification-ui.js";

const API = "/api/stream/messages.php";
const state = { types: [], messages: [], settings: {}, selectedType: null, selectedMessage: null };

const $ = selector => document.querySelector(selector);
const dom = {
    key: $("#adminKey"), status: $("#status"), reload: $("#reloadButton"),
    messageList: $("#messageList"), typeList: $("#typeList"),
    messagePreview: $("#messagePreview"), typePreview: $("#typePreview"),
    settingsForm: $("#settingsForm"), messageType: $("#messageType")
};

const typeFields = {
    original: $("#typeOriginalId"), id: $("#typeId"), name: $("#typeName"),
    accent: $("#typeAccent"), secondary: $("#typeSecondary"), background: $("#typeBackground"),
    border: $("#typeBorder"), glow: $("#typeGlow"), meta: $("#typeMeta"), badge: $("#typeBadge"),
    priority: $("#typePriority"), cooldown: $("#typeCooldown"), enabled: $("#typeEnabled"), label: $("#typeIdLabel")
};
const messageFields = {
    id: $("#messageId"), type: $("#messageType"), title: $("#messageTitle"), badge: $("#messageBadge"),
    body: $("#messageBody"), meta: $("#messageMeta"), priority: $("#messagePriority"), weight: $("#messageWeight"),
    side: $("#messageSide"), enabled: $("#messageEnabled"), label: $("#messageIdLabel")
};

const SETTINGS = [
    ["notifications_enabled", "Notifications enabled", "boolean", "Master switch for both live events and ambient messages."],
    ["ambient_interval_min_ms", "Ambient minimum gap (ms)", "number", "Shortest normal gap before an ambient message slot."],
    ["ambient_interval_max_ms", "Ambient maximum gap (ms)", "number", "Longest normal gap before an ambient message slot."],
    ["visible_duration_min_ms", "Visible duration min (ms)", "number", "Minimum readable time before the fade-out begins."],
    ["visible_duration_max_ms", "Visible duration max (ms)", "number", "Maximum readable time before the fade-out begins."],
    ["event_min_gap_ms", "Live-event minimum gap (ms)", "number", "Prevents bursts of real map changes from becoming a ticker."],
    ["event_expiry_ms", "Queued event expiry (ms)", "number", "Drops stale live events instead of showing old intelligence later."],
    ["recent_message_memory", "Recent ambient memory", "number", "How many recent custom messages are avoided before repeating."],
    ["intel_refresh_ms", "Intelligence poll (ms)", "number", "Browser check interval. Server-side source caching still controls external requests."],
    ["message_refresh_ms", "Message config poll (ms)", "number", "How quickly changes from this page appear in the live overlay."],
    ["render_fps", "Map render FPS", "number", "24 is a good OBS target."],
    ["label_fps", "Label update FPS", "number", "Lower than map FPS to reduce DOM work."],
    ["pixel_ratio_cap", "WebGL pixel-ratio cap", "number", "1.0–1.25 is recommended for the OBS source."],
    ["war_change_percent_threshold", "War progress message threshold", "number", "Minimum corruption/suppression point shift before a routine progress popup is queued."],
    ["venal_kill_event_minimum", "Venal kill minimum", "number", "Minimum current one-hour kills before Venal can generate a combat update."],
    ["kill_spike_delta", "Kill increase threshold", "number", "Minimum increase between refreshes before a kill-update popup is queued."]
];

function adminKey() { return dom.key.value.trim(); }
function headers() {
    const result = { "Content-Type": "application/json", Accept: "application/json" };
    if (adminKey()) result["X-Guristas-Stream-Key"] = adminKey();
    return result;
}
function setStatus(text, mode = "") {
    dom.status.textContent = text;
    dom.status.className = `status ${mode}`.trim();
}
async function api(url, options = {}) {
    const response = await fetch(url, { cache: "no-store", ...options, headers: { ...headers(), ...(options.headers || {}) } });
    const payload = await response.json();
    if (!response.ok || !payload?.ok) throw new Error(payload?.error || `HTTP ${response.status}`);
    return payload;
}
async function load() {
    setStatus("Loading…");
    try {
        const payload = await api(`${API}?admin=1`);
        applyBundle(payload.data);
        setStatus("Admin data loaded", "good");
    } catch (error) {
        setStatus(error.message, "bad");
    }
}
function applyBundle(bundle) {
    state.types = Array.isArray(bundle?.types) ? bundle.types : [];
    state.messages = Array.isArray(bundle?.messages) ? bundle.messages : [];
    state.settings = bundle?.settings || {};
    renderTypeList();
    renderMessageTypeOptions();
    renderMessageList();
    renderSettings();
    if (!state.selectedType && state.types[0]) selectType(state.types[0].id);
    else if (state.selectedType) selectType(state.selectedType, false);
    if (!state.selectedMessage && state.messages[0]) selectMessage(state.messages[0].id);
    else if (state.selectedMessage) selectMessage(state.selectedMessage, false);
}
function typeById(id) { return state.types.find(type => String(type.id) === String(id)) || fallbackType(); }
function escapeText(value) { return String(value ?? ""); }

function renderTypeList() {
    dom.typeList.innerHTML = "";
    for (const type of state.types) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = `list-item${String(type.id) === String(state.selectedType) ? " is-active" : ""}`;
        const strong = document.createElement("strong");
        strong.textContent = escapeText(type.name);
        const small = document.createElement("small");
        small.textContent = `${escapeText(type.id)} · ${type.enabled ? "enabled" : "disabled"}`;
        button.append(strong, small);
        button.addEventListener("click", () => selectType(type.id));
        dom.typeList.appendChild(button);
    }
}
function renderMessageList() {
    dom.messageList.innerHTML = "";
    for (const message of state.messages) {
        const type = typeById(message.type);
        const button = document.createElement("button");
        button.type = "button";
        button.className = `list-item${String(message.id) === String(state.selectedMessage) ? " is-active" : ""}`;
        const strong = document.createElement("strong");
        strong.textContent = escapeText(message.title);
        const small = document.createElement("small");
        small.textContent = `${escapeText(type.name)} · ${message.enabled ? "enabled" : "disabled"}`;
        button.append(strong, small);
        button.addEventListener("click", () => selectMessage(message.id));
        dom.messageList.appendChild(button);
    }
}
function renderMessageTypeOptions() {
    const current = dom.messageType.value;
    dom.messageType.innerHTML = "";
    for (const type of state.types) {
        const option = document.createElement("option");
        option.value = type.id;
        option.textContent = type.name;
        dom.messageType.appendChild(option);
    }
    if (state.types.some(type => type.id === current)) dom.messageType.value = current;
}

function blankType() {
    return { ...fallbackType(), id: "", name: "New Message Type", default_badge: "GURISTAS RELAY", default_priority: "ambient", cooldown_seconds: 20, enabled: true };
}
function selectType(id, updateLists = true) {
    const type = state.types.find(row => String(row.id) === String(id)) || blankType();
    state.selectedType = type.id || null;
    typeFields.original.value = type.id || "";
    typeFields.id.value = type.id || "";
    typeFields.id.readOnly = Boolean(type.id);
    typeFields.name.value = type.name || "";
    typeFields.accent.value = type.accent || "#ff6a00";
    typeFields.secondary.value = type.secondary || "#ffffff";
    typeFields.background.value = type.background || "#100804";
    typeFields.border.value = type.border || "#ff8737";
    typeFields.glow.value = type.glow || "#ff6a00";
    typeFields.meta.value = type.meta || "#ffc599";
    typeFields.badge.value = type.default_badge || "";
    typeFields.priority.value = type.default_priority || "ambient";
    typeFields.cooldown.value = type.cooldown_seconds ?? 20;
    typeFields.enabled.checked = type.enabled !== false;
    typeFields.label.textContent = type.id || "NEW";
    if (updateLists) renderTypeList();
    updateTypePreview();
}
function currentTypeForm() {
    return {
        id: typeFields.id.value.trim(), name: typeFields.name.value.trim(), accent: typeFields.accent.value,
        secondary: typeFields.secondary.value, background: typeFields.background.value, border: typeFields.border.value,
        glow: typeFields.glow.value, meta: typeFields.meta.value, default_badge: typeFields.badge.value.trim(),
        default_priority: typeFields.priority.value, cooldown_seconds: Number(typeFields.cooldown.value || 0), enabled: typeFields.enabled.checked
    };
}
function updateTypePreview() {
    dom.typePreview.innerHTML = "";
    const type = currentTypeForm();
    const notice = createNoticeElement({
        title: type.name || "MESSAGE TYPE",
        badge: type.default_badge || "GURISTAS RELAY",
        body: "Live preview: this is exactly how messages using this type will be rendered on the stream overlay.",
        meta: "PREVIEW // MESSAGE NETWORK",
        priority: type.default_priority || "ambient"
    }, type, { preview: true });
    dom.typePreview.appendChild(notice);
}

function blankMessage() {
    return { id:"", type: state.types[0]?.id || "system", title:"NEW TRANSMISSION", badge:"", body:"Write the message body here.", meta:"OPEN BAND", priority:"ambient", weight:1, side:"either", enabled:true };
}
function selectMessage(id, updateLists = true) {
    const message = state.messages.find(row => String(row.id) === String(id)) || blankMessage();
    state.selectedMessage = message.id || null;
    messageFields.id.value = message.id || "";
    messageFields.type.value = state.types.some(type => type.id === message.type) ? message.type : (state.types[0]?.id || "system");
    messageFields.title.value = message.title || "";
    messageFields.badge.value = message.badge || "";
    messageFields.body.value = message.body || "";
    messageFields.meta.value = message.meta || "";
    messageFields.priority.value = message.priority || "ambient";
    messageFields.weight.value = message.weight ?? 1;
    messageFields.side.value = message.side || "either";
    messageFields.enabled.checked = message.enabled !== false;
    messageFields.label.textContent = message.id || "NEW";
    if (updateLists) renderMessageList();
    updateMessagePreview();
}
function currentMessageForm() {
    return {
        id: messageFields.id.value.trim(), type: messageFields.type.value, title: messageFields.title.value.trim(),
        badge: messageFields.badge.value.trim(), body: messageFields.body.value.trim(), meta: messageFields.meta.value.trim(),
        priority: messageFields.priority.value, weight: Number(messageFields.weight.value || 1), side: messageFields.side.value,
        enabled: messageFields.enabled.checked
    };
}
function updateMessagePreview() {
    dom.messagePreview.innerHTML = "";
    const message = currentMessageForm();
    const type = typeById(message.type);
    if (!message.badge) message.badge = type.default_badge || "NETWORK";
    const notice = createNoticeElement(message, type, { preview: true });
    dom.messagePreview.appendChild(notice);
}

async function post(action, data) {
    setStatus("Saving…");
    const payload = await api(API, { method:"POST", body:JSON.stringify({ action, ...data }) });
    applyBundle(payload.data);
    setStatus("Saved", "good");
    return payload.result;
}

function renderSettings() {
    dom.settingsForm.innerHTML = "";
    for (const [key, label, kind, help] of SETTINGS) {
        const card = document.createElement("div");
        card.className = "settings-card";
        const h = document.createElement("h3"); h.textContent = label;
        const field = document.createElement("div"); field.className = "field";
        let input;
        if (kind === "boolean") {
            input = document.createElement("input"); input.type = "checkbox"; input.checked = Boolean(state.settings[key]);
            const row = document.createElement("label"); row.className = "check-row"; row.append(input, document.createTextNode(" Enabled")); field.appendChild(row);
        } else {
            input = document.createElement("input"); input.type = "number"; input.step = key.includes("ratio") || key.includes("threshold") ? "0.1" : "1"; input.value = state.settings[key] ?? ""; field.appendChild(input);
        }
        input.dataset.setting = key;
        const p = document.createElement("p"); p.className = "help"; p.textContent = help;
        card.append(h, field, p);
        dom.settingsForm.appendChild(card);
    }
}
function currentSettingsForm() {
    const result = {};
    for (const input of dom.settingsForm.querySelectorAll("[data-setting]")) {
        result[input.dataset.setting] = input.type === "checkbox" ? input.checked : Number(input.value);
    }
    return result;
}

for (const button of document.querySelectorAll("[data-tab]")) {
    button.addEventListener("click", () => {
        document.querySelectorAll("[data-tab]").forEach(node => node.classList.toggle("is-active", node === button));
        document.querySelectorAll("[data-panel]").forEach(panel => panel.classList.toggle("is-active", panel.dataset.panel === button.dataset.tab));
    });
}

dom.key.value = localStorage.getItem("guristas.streamAdminKey") || "";
dom.key.addEventListener("change", () => localStorage.setItem("guristas.streamAdminKey", adminKey()));
dom.reload.addEventListener("click", load);

$("#newType").addEventListener("click", () => { state.selectedType = null; selectType(null); });
$("#duplicateType").addEventListener("click", () => { const copy = currentTypeForm(); copy.id = ""; copy.name = `${copy.name} Copy`; state.selectedType = null; typeFields.id.readOnly = false; Object.assign(copy,{id:""}); selectType(null); typeFields.name.value=copy.name; typeFields.accent.value=copy.accent; typeFields.secondary.value=copy.secondary; typeFields.background.value=copy.background; typeFields.border.value=copy.border; typeFields.glow.value=copy.glow; typeFields.meta.value=copy.meta; typeFields.badge.value=copy.default_badge; typeFields.priority.value=copy.default_priority; typeFields.cooldown.value=copy.cooldown_seconds; typeFields.enabled.checked=copy.enabled; updateTypePreview(); });
$("#saveType").addEventListener("click", async () => { try { const saved = await post("save_type", { type: currentTypeForm() }); state.selectedType = saved.id; selectType(saved.id); } catch(e){ setStatus(e.message,"bad"); } });
$("#deleteType").addEventListener("click", async () => { const id=typeFields.original.value; if(!id||!confirm(`Delete message type '${id}'? Messages using it will be reassigned.`))return; try{await post("delete_type",{id});state.selectedType=null;selectType(state.types[0]?.id);}catch(e){setStatus(e.message,"bad");} });

$("#newMessage").addEventListener("click", () => { state.selectedMessage = null; selectMessage(null); });
$("#duplicateMessage").addEventListener("click", () => { const copy=currentMessageForm(); state.selectedMessage=null; selectMessage(null); messageFields.type.value=copy.type; messageFields.title.value=`${copy.title} COPY`; messageFields.badge.value=copy.badge; messageFields.body.value=copy.body; messageFields.meta.value=copy.meta; messageFields.priority.value=copy.priority; messageFields.weight.value=copy.weight; messageFields.side.value=copy.side; messageFields.enabled.checked=copy.enabled; updateMessagePreview(); });
$("#saveMessage").addEventListener("click", async () => { try { const saved=await post("save_message",{message:currentMessageForm()});state.selectedMessage=saved.id;selectMessage(saved.id);}catch(e){setStatus(e.message,"bad");} });
$("#deleteMessage").addEventListener("click", async () => { const id=messageFields.id.value; if(!id||!confirm(`Delete message '${id}'?`))return; try{await post("delete_message",{id});state.selectedMessage=null;selectMessage(state.messages[0]?.id);}catch(e){setStatus(e.message,"bad");} });
$("#saveSettings").addEventListener("click", async () => { try{await post("save_settings",{settings:currentSettingsForm()});}catch(e){setStatus(e.message,"bad");} });

$("#typeForm").addEventListener("input", updateTypePreview);
$("#typeForm").addEventListener("change", updateTypePreview);
$("#messageForm").addEventListener("input", updateMessagePreview);
$("#messageForm").addEventListener("change", updateMessagePreview);

load();
