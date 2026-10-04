"use strict";

import * as THREE from "https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js";
import { createNoticeElement, fallbackType } from "/assets/js/stream/notification-ui.js";

const DESIGN = { width: 1920, height: 1080 };
const VIEW = {
    // Notification/title zones stay unchanged.  The actual insurgency map uses
    // WAR_MAP_VIEW below so its layout can be tightened independently.
    war: { x: 0, y: 0, width: 640, height: 540 },
    zarzakh: { x: 0, y: 540, width: 640, height: 540 },
    venal: { x: 1280, y: 0, width: 640, height: 1080 }
};

// Keep the rotating insurgency topology entirely in the left-most 480 px and
// lower it beneath the title.  Its bottom edge stays safely above the Zarzakh
// mark, even allowing for labels and active-node rings.
const WAR_MAP_VIEW = { x: 0, y: 72, width: 480, height: 530 };
const INTEL_URL = "/api/stream/intelligence.php";
const MESSAGE_URL = "/api/stream/messages.php";
const COLORS = {
    orange: new THREE.Color("#ff6a00"),
    red: new THREE.Color("#ff2739"),
    cyan: new THREE.Color("#55d9ff"),
    green: new THREE.Color("#56ff8a"),
    white: new THREE.Color("#ffffff")
};
const COLOR_HEX = {
    guristas: "#ff6a00",
    caldari: "#55d9ff",
    gallente: "#56ff8a",
    neutral: "#ffffff",
    threat: "#ff2739"
};

const DEFAULT_SETTINGS = {
    notifications_enabled: true,
    ambient_interval_min_ms: 8000,
    ambient_interval_max_ms: 15000,
    visible_duration_min_ms: 5600,
    visible_duration_max_ms: 8200,
    enter_duration_min_ms: 90,
    enter_duration_max_ms: 180,
    exit_duration_min_ms: 220,
    exit_duration_max_ms: 360,
    event_min_gap_ms: 5000,
    event_expiry_ms: 120000,
    max_active: 1,
    recent_message_memory: 12,
    intel_refresh_ms: 300000,
    message_refresh_ms: 60000,
    render_fps: 24,
    label_fps: 12,
    pixel_ratio_cap: 1.15,
    war_change_percent_threshold: 2.5,
    venal_kill_event_minimum: 2,
    kill_spike_delta: 3
};

const dom = {
    overlay: document.querySelector("#streamOverlay"),
    canvas: document.querySelector("#streamWebgl"),
    labelLayer: document.querySelector("#mapLabelLayer"),
    notificationLayer: document.querySelector("#notificationLayer"),
    zarzakhMark: document.querySelector("#zarzakhMark"),
    zarzakhCore: document.querySelector("#zarzakhCore"),
    zarzakhKills: document.querySelector("#zarzakhKills"),
    error: document.querySelector("#streamError")
};

const app = {
    settings: { ...DEFAULT_SETTINGS },
    types: [],
    typeById: new Map(),
    messages: [],
    previousIntel: null,
    refreshingIntel: false,
    activeNotices: 0,
    eventQueue: [],
    recentMessageIds: [],
    lastTypeShown: new Map(),
    nextAmbientAt: 0,
    lastNoticeAt: 0,
    lastAmbientSide: "right",
    lastAmbientLeftZone: "lower",
    renderPaused: false,
    lastFrameAt: 0,
    lastLabelAt: 0,
    timers: []
};

function randomBetween(min, max) {
    return min + Math.random() * Math.max(0, max - min);
}
function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}
function number(value, fallback = 0) {
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
}
function safePercent(value) {
    const n = Number(value);
    return Number.isFinite(n) ? `${Math.round(n)}%` : "?";
}
function showError(message) {
    console.error(message);
    if (!dom.error) return;
    dom.error.textContent = String(message);
    dom.error.classList.add("is-visible");
    window.setTimeout(() => dom.error.classList.remove("is-visible"), 6500);
}

let lastWarzoneStatus = null;
let intelligenceRefreshFailed = false;

function updateFeedStatus(warzone = lastWarzoneStatus, failed = intelligenceRefreshFailed) {
    lastWarzoneStatus = warzone;
    intelligenceRefreshFailed = failed;
    let element = document.getElementById("warFeedStatus");
    if (!element) {
        element = document.createElement("div");
        element.id = "warFeedStatus";
        element.className = "war-feed-status";
        element.setAttribute("role", "status");
        dom.overlay.appendChild(element);
    }
    const source = warzone?.source_mode === "eve_online_frontlines" ? "CCP" :
        warzone?.source_mode === "everef_fallback" ? "EVE Ref backup" : "Source unavailable";
    const successAt = Date.parse(warzone?.last_success_at || "");
    const ageMinutes = Number.isFinite(successAt) ? Math.max(0, Math.floor((Date.now() - successAt) / 60000)) : null;
    const stale = failed || warzone?.stale === true || (ageMinutes !== null && ageMinutes >= 20);
    const state = !warzone ? "Unavailable" : stale ? "Saved data" : "Current";
    element.textContent = `Insurgency: ${source} · ${state}` +
        (ageMinutes !== null ? ` · Checked ${ageMinutes < 1 ? "just now" : `${ageMinutes}m ago`}` : "");
    element.classList.toggle("is-warning", stale || !warzone || warzone?.source_mode === "everef_fallback");
    element.title = failed ? "Intelligence refresh failed. Displayed data has not been refreshed." :
        warzone?.stale ? "The source could not be refreshed. Showing the last saved response." :
        warzone?.source_mode === "everef_fallback" ? "The primary source is unavailable. Using the EVE Ref mirror." :
        "Time of the last successful source check; game data may update on a different schedule.";
}

function ensurePanelTitle(id, className, options) {
    let element = document.getElementById(id);
    if (!element) {
        element = document.createElement("div");
        element.id = id;
        element.className = `map-panel-title ${className}`;
        element.innerHTML = `<span class="map-panel-kicker"></span><strong class="map-panel-name"></strong>`;
        dom.labelLayer.appendChild(element);
    }
    const x = number(options?.x);
    const y = number(options?.y);
    const right = options && options.right !== undefined ? number(options.right) : null;
    element.style.top = `${y}px`;
    if (right !== null && Number.isFinite(right)) {
        element.style.left = "";
        element.style.right = `${right}px`;
    } else {
        element.style.right = "";
        element.style.left = `${x}px`;
    }
    return element;
}

function warzoneRegionNames(warzone) {
    const direct = Array.isArray(warzone?.regions) ? warzone.regions : [];
    const fromSystems = Array.isArray(warzone?.systems)
        ? warzone.systems.map(system => system.region_name).filter(Boolean)
        : [];
    const names = Array.from(new Set([...direct, ...fromSystems].map(value => String(value).trim()).filter(Boolean)));
    return names;
}

function updatePanelTitles(intel) {
    const edgeInset = 64;
    const topInset = 62;
    const warTitle = ensurePanelTitle("warPanelTitle", "war", {
        x: VIEW.war.x + edgeInset,
        y: VIEW.war.y + topInset
    });
    const venalTitle = ensurePanelTitle("venalPanelTitle", "venal", {
        right: DESIGN.width - (VIEW.venal.x + VIEW.venal.width - edgeInset),
        y: VIEW.venal.y + topInset
    });

    const regions = warzoneRegionNames(intel?.warzone);
    warTitle.querySelector(".map-panel-kicker").textContent = regions.length ? regions.join(" // ").toUpperCase() : "CURRENT THEATER";
    warTitle.querySelector(".map-panel-name").textContent = "GURISTAS INSURGENCY";

    venalTitle.querySelector(".map-panel-kicker").textContent = "REGIONAL MAP";
    venalTitle.querySelector(".map-panel-name").textContent = "VENAL";
}

function updateScale() {
    const scale = Math.min(window.innerWidth / DESIGN.width, window.innerHeight / DESIGN.height);
    dom.overlay.style.transform = `scale(${scale})`;
}

function viewBottom(view) {
    return DESIGN.height - view.y - view.height;
}

function activityIntensity(value, maximum) {
    const v = Math.max(0, number(value));
    const max = Math.max(0, number(maximum));
    if (v <= 0 || max <= 0) return 0;
    const relative = clamp(Math.log1p(v) / Math.log1p(max), 0, 1);
    const absolute = 1 - Math.exp(-v / 7);
    return clamp(relative * .35 + absolute * .65, 0, 1);
}

class RotatingMap {
    constructor(name, view, span, rotationSpeed, initialRotationZ = 0) {
        this.name = name;
        this.view = view;
        this.span = span;
        this.rotationSpeed = rotationSpeed;
        this.scene = new THREE.Scene();
        this.scene.background = null;
        this.group = new THREE.Group();
        this.group.rotation.z = initialRotationZ;
        this.scene.add(this.group);
        this.camera = new THREE.PerspectiveCamera(44, view.width / view.height, .1, 3000);
        this.positions = new Map();
        this.labels = new Map();
        this.screenPositions = new Map();
        this.signature = "";
        this.nodeMesh = null;
        this.threatMesh = null;
        this.systems = [];
        this.maximumKills = 0;
        this.matrix = new THREE.Matrix4();
        this.scaleVector = new THREE.Vector3();
        this.quaternion = new THREE.Quaternion();
        this.tempVector = new THREE.Vector3();
        this.lastRotationDelta = 0;
    }

    disposeGroup() {
        for (const label of this.labels.values()) label.element.remove();
        this.labels.clear();
        this.screenPositions.clear();
        while (this.group.children.length) {
            const object = this.group.children.pop();
            if (object.geometry) object.geometry.dispose();
            if (object.material) {
                if (Array.isArray(object.material)) object.material.forEach(material => material.dispose());
                else object.material.dispose();
            }
        }
        this.positions.clear();
        this.nodeMesh = null;
        this.threatMesh = null;
    }

    normalize(systems) {
        const raw = systems.map(system => new THREE.Vector3(
            number(system.position?.x),
            number(system.position?.y),
            -number(system.position?.z)
        ));
        if (!raw.length) return;
        const box = new THREE.Box3().setFromPoints(raw);
        const center = box.getCenter(new THREE.Vector3());
        const size = box.getSize(new THREE.Vector3());
        const largest = Math.max(size.x, size.y, size.z) || 1;
        const scale = this.span / largest;
        systems.forEach((system, index) => {
            this.positions.set(Number(system.id), raw[index].sub(center).multiplyScalar(scale));
        });
    }

    fitCamera(padding = 1.20) {
        const vertical = THREE.MathUtils.degToRad(this.camera.fov);
        const horizontal = 2 * Math.atan(Math.tan(vertical / 2) * this.camera.aspect);
        const limiting = Math.min(vertical, horizontal);
        const distance = (this.span * .58 * padding) / Math.tan(limiting / 2);
        this.camera.position.set(0, this.span * .12, distance);
        this.camera.lookAt(0, 0, 0);
        this.camera.updateProjectionMatrix();
    }

    createBaseLabel(system, className) {
        const element = document.createElement("div");
        element.className = `map-label ${className}`;
        element.hidden = true;
        // Cheap DOM depth layer: a dark backplate + restrained color halo that
        // follows the projected node. This improves readability over webcam/video
        // without adding WebGL post-processing, blur passes, or another render loop.
        const depth = document.createElement("span");
        depth.className = "node-depth";
        depth.setAttribute("aria-hidden", "true");

        const name = document.createElement("span");
        name.className = "system-name";
        name.textContent = String(system.name || system.id);
        element.append(depth, name);
        if (system.name === "H-PA29") element.classList.add("is-home");
        if (system.is_fob) element.classList.add("is-fob");
        dom.labelLayer.appendChild(element);
        this.labels.set(Number(system.id), { element, name, depth, systemId: Number(system.id) });
        return element;
    }

    pulse(systemId, color = "#ff6a00") {
        const entry = this.labels.get(Number(systemId));
        if (!entry) return;
        entry.element.style.setProperty("--pulse-color", color);
        entry.element.classList.remove("is-pulsing");
        void entry.element.offsetWidth;
        entry.element.classList.add("is-pulsing");
        window.setTimeout(() => entry.element.classList.remove("is-pulsing"), 3800);
    }

    rotate(deltaSeconds) {
        this.group.rotation.y += this.rotationSpeed * Math.min(deltaSeconds, .1);
    }

    updateLabelPositions() {
        this.scene.updateMatrixWorld(true);
        for (const [id, entry] of this.labels.entries()) {
            const base = this.positions.get(id);
            if (!base) continue;
            this.tempVector.copy(base).applyMatrix4(this.group.matrixWorld).project(this.camera);
            const visible = this.tempVector.z >= -1 && this.tempVector.z <= 1;
            const x = this.view.x + (this.tempVector.x + 1) * .5 * this.view.width;
            const y = this.view.y + (1 - this.tempVector.y) * .5 * this.view.height;
            const inBounds = visible
                && x >= this.view.x - 24 && x <= this.view.x + this.view.width + 24
                && y >= this.view.y - 24 && y <= this.view.y + this.view.height + 24;
            entry.element.hidden = !inBounds;
            if (inBounds) {
                entry.element.style.transform = `translate3d(${x.toFixed(1)}px,${y.toFixed(1)}px,0) translate(-50%,-50%)`;
                this.screenPositions.set(id, { x, y });
            } else {
                this.screenPositions.delete(id);
            }
        }
    }

}

class VenalMap extends RotatingMap {
    constructor() {
        // Venal is naturally wider than tall in its default projection. A fixed
        // 90-degree roll makes the region match the tall 640x1080 stream column.
        // The normal Y-axis auto-rotation is preserved after this roll.
        super("venal", VIEW.venal, 180, .052, Math.PI / 2);
    }

    fitCamera(padding = 1.08) {
        // With the 90-degree roll, screen width is primarily the original Y
        // dimension while auto-rotation mixes X/Z into the tall screen axis.
        // Fit against those worst-case extents so Venal can occupy much more of
        // its portrait viewport without clipping as it rotates.
        const vertical = THREE.MathUtils.degToRad(this.camera.fov);
        const horizontal = 2 * Math.atan(Math.tan(vertical / 2) * this.camera.aspect);
        let halfWidth = 1;
        let halfHeight = 1;
        let depth = 0;
        for (const p of this.positions.values()) {
            halfWidth = Math.max(halfWidth, Math.abs(p.y));
            const radialXZ = Math.hypot(p.x, p.z);
            halfHeight = Math.max(halfHeight, radialXZ);
            depth = Math.max(depth, radialXZ);
        }
        const widthDistance = halfWidth / Math.tan(horizontal / 2);
        const heightDistance = halfHeight / Math.tan(vertical / 2);
        const distance = Math.max(widthDistance, heightDistance) * padding + depth * .28;
        this.camera.position.set(0, 0, Math.max(distance, 1));
        this.camera.lookAt(0, 0, 0);
        this.camera.updateProjectionMatrix();
    }

    build(data) {
        this.disposeGroup();
        const systems = Array.isArray(data.systems) ? data.systems : [];
        const edges = Array.isArray(data.edges) ? data.edges : [];
        this.systems = systems;
        this.signature = systems.map(row => Number(row.id)).sort((a,b) => a-b).join(",");
        this.normalize(systems);
        this.maximumKills = Math.max(0, ...systems.map(row => number(row.ship_kills)));

        const geometry = new THREE.SphereGeometry(1, 10, 7);
        const material = new THREE.MeshBasicMaterial({ color: 0xffffff });
        this.nodeMesh = new THREE.InstancedMesh(geometry, material, systems.length);
        this.nodeMesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
        this.group.add(this.nodeMesh);

        systems.forEach((system, index) => {
            const id = Number(system.id);
            const element = this.createBaseLabel(system, "venal-label");
            const badge = document.createElement("span");
            badge.className = "kill-badge";
            badge.hidden = true;
            element.appendChild(badge);
            this.labels.get(id).badge = badge;
            this.labels.get(id).index = index;
        });

        this.buildGates(edges);
        this.update(data);
        this.fitCamera(1.06);
    }

    buildGates(edges) {
        const solid = [];
        const dashed = [];
        for (const edge of edges) {
            const a = this.positions.get(Number(edge.a));
            const b = this.positions.get(Number(edge.b));
            if (!a || !b) continue;
            const target = edge.type === "cross_constellation" ? dashed : solid;
            target.push(a.x,a.y,a.z,b.x,b.y,b.z);
        }
        if (solid.length) {
            const geometry = new THREE.BufferGeometry();
            geometry.setAttribute("position", new THREE.Float32BufferAttribute(solid, 3));
            const material = new THREE.LineBasicMaterial({ color: 0xff6a00, transparent: true, opacity: .78 });
            this.group.add(new THREE.LineSegments(geometry, material));
        }
        if (dashed.length) {
            const geometry = new THREE.BufferGeometry();
            geometry.setAttribute("position", new THREE.Float32BufferAttribute(dashed, 3));
            const material = new THREE.LineDashedMaterial({ color: 0xff6a00, transparent: true, opacity: .88, dashSize: 3.2, gapSize: 2.1 });
            const lines = new THREE.LineSegments(geometry, material);
            lines.computeLineDistances();
            this.group.add(lines);
        }
    }

    update(data) {
        if (!this.nodeMesh) return;
        const systems = Array.isArray(data.systems) ? data.systems : [];
        this.maximumKills = Math.max(0, ...systems.map(row => number(row.ship_kills)));
        for (const system of systems) {
            const id = Number(system.id);
            const label = this.labels.get(id);
            const position = this.positions.get(id);
            if (!label || !position) continue;
            const kills = Math.max(0, number(system.ship_kills));
            const intensity = activityIntensity(kills, this.maximumKills || 1);
            const securityDepth = clamp(Math.abs(number(system.security)), 0, 1);
            const radius = (.78 + securityDepth * .22) * (kills > 0 ? 1.08 : 1);
            const color = COLORS.orange.clone();
            this.scaleVector.setScalar(radius);
            this.matrix.compose(position, this.quaternion, this.scaleVector);
            this.nodeMesh.setMatrixAt(label.index, this.matrix);
            this.nodeMesh.setColorAt(label.index, color);

            label.badge.hidden = kills <= 0;
            label.badge.classList.toggle("is-active", kills > 0);
            label.badge.style.setProperty("--kill-color", COLOR_HEX.guristas);
            label.element.style.setProperty("--node-glow-rgb", "255, 106, 0");
            if (kills > 0) {
                const killSize = Math.round(18 + intensity * 10);
                label.badge.textContent = String(kills);
                label.badge.style.setProperty("--kill-size", `${killSize}px`);
                label.element.style.setProperty("--node-depth-size", `${killSize + 8}px`);
                label.baseOffsetX = 7;
                label.baseOffsetY = -(Math.ceil(killSize / 2) + 8);
                label.priority = 100 + kills + (system.name === "H-PA29" ? 20 : 0) + (system.is_fob ? 12 : 0);
                label.name.style.setProperty("--label-offset-x", `${label.baseOffsetX}px`);
                label.name.style.setProperty("--label-offset-y", `${label.baseOffsetY}px`);
            } else {
                label.element.style.setProperty("--node-depth-size", "15px");
                label.baseOffsetX = 8;
                label.baseOffsetY = -13;
                label.priority = (system.name === "H-PA29" ? 40 : 0) + (system.is_fob ? 22 : 0);
                label.name.style.setProperty("--label-offset-x", `${label.baseOffsetX}px`);
                label.name.style.setProperty("--label-offset-y", `${label.baseOffsetY}px`);
            }
        }
        this.nodeMesh.instanceMatrix.needsUpdate = true;
        if (this.nodeMesh.instanceColor) this.nodeMesh.instanceColor.needsUpdate = true;
    }
}

class WarMap extends RotatingMap {
    constructor() {
        super("war", WAR_MAP_VIEW, 175, .050);
        // The viewport itself now supplies most of the downward placement.
        // A small additional world-space offset keeps the network visually
        // weighted toward the lower half without approaching the Fulcrum mark.
        this.group.position.y = -10;
    }

    build(data) {
        this.disposeGroup();
        const systems = Array.isArray(data.systems) ? data.systems : [];
        const edges = Array.isArray(data.edges) ? data.edges : [];
        this.systems = systems;
        this.signature = systems.map(row => Number(row.id)).sort((a,b) => a-b).join(",");
        this.normalize(systems);
        this.maximumKills = Math.max(0, ...systems.map(row => number(row.ship_kills)));

        const coreGeometry = new THREE.SphereGeometry(1, 10, 7);
        const coreMaterial = new THREE.MeshBasicMaterial({ color: 0xffffff });
        this.nodeMesh = new THREE.InstancedMesh(coreGeometry, coreMaterial, systems.length);
        this.nodeMesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
        this.group.add(this.nodeMesh);

        const threatGeometry = new THREE.SphereGeometry(1.25, 7, 5);
        const threatMaterial = new THREE.MeshBasicMaterial({ color: 0xff2739, wireframe: true, transparent: true, opacity: .68 });
        this.threatMesh = new THREE.InstancedMesh(threatGeometry, threatMaterial, systems.length);
        this.threatMesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
        this.group.add(this.threatMesh);

        systems.forEach((system, index) => {
            const id = Number(system.id);
            const element = this.createBaseLabel(system, "war-label");
            const stateRing = document.createElement("span");
            stateRing.className = "war-state-ring";
            const kills = document.createElement("span");
            kills.className = "war-kills";
            kills.hidden = true;
            element.append(stateRing, kills);
            Object.assign(this.labels.get(id), { index, stateRing, kills });
        });

        const solid = [];
        const dashed = [];
        for (const edge of edges) {
            const a = this.positions.get(Number(edge.a));
            const b = this.positions.get(Number(edge.b));
            if (!a || !b) continue;
            const target = edge.type === "cross_constellation" ? dashed : solid;
            target.push(a.x, a.y, a.z, b.x, b.y, b.z);
        }
        if (solid.length) {
            const geometry = new THREE.BufferGeometry();
            geometry.setAttribute("position", new THREE.Float32BufferAttribute(solid, 3));
            const material = new THREE.LineBasicMaterial({ color: 0xff6a00, transparent: true, opacity: .78 });
            this.group.add(new THREE.LineSegments(geometry, material));
        }
        if (dashed.length) {
            const geometry = new THREE.BufferGeometry();
            geometry.setAttribute("position", new THREE.Float32BufferAttribute(dashed, 3));
            const material = new THREE.LineDashedMaterial({ color: 0xff6a00, transparent: true, opacity: .88, dashSize: 3.2, gapSize: 2.1 });
            const lines = new THREE.LineSegments(geometry, material);
            lines.computeLineDistances();
            this.group.add(lines);
        }

        this.update(data);
        this.fitCamera(1.06);
    }

    winnerColor(winner) {
        if (winner === "guristas") return COLORS.orange;
        if (winner === "caldari") return COLORS.cyan;
        if (winner === "gallente") return COLORS.green;
        return COLORS.white;
    }

    update(data) {
        if (!this.nodeMesh || !this.threatMesh) return;
        const systems = Array.isArray(data.systems) ? data.systems : [];
        this.maximumKills = Math.max(0, ...systems.map(row => number(row.ship_kills)));
        for (const system of systems) {
            const id = Number(system.id);
            const label = this.labels.get(id);
            const position = this.positions.get(id);
            if (!label || !position) continue;
            const kills = Math.max(0, number(system.ship_kills));
            const threat = activityIntensity(kills, this.maximumKills || 1);
            const progress = Math.max(number(system.corruption_percent), number(system.suppression_percent)) / 100;
            const active = kills > 0;
            const coreRadius = ((.82 + clamp(progress,0,1) * .18) * (system.is_fob ? 1.14 : 1)) * (active ? 1.03 : 1);
            const coreColor = this.winnerColor(String(system.winner || "neutral"));
            this.scaleVector.setScalar(coreRadius);
            this.matrix.compose(position, this.quaternion, this.scaleVector);
            this.nodeMesh.setMatrixAt(label.index, this.matrix);
            this.nodeMesh.setColorAt(label.index, coreColor);

            const threatRadius = active ? coreRadius * (1.14 + threat * .32) : .001;
            this.scaleVector.setScalar(threatRadius);
            this.matrix.compose(position, this.quaternion, this.scaleVector);
            this.threatMesh.setMatrixAt(label.index, this.matrix);

            const stateHex = COLOR_HEX[system.winner] || COLOR_HEX.neutral;
            const stateSize = Math.round(18 + clamp(progress,0,1) * 6 + threat * 4);
            label.stateRing.hidden = !active;
            label.stateRing.style.setProperty("--state-color", stateHex);
            // Put the state size on the parent label so BOTH the colored ring
            // and the sibling kill-count disk inherit the exact same diameter.
            // Previously the variable lived only on .war-state-ring, so
            // .war-kills fell back to its default size and looked too small.
            label.element.style.setProperty("--state-size", `${stateSize}px`);
            label.stateRing.style.setProperty("--state-size", `${stateSize}px`);
            label.element.style.setProperty("--node-depth-size", `${active ? stateSize + 8 : 15}px`);
            label.element.style.setProperty("--node-glow-rgb", `${Math.round(coreColor.r * 255)}, ${Math.round(coreColor.g * 255)}, ${Math.round(coreColor.b * 255)}`);
            label.kills.hidden = !active;
            if (active) {
                label.kills.textContent = String(kills);
                label.baseOffsetX = 6;
                label.baseOffsetY = -(Math.ceil(stateSize / 2) + 8);
                label.priority = 100 + kills + (system.is_fob ? 14 : 0);
                label.name.style.setProperty("--label-offset-x", `${label.baseOffsetX}px`);
                label.name.style.setProperty("--label-offset-y", `${label.baseOffsetY}px`);
            } else {
                label.baseOffsetX = 7;
                label.baseOffsetY = -13;
                label.priority = system.is_fob ? 24 : 0;
                label.name.style.setProperty("--label-offset-x", `${label.baseOffsetX}px`);
                label.name.style.setProperty("--label-offset-y", `${label.baseOffsetY}px`);
            }
        }
        this.nodeMesh.instanceMatrix.needsUpdate = true;
        this.threatMesh.instanceMatrix.needsUpdate = true;
        if (this.nodeMesh.instanceColor) this.nodeMesh.instanceColor.needsUpdate = true;
    }
}

const venalMap = new VenalMap();
const warMap = new WarMap();

let renderer = null;
let clock = null;

function createRenderer() {
    renderer = new THREE.WebGLRenderer({
        canvas: dom.canvas,
        alpha: true,
        antialias: true,
        premultipliedAlpha: true,
        powerPreference: "high-performance"
    });
    renderer.setClearColor(0x000000, 0);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, number(app.settings.pixel_ratio_cap, 1.15)));
    renderer.setSize(DESIGN.width, DESIGN.height, false);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.autoClear = false;
    renderer.sortObjects = false;
    renderer.setScissorTest(true);
    clock = new THREE.Clock();
}

function renderView(map) {
    const view = map.view;
    renderer.setViewport(view.x, viewBottom(view), view.width, view.height);
    renderer.setScissor(view.x, viewBottom(view), view.width, view.height);
    renderer.render(map.scene, map.camera);
}

function animationFrame(timestamp) {
    window.requestAnimationFrame(animationFrame);
    if (app.renderPaused || !renderer) return;
    const fps = Math.max(10, number(app.settings.render_fps, 24));
    const frameInterval = 1000 / fps;
    if (timestamp - app.lastFrameAt < frameInterval) return;
    const delta = clock.getDelta();
    app.lastFrameAt = timestamp;

    venalMap.rotate(delta);
    warMap.rotate(delta);

    renderer.setScissorTest(false);
    renderer.clear(true, true, true);
    renderer.setScissorTest(true);
    renderView(warMap);
    renderView(venalMap);

    const labelFps = Math.max(4, number(app.settings.label_fps, 12));
    if (timestamp - app.lastLabelAt >= 1000 / labelFps) {
        app.lastLabelAt = timestamp;
        warMap.updateLabelPositions();
        venalMap.updateLabelPositions();
    }
}

function updateZarzakh(zarzakh) {
    const kills = Math.max(0, number(zarzakh?.ship_kills));
    dom.zarzakhCore.classList.toggle("has-kills", kills > 0);
    dom.zarzakhCore.dataset.kills = String(kills);
    dom.zarzakhKills.textContent = "SHIP KILLS // 1H";
}

function mapById(rows) {
    const result = new Map();
    for (const row of rows || []) result.set(Number(row.id), row);
    return result;
}

function winnerText(winner) {
    if (winner === "guristas") return "Guristas corruption";
    if (winner === "caldari") return "Caldari suppression";
    if (winner === "gallente") return "Gallente suppression";
    return "no side";
}

function priorityScore(priority) {
    return { critical: 4, priority: 3, info: 2, ambient: 1 }[priority] || 1;
}

function queueEvent(event) {
    const now = Date.now();
    const key = event.key || `${event.type}:${event.title}:${event.meta}`;
    if (app.eventQueue.some(item => item.key === key)) return;
    app.eventQueue.push({ ...event, key, createdAt: now, score: priorityScore(event.priority) });
    app.eventQueue.sort((a,b) => b.score - a.score || a.createdAt - b.createdAt);
    if (app.eventQueue.length > 12) app.eventQueue.length = 12;
}

function pulseForEvent(event) {
    if (!event.anchor) return;
    if (event.anchor.map === "war") warMap.pulse(event.anchor.systemId, event.pulseColor || COLOR_HEX.guristas);
    if (event.anchor.map === "venal") venalMap.pulse(event.anchor.systemId, event.pulseColor || COLOR_HEX.threat);
    if (event.anchor.map === "zarzakh") {
        dom.zarzakhMark.animate(
            [{ transform: "scale(1)" }, { transform: "scale(1.09)" }, { transform: "scale(1)" }],
            { duration: 900, iterations: 2, easing: "ease-out" }
        );
    }
}

function compareWar(previousRows, nextRows) {
    const oldMap = mapById(previousRows);
    const newMap = mapById(nextRows);
    const structural = [];
    const percentEvents = [];
    const killEvents = [];
    const changedIds = new Set();
    const threshold = number(app.settings.war_change_percent_threshold, 2.5);

    for (const [id, next] of newMap.entries()) {
        const old = oldMap.get(id);
        if (!old) {
            structural.push({
                key: `war-add-${id}`,
                type: "warzone",
                title: "INSURGENCY EXPANDS",
                badge: "NEW SYSTEM",
                body: `${next.name} has appeared on the Guristas operational grid.`,
                meta: `${next.name} // LIVE`,
                priority: "critical",
                anchor: { map: "war", systemId: id },
                pulseColor: COLOR_HEX.guristas
            });
            changedIds.add(id);
            continue;
        }

        if (String(old.winner) !== String(next.winner)) {
            const winner = String(next.winner || "neutral");
            structural.push({
                key: `war-winner-${id}-${winner}`,
                type: "warzone",
                title: winner === "guristas" ? "CORRUPTION ADVANTAGE" : winner === "neutral" ? "CONTROL DEADLOCK" : "SUPPRESSION ADVANTAGE",
                badge: "CONTROL SHIFT",
                body: `${winnerText(winner)} now leads in ${next.name}: ${safePercent(next.corruption_percent)} corruption vs ${safePercent(next.suppression_percent)} suppression.`,
                meta: `${next.name} // STATE CHANGE`,
                priority: "critical",
                anchor: { map: "war", systemId: id },
                pulseColor: COLOR_HEX[winner] || COLOR_HEX.neutral
            });
            changedIds.add(id);
        }

        const oldCStage = number(old.corruption_stage, -1);
        const nextCStage = number(next.corruption_stage, -1);
        const oldSStage = number(old.suppression_stage, -1);
        const nextSStage = number(next.suppression_stage, -1);
        if (oldCStage !== nextCStage && nextCStage >= 0) {
            structural.push({
                key: `war-cstage-${id}-${nextCStage}`,
                type: "warzone",
                title: "CORRUPTION STAGE CHANGE",
                badge: `C${nextCStage}`,
                body: `${next.name} corruption has moved from stage ${oldCStage} to stage ${nextCStage}.`,
                meta: `${next.name} // CORRUPTION`,
                priority: "priority",
                anchor: { map: "war", systemId: id },
                pulseColor: COLOR_HEX.guristas
            });
            changedIds.add(id);
        }
        if (oldSStage !== nextSStage && nextSStage >= 0) {
            const side = String(next.empire_side || "neutral");
            structural.push({
                key: `war-sstage-${id}-${nextSStage}`,
                type: "warzone",
                title: "SUPPRESSION STAGE CHANGE",
                badge: `S${nextSStage}`,
                body: `${next.name} suppression has moved from stage ${oldSStage} to stage ${nextSStage}.`,
                meta: `${next.name} // SUPPRESSION`,
                priority: "priority",
                anchor: { map: "war", systemId: id },
                pulseColor: COLOR_HEX[side] || COLOR_HEX.neutral
            });
            changedIds.add(id);
        }

        const corruptionDelta = number(next.corruption_percent) - number(old.corruption_percent);
        const suppressionDelta = number(next.suppression_percent) - number(old.suppression_percent);
        const largestDelta = Math.max(Math.abs(corruptionDelta), Math.abs(suppressionDelta));
        if (largestDelta >= threshold) {
            const corruptionWinsDelta = Math.abs(corruptionDelta) >= Math.abs(suppressionDelta);
            percentEvents.push({
                magnitude: largestDelta,
                event: {
                    key: `war-progress-${id}-${Math.round(number(next.corruption_percent))}-${Math.round(number(next.suppression_percent))}`,
                    type: "warzone",
                    title: corruptionWinsDelta ? (corruptionDelta >= 0 ? "CORRUPTION CLIMBING" : "CORRUPTION FALLING") : (suppressionDelta >= 0 ? "SUPPRESSION CLIMBING" : "SUPPRESSION FALLING"),
                    badge: "LIVE WARZONE",
                    body: `${next.name}: corruption ${safePercent(next.corruption_percent)}, suppression ${safePercent(next.suppression_percent)}.`,
                    meta: `${next.name} // ${largestDelta.toFixed(1)}-POINT SHIFT`,
                    priority: "info",
                    anchor: { map: "war", systemId: id },
                    pulseColor: corruptionWinsDelta ? COLOR_HEX.guristas : (COLOR_HEX[next.empire_side] || COLOR_HEX.neutral)
                }
            });
            changedIds.add(id);
        }

        const oldKills = number(old.ship_kills);
        const nextKills = number(next.ship_kills);
        const deltaKills = nextKills - oldKills;
        if (deltaKills >= number(app.settings.kill_spike_delta, 3)) {
            killEvents.push({
                magnitude: deltaKills,
                event: {
                    key: `war-kills-${id}-${nextKills}`,
                    type: "kill-report",
                    title: "SHIP LOSSES RISING",
                    badge: "1H COMBAT WINDOW",
                    body: `Last-hour ship losses in ${next.name} increased from ${oldKills} to ${nextKills}.`,
                    meta: `${next.name} // +${deltaKills} ON REFRESH`,
                    priority: nextKills >= 10 ? "priority" : "info",
                    anchor: { map: "war", systemId: id },
                    pulseColor: COLOR_HEX.threat
                }
            });
            changedIds.add(id);
        }
    }

    for (const [id, old] of oldMap.entries()) {
        if (!newMap.has(id)) {
            structural.push({
                key: `war-remove-${id}`,
                type: "warzone",
                title: "SYSTEM DROPPED FROM GRID",
                badge: "WARZONE UPDATE",
                body: `${old.name} is no longer present in the current Guristas insurgency set.`,
                meta: `${old.name} // TOPOLOGY CHANGE`,
                priority: "priority",
                anchor: { map: "war" },
                pulseColor: COLOR_HEX.neutral
            });
        }
    }

    // Pulse every changed system, but only queue the most informative progress
    // notices so a single hourly refresh cannot flood the stream for minutes.
    for (const id of changedIds) {
        const row = newMap.get(id);
        const color = row ? (COLOR_HEX[row.winner] || COLOR_HEX.guristas) : COLOR_HEX.guristas;
        warMap.pulse(id, color);
    }

    percentEvents.sort((a,b) => b.magnitude - a.magnitude);
    killEvents.sort((a,b) => b.magnitude - a.magnitude);
    return [...structural.slice(0,5), ...percentEvents.slice(0,3).map(x => x.event), ...killEvents.slice(0,2).map(x => x.event)].slice(0,8);
}

function compareVenal(previousRows, nextRows) {
    const oldMap = mapById(previousRows);
    const events = [];
    for (const next of nextRows || []) {
        const id = Number(next.id);
        const old = oldMap.get(id);
        if (!old) continue;
        const oldKills = number(old.ship_kills);
        const nextKills = number(next.ship_kills);
        const delta = nextKills - oldKills;
        if (nextKills >= number(app.settings.venal_kill_event_minimum, 2) && delta >= number(app.settings.kill_spike_delta, 3)) {
            events.push({
                magnitude: delta,
                event: {
                    key: `venal-kills-${id}-${nextKills}`,
                    type: "venal",
                    title: "VENAL COMBAT SPIKE",
                    badge: "1H SHIP LOSSES",
                    body: `${next.name} rose from ${oldKills} to ${nextKills} ship losses in the current one-hour window.`,
                    meta: `${next.name} // +${delta} ON REFRESH`,
                    priority: nextKills >= 10 ? "priority" : "info",
                    anchor: { map: "venal", systemId: id },
                    pulseColor: COLOR_HEX.threat
                }
            });
            venalMap.pulse(id, COLOR_HEX.threat);
        }
    }
    events.sort((a,b) => b.magnitude - a.magnitude);
    return events.slice(0,2).map(x => x.event);
}

function compareZarzakh(previous, next) {
    const oldKills = number(previous?.ship_kills);
    const nextKills = number(next?.ship_kills);
    const delta = nextKills - oldKills;
    if (delta >= number(app.settings.kill_spike_delta, 3)) {
        return [{
            key: `zarzakh-kills-${nextKills}`,
            type: "fulcrum",
            title: "FULCRUM TRAFFIC VIOLENCE",
            badge: "ZARZAKH",
            body: `Last-hour ship losses in Zarzakh increased from ${oldKills} to ${nextKills}.`,
            meta: `THE FULCRUM // +${delta} ON REFRESH`,
            priority: nextKills >= 8 ? "priority" : "info",
            anchor: { map: "zarzakh" },
            pulseColor: COLOR_HEX.threat
        }];
    }
    return [];
}

function compareIntel(previous, next) {
    if (!previous || !next) return;
    const events = [
        ...compareWar(previous.warzone?.systems, next.warzone?.systems),
        ...compareVenal(previous.venal?.systems, next.venal?.systems),
        ...compareZarzakh(previous.zarzakh, next.zarzakh)
    ];
    for (const event of events) {
        pulseForEvent(event);
        queueEvent(event);
    }
}

async function fetchJson(url) {
    const response = await fetch(url, { cache: "no-store", headers: { Accept: "application/json" } });
    const payload = await response.json();
    if (!response.ok || !payload?.ok) throw new Error(payload?.error?.message || payload?.error || `HTTP ${response.status}`);
    return payload;
}

async function refreshIntel() {
    if (app.refreshingIntel) return;
    app.refreshingIntel = true;
    try {
        const payload = await fetchJson(INTEL_URL);
        const next = payload.data;
        updateFeedStatus(next.warzone, false);
        const warSignature = (next.warzone?.systems || []).map(row => Number(row.id)).sort((a,b) => a-b).join(",");
        const venalSignature = (next.venal?.systems || []).map(row => Number(row.id)).sort((a,b) => a-b).join(",");

        if (!warMap.nodeMesh || warSignature !== warMap.signature) warMap.build(next.warzone || {});
        else warMap.update(next.warzone || {});
        if (!venalMap.nodeMesh || venalSignature !== venalMap.signature) venalMap.build(next.venal || {});
        else venalMap.update(next.venal || {});
        updateZarzakh(next.zarzakh || {});
        updatePanelTitles(next);

        compareIntel(app.previousIntel, next);
        app.previousIntel = typeof structuredClone === "function" ? structuredClone(next) : JSON.parse(JSON.stringify(next));
    } catch (error) {
        updateFeedStatus(lastWarzoneStatus, true);
        showError(`Stream intelligence refresh failed: ${error.message}`);
    } finally {
        app.refreshingIntel = false;
    }
}

function rebuildTypeIndex() {
    app.typeById = new Map(app.types.map(type => [String(type.id), type]));
}

async function refreshMessages(initial = false) {
    try {
        const payload = await fetchJson(MESSAGE_URL);
        app.types = Array.isArray(payload.data?.types) ? payload.data.types : [];
        app.messages = Array.isArray(payload.data?.messages) ? payload.data.messages : [];
        app.settings = { ...DEFAULT_SETTINGS, ...(payload.data?.settings || {}) };
        rebuildTypeIndex();
        if (renderer) renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, number(app.settings.pixel_ratio_cap, 1.15)));
        if (initial) app.nextAmbientAt = Date.now() + 1200;
    } catch (error) {
        console.warn("Unable to refresh stream message configuration.", error);
        if (initial) app.nextAmbientAt = Date.now() + 1200;
    }
}

function typeFor(id) {
    return app.typeById.get(String(id)) || app.typeById.get("system") || fallbackType();
}

function randomAmbientMessage() {
    const now = Date.now();
    const recent = new Set(app.recentMessageIds);
    let candidates = app.messages.filter(message => {
        if (!message.enabled) return false;
        if (recent.has(String(message.id))) return false;
        const type = typeFor(message.type);
        if (!type.enabled) return false;
        const last = app.lastTypeShown.get(type.id) || 0;
        const cooldownMs = Math.max(0, number(type.cooldown_seconds)) * 1000;
        return now - last >= cooldownMs;
    });
    if (!candidates.length) candidates = app.messages.filter(message => message.enabled && typeFor(message.type).enabled);
    if (!candidates.length) return null;

    const total = candidates.reduce((sum, message) => sum + Math.max(.05, number(message.weight, 1)), 0);
    let roll = Math.random() * total;
    for (const message of candidates) {
        roll -= Math.max(.05, number(message.weight, 1));
        if (roll <= 0) return message;
    }
    return candidates[candidates.length - 1];
}

function pointForAnchor(anchor) {
    if (!anchor) return null;
    if (anchor.map === "war" && anchor.systemId) return warMap.screenPositions.get(Number(anchor.systemId)) || null;
    if (anchor.map === "venal" && anchor.systemId) return venalMap.screenPositions.get(Number(anchor.systemId)) || null;
    if (anchor.map === "zarzakh") return { x: 320, y: 810 };
    if (anchor.map === "war") return { x: 320, y: 270 };
    if (anchor.map === "venal") return { x: 1600, y: 540 };
    return null;
}

function clampPosition(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

function placeNearAnchor(panel, anchor) {
    const point = pointForAnchor(anchor);
    const map = anchor?.map;
    const bounds = map === "war" ? VIEW.war : map === "zarzakh" ? VIEW.zarzakh : VIEW.venal;
    const width = panel.offsetWidth || 372;
    const height = panel.offsetHeight || 110;
    const margin = 14;
    if (!point) {
        panel.style.left = `${bounds.x + margin}px`;
        panel.style.top = `${bounds.y + margin}px`;
        return;
    }
    let x = point.x + 22;
    if (x + width > bounds.x + bounds.width - margin) x = point.x - width - 22;
    x = clampPosition(x, bounds.x + margin, bounds.x + bounds.width - width - margin);
    let y = point.y - height / 2;
    y = clampPosition(y, bounds.y + margin, bounds.y + bounds.height - height - margin);
    panel.style.left = `${Math.round(x)}px`;
    panel.style.top = `${Math.round(y)}px`;
}

function chooseAmbientBounds(sidePreference) {
    let side = sidePreference;
    if (side !== "left" && side !== "right") {
        side = app.lastAmbientSide === "right" ? "left" : "right";
    }
    app.lastAmbientSide = side;
    if (side === "right") return VIEW.venal;
    const zone = app.lastAmbientLeftZone === "upper" ? "lower" : "upper";
    app.lastAmbientLeftZone = zone;
    return zone === "upper" ? VIEW.war : VIEW.zarzakh;
}

function placeAmbient(panel, message) {
    const bounds = chooseAmbientBounds(String(message.side || "either"));
    const width = panel.offsetWidth || 372;
    const height = panel.offsetHeight || 110;
    const margin = 18;
    const maxX = bounds.x + bounds.width - width - margin;
    const maxY = bounds.y + bounds.height - height - margin;
    const x = Math.random() < .5 ? bounds.x + margin : Math.max(bounds.x + margin, maxX);
    const y = randomBetween(bounds.y + 80, Math.max(bounds.y + 80, maxY - 24));
    panel.style.left = `${Math.round(x)}px`;
    panel.style.top = `${Math.round(y)}px`;
}

function showNotice(message, options = {}) {
    if (!app.settings.notifications_enabled || app.activeNotices >= number(app.settings.max_active, 1)) return false;
    const type = typeFor(message.type);
    const panel = createNoticeElement(message, type);
    const enter = randomBetween(number(app.settings.enter_duration_min_ms, 90), number(app.settings.enter_duration_max_ms, 180));
    const exit = randomBetween(number(app.settings.exit_duration_min_ms, 220), number(app.settings.exit_duration_max_ms, 360));
    const visible = randomBetween(number(app.settings.visible_duration_min_ms, 5600), number(app.settings.visible_duration_max_ms, 8200));
    panel.style.setProperty("--notice-enter-ms", `${enter}ms`);
    panel.style.setProperty("--notice-exit-ms", `${exit}ms`);
    panel.style.setProperty("--notice-scan-ms", `${randomBetween(3900,5400)}ms`);
    dom.notificationLayer.appendChild(panel);
    app.activeNotices++;
    app.lastNoticeAt = Date.now();
    app.lastTypeShown.set(String(type.id || message.type || "system"), Date.now());

    if (options.anchor) placeNearAnchor(panel, options.anchor);
    else placeAmbient(panel, message);

    requestAnimationFrame(() => panel.classList.add("is-visible"));
    window.setTimeout(() => {
        panel.classList.remove("is-visible");
        panel.classList.add("is-hiding");
    }, visible);
    window.setTimeout(() => {
        panel.remove();
        app.activeNotices = Math.max(0, app.activeNotices - 1);
    }, visible + exit + 90);

    app.nextAmbientAt = Date.now() + randomBetween(number(app.settings.ambient_interval_min_ms,8000), number(app.settings.ambient_interval_max_ms,15000));
    return true;
}

function schedulerTick() {
    if (!app.settings.notifications_enabled || app.activeNotices >= number(app.settings.max_active,1)) return;
    const now = Date.now();
    const expiry = number(app.settings.event_expiry_ms, 120000);
    app.eventQueue = app.eventQueue.filter(event => now - event.createdAt <= expiry);

    if (app.eventQueue.length && now - app.lastNoticeAt >= number(app.settings.event_min_gap_ms,5000)) {
        const event = app.eventQueue.shift();
        showNotice(event, { anchor: event.anchor });
        return;
    }

    if (now >= app.nextAmbientAt) {
        const message = randomAmbientMessage();
        if (!message) {
            app.nextAmbientAt = now + 5000;
            return;
        }
        if (showNotice(message)) {
            const id = String(message.id || "");
            if (id) {
                app.recentMessageIds.push(id);
                const memory = Math.max(0, number(app.settings.recent_message_memory,12));
                while (app.recentMessageIds.length > memory) app.recentMessageIds.shift();
            }
        }
    }
}

function startTimers() {
    const schedulerTimer = window.setInterval(schedulerTick, 250);
    app.timers.push(schedulerTimer);

    const scheduleIntel = () => {
        const timer = window.setTimeout(async () => {
            await refreshIntel();
            scheduleIntel();
        }, Math.max(60000, number(app.settings.intel_refresh_ms,300000)));
        app.timers.push(timer);
    };
    const scheduleMessages = () => {
        const timer = window.setTimeout(async () => {
            await refreshMessages();
            scheduleMessages();
        }, Math.max(10000, number(app.settings.message_refresh_ms,60000)));
        app.timers.push(timer);
    };

    scheduleIntel();
    scheduleMessages();
}

async function main() {
    updateScale();
    await refreshMessages(true);
    createRenderer();
    await refreshIntel();
    startTimers();
    requestAnimationFrame(animationFrame);
}

window.addEventListener("resize", updateScale, { passive: true });
document.addEventListener("visibilitychange", () => {
    app.renderPaused = document.hidden;
    if (!document.hidden && clock) clock.getDelta();
});

window.setInterval(() => updateFeedStatus(), 30000);

main().catch(error => showError(`Stream overlay initialization failed: ${error.message}`));
