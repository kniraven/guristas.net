"use strict";

import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import {
    CSS2DRenderer,
    CSS2DObject
} from "three/addons/renderers/CSS2DRenderer.js";

const API_URL = "/api/venal/map.php";
const HISTORY_API_URL = "/api/venal/history.php";

const el = {
    shell: document.querySelector("[data-venal-map-shell]"),
    map: document.querySelector("#map"),
    status: document.querySelector("#status"),
    search: document.querySelector("#search"),
    findBtn: document.querySelector("#findBtn"),
    autoRotate: document.querySelector("#autoRotate"),
    showLabels: document.querySelector("#showLabels"),
    showGates: document.querySelector("#showGates"),
    showExits: document.querySelector("#showExits"),
    activityLayer: document.querySelector("#activityLayer"),
    activityWindow: document.querySelector("#activityWindow"),
    activityLegend: document.querySelector("#activityLegend"),
    activityScale: document.querySelector("#activityScale"),
    activityScaleBar: document.querySelector("#activityScaleBar"),
    activityScaleMax: document.querySelector("#activityScaleMax"),
    zScale: document.querySelector("#zScale"),
    zScaleValue: document.querySelector("#zScaleValue"),
    resetBtn: document.querySelector("#resetBtn"),
    info: document.querySelector("#info"),
    infoName: document.querySelector("#infoName"),
    infoSec: document.querySelector("#infoSec"),
    infoGrid: document.querySelector("#infoGrid"),
    tooltip: document.querySelector("#tooltip"),
    errorBox: document.querySelector("#errorBox"),
    errorDetail: document.querySelector("#errorDetail"),
    sourceBtn: document.querySelector("#sourceBtn"),
    sourcePanel: document.querySelector("#sourcePanel"),
    sourceClose: document.querySelector("#sourceClose"),
    sourceList: document.querySelector("#sourceList")
};

const state = {
    data: null,
    systemMeshes: [],
    boundaryMeshes: [],
    labels: [],
    boundaryLabels: [],
    activityBadges: [],
    selected: null,
    hovered: null,
    gateGroup: null,
    exitGroup: null,
    gateLineSets: [],
    exitLineSets: [],
    starGroup: null,
    boundaryGroup: null,
    labelGroup: null,
    boundaryLabelGroup: null,
    activityBadgeGroup: null,
    basePositions: new Map(),
    systemById: new Map(),
    boundaryBasePositions: new Map(),
    extent: 180,
    initialCamera: null,
    verticalScale: 1,
    activityLayer: "none",
    activityHours: 1,
    activityMaximum: 0,
    activityValues: new Map(),
    activityCoverage: null,
    activityHistoryCache: new Map(),
    activityRequestSerial: 0,
    baseSources: [],
    historySources: []
};

function mapSize() {
    return {
        width: Math.max(1, el.map.clientWidth),
        height: Math.max(1, el.map.clientHeight)
    };
}

const initialSize = mapSize();
const scene = new THREE.Scene();
scene.background = new THREE.Color(0x030506);
scene.fog = new THREE.FogExp2(0x030506, 0.0022);

const camera = new THREE.PerspectiveCamera(
    46,
    initialSize.width / initialSize.height,
    0.1,
    5000
);

const renderer = new THREE.WebGLRenderer({
    antialias: true,
    alpha: false,
    powerPreference: "high-performance"
});
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
renderer.setSize(initialSize.width, initialSize.height);
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.1;
el.map.appendChild(renderer.domElement);

const labelRenderer = new CSS2DRenderer();
labelRenderer.setSize(initialSize.width, initialSize.height);
labelRenderer.domElement.style.position = "absolute";
labelRenderer.domElement.style.inset = "0";
labelRenderer.domElement.style.pointerEvents = "none";
el.map.appendChild(labelRenderer.domElement);

const controls = new OrbitControls(
    camera,
    renderer.domElement
);
controls.enableDamping = true;
controls.dampingFactor = 0.055;
controls.rotateSpeed = 0.55;
controls.zoomSpeed = 0.85;
controls.panSpeed = 0.7;
controls.autoRotate = true;
controls.autoRotateSpeed = 0.42;
controls.minDistance = 18;
controls.maxDistance = 1300;

scene.add(new THREE.AmbientLight(0xffffff, 1.4));
scene.add(createBackgroundStars());

const raycaster = new THREE.Raycaster();
const pointer = new THREE.Vector2(2, 2);
let pointerClientX = 0;
let pointerClientY = 0;

function createBackgroundStars() {
    const count = 1200;
    const positions = new Float32Array(count * 3);

    for (let i = 0; i < count; i += 1) {
        const radius = 700 + Math.random() * 900;
        const theta = Math.random() * Math.PI * 2;
        const phi = Math.acos(2 * Math.random() - 1);

        positions[i * 3] =
            radius * Math.sin(phi) * Math.cos(theta);
        positions[i * 3 + 1] =
            radius * Math.cos(phi);
        positions[i * 3 + 2] =
            radius * Math.sin(phi) * Math.sin(theta);
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute(
        "position",
        new THREE.BufferAttribute(positions, 3)
    );

    const material = new THREE.PointsMaterial({
        size: 0.8,
        color: 0x70808a,
        transparent: true,
        opacity: 0.34,
        depthWrite: false
    });

    return new THREE.Points(geometry, material);
}

function cssColor(variableName, fallback) {
    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(variableName)
        .trim();

    try {
        return new THREE.Color(value || fallback);
    } catch (error) {
        return new THREE.Color(fallback);
    }
}

function colors() {
    return {
        accent: cssColor("--accent", "#ff6a00"),
        hot: cssColor("--accent-bright", "#ffae63"),
        pale: cssColor("--accent-pale", "#ffd0a0"),
        muted: new THREE.Color("#68757e"),
        boundary: new THREE.Color("#77828a")
    };
}

async function fetchVenal() {
    const response = await fetch(API_URL, {
        headers: {
            Accept: "application/json"
        }
    });

    let payload = null;
    try {
        payload = await response.json();
    } catch (error) {
        throw new Error(
            `Venal API returned non-JSON data (HTTP ${response.status}).`
        );
    }

    if (!response.ok || !payload?.ok || !payload?.data) {
        const message =
            payload?.error?.message ||
            `Venal API returned HTTP ${response.status}.`;

        throw new Error(message);
    }

    return payload;
}

function buildMap(payload) {
    const data = payload.data;
    state.data = data;

    state.starGroup = new THREE.Group();
    state.boundaryGroup = new THREE.Group();
    state.labelGroup = new THREE.Group();
    state.boundaryLabelGroup = new THREE.Group();
    state.activityBadgeGroup = new THREE.Group();

    state.starGroup.name = "Venal systems";
    state.boundaryGroup.name = "Venal regional boundary systems";
    state.labelGroup.name = "Venal labels";
    state.boundaryLabelGroup.name = "Venal boundary labels";
    state.activityBadgeGroup.name = "Venal activity values";

    scene.add(
        state.starGroup,
        state.boundaryGroup,
        state.labelGroup,
        state.boundaryLabelGroup,
        state.activityBadgeGroup
    );

    const rawInternal = data.systems.map(system =>
        new THREE.Vector3(
            Number(system.position.x),
            Number(system.position.y),
            -Number(system.position.z)
        )
    );

    const box = new THREE.Box3().setFromPoints(rawInternal);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const largest = Math.max(size.x, size.y, size.z) || 1;
    const scale = 180 / largest;
    state.extent = 180;

    const geometry = new THREE.SphereGeometry(1, 16, 12);

    data.systems.forEach((system, index) => {
        const position = rawInternal[index]
            .sub(center)
            .multiplyScalar(scale);

        state.basePositions.set(
            Number(system.id),
            position.clone()
        );
        state.systemById.set(
            Number(system.id),
            system
        );

        const material = new THREE.MeshBasicMaterial({
            color: colors().accent.clone()
        });

        const mesh = new THREE.Mesh(
            geometry,
            material
        );

        const securityDepth = THREE.MathUtils.clamp(
            Math.abs(Number(system.security)),
            0,
            1
        );
        const radius = 0.82 + securityDepth * 0.45;

        mesh.scale.setScalar(radius);
        mesh.position.copy(position);
        mesh.userData.system = {
            ...system,
            is_boundary: false
        };
        mesh.userData.baseRadius = radius;
        mesh.userData.activityMultiplier = 1;

        state.starGroup.add(mesh);
        state.systemMeshes.push(mesh);

        const anchor = document.createElement("div");
        anchor.className = "system-label-anchor";

        const labelElement = document.createElement("span");
        labelElement.className = "system-label";
        labelElement.textContent = system.name;
        anchor.appendChild(labelElement);

        const label = new CSS2DObject(anchor);
        label.position.copy(position);
        label.userData.systemId = Number(system.id);
        label.userData.el = labelElement;

        state.labelGroup.add(label);
        state.labels.push(label);

        const badgeAnchor = document.createElement("div");
        badgeAnchor.className = "activity-node-anchor";

        const badgeElement = document.createElement("span");
        badgeElement.className = "activity-node";
        badgeElement.hidden = true;
        badgeAnchor.appendChild(badgeElement);

        const badge = new CSS2DObject(badgeAnchor);
        badge.position.copy(position);
        badge.userData.systemId = Number(system.id);
        badge.userData.system = system;
        badge.userData.el = badgeElement;

        state.activityBadgeGroup.add(badge);
        state.activityBadges.push(badge);
    });

    (data.boundary_systems || []).forEach(system => {
        const raw = new THREE.Vector3(
            Number(system.position.x),
            Number(system.position.y),
            -Number(system.position.z)
        );
        const position = raw.sub(center).multiplyScalar(scale);

        state.boundaryBasePositions.set(
            Number(system.id),
            position.clone()
        );

        const mesh = new THREE.Mesh(
            new THREE.SphereGeometry(0.62, 12, 9),
            new THREE.MeshBasicMaterial({
                color: colors().boundary.clone(),
                transparent: true,
                opacity: 0.52
            })
        );

        mesh.position.copy(position);
        mesh.userData.system = {
            ...system,
            is_boundary: true
        };
        mesh.userData.baseRadius = 0.72;
        mesh.userData.activityMultiplier = 1;

        state.boundaryGroup.add(mesh);
        state.boundaryMeshes.push(mesh);

        const anchor = document.createElement("div");
        anchor.className = "boundary-label-anchor";

        const labelElement = document.createElement("span");
        labelElement.className = "boundary-label";
        labelElement.textContent = `${system.name} · ${system.region}`;
        anchor.appendChild(labelElement);

        const label = new CSS2DObject(anchor);
        label.position.copy(position);
        label.userData.systemId = Number(system.id);
        label.userData.el = labelElement;

        state.boundaryLabelGroup.add(label);
        state.boundaryLabels.push(label);
    });

    state.boundaryGroup.visible = false;
    state.boundaryLabelGroup.visible = false;

    buildGateNetwork(data);

    const grid = new THREE.GridHelper(
        260,
        13,
        0x34241a,
        0x182126
    );
    grid.material.transparent = true;
    grid.material.opacity = 0.23;
    scene.add(grid);

    fitInitialView();
    state.baseSources = payload.sources || [];
    state.historySources = [];
    populateSources(state.baseSources);
    applyActivityLayer();

    const counts = data.counts || {};
    const activityTime = formatUtcTime(
        data.activity?.updated_at ||
        payload.meta?.activity_updated_at
    );

    el.status.textContent = [
        `${counts.systems ?? data.systems.length} systems`,
        `${counts.internal_connections ?? data.edges.length} internal links`,
        `${counts.regional_exits ?? (data.external_edges || []).length} regional exits`,
        activityTime ? `activity ${activityTime}` : null
    ].filter(Boolean).join(" · ");

    const hpa = data.systems.find(
        system => system.name === "H-PA29"
    );

    if (hpa) {
        pulseSystem(Number(hpa.id), 900);
    }
}

function buildGateNetwork(data) {
    const sameConstellation = [];
    const crossConstellation = [];

    for (const edge of data.edges || []) {
        if (edge.type === "cross_constellation") {
            crossConstellation.push(edge);
        } else {
            sameConstellation.push(edge);
        }
    }

    state.gateGroup = new THREE.Group();
    state.gateGroup.name = "Venal stargate network";
    state.gateLineSets = [];

    addLineSet({
        edges: sameConstellation,
        group: state.gateGroup,
        dashed: false,
        external: false,
        targetState: state.gateLineSets
    });

    addLineSet({
        edges: crossConstellation,
        group: state.gateGroup,
        dashed: true,
        external: false,
        targetState: state.gateLineSets
    });

    scene.add(state.gateGroup);

    state.exitGroup = new THREE.Group();
    state.exitGroup.name = "Venal regional exits";
    state.exitLineSets = [];

    addLineSet({
        edges: data.external_edges || [],
        group: state.exitGroup,
        dashed: true,
        external: true,
        targetState: state.exitLineSets
    });

    state.exitGroup.visible = false;
    scene.add(state.exitGroup);
}

function addLineSet({
    edges,
    group,
    dashed,
    external,
    targetState
}) {
    if (!edges.length) {
        return;
    }

    const vertices = [];

    for (const edge of edges) {
        const a = getBasePosition(Number(edge.a));
        const b = getBasePosition(Number(edge.b));

        if (!a || !b) {
            continue;
        }

        vertices.push(
            a.x, a.y, a.z,
            b.x, b.y, b.z
        );
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute(
        "position",
        new THREE.Float32BufferAttribute(vertices, 3)
    );

    const palette = colors();
    let material;

    if (dashed) {
        material = new THREE.LineDashedMaterial({
            color: external
                ? palette.boundary.clone()
                : palette.accent.clone(),
            transparent: true,
            opacity: external ? 0.38 : 0.64,
            dashSize: external ? 0.75 : 2.2,
            gapSize: external ? 1.55 : 1.25
        });
    } else {
        material = new THREE.LineBasicMaterial({
            color: palette.accent.clone(),
            transparent: true,
            opacity: 0.64
        });
    }

    const lines = new THREE.LineSegments(
        geometry,
        material
    );

    if (dashed) {
        lines.computeLineDistances();
    }

    lines.renderOrder = -1;
    group.add(lines);
    targetState.push({
        lines,
        edges,
        dashed,
        external
    });
}

function getBasePosition(id) {
    return (
        state.basePositions.get(id) ||
        state.boundaryBasePositions.get(id) ||
        null
    );
}

function fitInitialView() {
    camera.position.set(155, 115, 225);
    controls.target.set(0, 0, 0);
    controls.update();

    state.initialCamera = {
        pos: camera.position.clone(),
        target: controls.target.clone()
    };
}

function applyVerticalScale(value) {
    const factor = Number(value);
    state.verticalScale = factor;
    el.zScaleValue.textContent = `${factor.toFixed(2)}×`;

    for (const mesh of state.systemMeshes) {
        const position = state.basePositions.get(
            Number(mesh.userData.system.id)
        );

        mesh.position.set(
            position.x,
            position.y * factor,
            position.z
        );
    }

    for (const mesh of state.boundaryMeshes) {
        const position = state.boundaryBasePositions.get(
            Number(mesh.userData.system.id)
        );

        mesh.position.set(
            position.x,
            position.y * factor,
            position.z
        );
    }

    for (const label of state.labels) {
        const position = state.basePositions.get(
            Number(label.userData.systemId)
        );
        label.position.set(
            position.x,
            position.y * factor,
            position.z
        );
    }

    for (const label of state.boundaryLabels) {
        const position = state.boundaryBasePositions.get(
            Number(label.userData.systemId)
        );
        label.position.set(
            position.x,
            position.y * factor,
            position.z
        );
    }

    for (const badge of state.activityBadges) {
        const position = state.basePositions.get(
            Number(badge.userData.systemId)
        );
        badge.position.set(
            position.x,
            position.y * factor,
            position.z
        );
    }

    updateLinePositions(state.gateLineSets, factor);
    updateLinePositions(state.exitLineSets, factor);
}

function updateLinePositions(lineSets, factor) {
    for (const lineSet of lineSets) {
        const positions =
            lineSet.lines.geometry.attributes.position.array;
        let index = 0;

        for (const edge of lineSet.edges) {
            const a = getBasePosition(Number(edge.a));
            const b = getBasePosition(Number(edge.b));

            if (!a || !b) {
                continue;
            }

            positions[index++] = a.x;
            positions[index++] = a.y * factor;
            positions[index++] = a.z;
            positions[index++] = b.x;
            positions[index++] = b.y * factor;
            positions[index++] = b.z;
        }

        lineSet.lines.geometry.attributes.position.needsUpdate = true;
        lineSet.lines.geometry.computeBoundingSphere();

        if (lineSet.dashed) {
            lineSet.lines.computeLineDistances();
        }
    }
}

function securityDisplay(security) {
    const value = Number(security);
    const rounded = Math.round(value * 10) / 10;
    return (Object.is(rounded, -0) ? 0 : rounded).toFixed(1);
}

function metricLabel(layer = state.activityLayer) {
    return ({
        ship_kills: "Ship kills",
        pod_kills: "Pod kills",
        npc_kills: "NPC kills",
        ship_jumps: "Ship jumps",
        none: "None"
    })[layer] || layer;
}

function windowLabel(hours = state.activityHours) {
    const labels = {
        1: "1 hour",
        3: "3 hours",
        6: "6 hours",
        12: "12 hours",
        24: "24 hours",
        72: "3 days",
        168: "7 days",
        720: "30 days"
    };

    return labels[Number(hours)] || `${Number(hours)} hours`;
}

function selectedActivityMarkup(system) {
    if (state.activityLayer === "none" || system.is_boundary) {
        return "";
    }

    const partial =
        state.activityHours > 1 &&
        state.activityCoverage &&
        !state.activityCoverage.complete;

    const suffix = partial ? " *" : "";

    return `
        <div class="k">${escapeHtml(metricLabel())} · ${escapeHtml(windowLabel())}</div>
        <div class="v">${formatNumber(activityValue(system))}${suffix}</div>
    `;
}

function selectSystem(system) {
    state.selected = system;
    updateSystemStyles();

    el.infoName.textContent = system.name;
    el.infoSec.textContent = securityDisplay(system.security);

    if (system.is_boundary) {
        el.infoGrid.innerHTML = `
            <div class="k">Region</div><div class="v">${escapeHtml(system.region || "Unknown")}</div>
            <div class="k">Constellation</div><div class="v">${escapeHtml(system.constellation || "Unknown")}</div>
            <div class="k">System ID</div><div class="v">${Number(system.id)}</div>
            <div class="k">Classification</div><div class="v">Regional boundary</div>
            <div class="k">True security</div><div class="v">${Number(system.security).toFixed(4)}</div>
        `;
    } else {
        const activity = system.activity || {};
        const exitCount = Array.isArray(system.regional_exits)
            ? system.regional_exits.length
            : 0;

        el.infoGrid.innerHTML = `
            <div class="k">Constellation</div><div class="v">${escapeHtml(system.constellation)}</div>
            <div class="k">System ID</div><div class="v">${Number(system.id)}</div>
            <div class="k">Stargates</div><div class="v">${Number(system.gate_count || 0)}</div>
            <div class="k">Regional exits</div><div class="v">${exitCount}</div>
            ${selectedActivityMarkup(system)}
            <div class="k">Ship jumps · 1h</div><div class="v">${formatNumber(activity.ship_jumps)}</div>
            <div class="k">Ship kills · 1h</div><div class="v">${formatNumber(activity.ship_kills)}</div>
            <div class="k">Pod kills · 1h</div><div class="v">${formatNumber(activity.pod_kills)}</div>
            <div class="k">NPC kills · 1h</div><div class="v">${formatNumber(activity.npc_kills)}</div>
            <div class="k">True security</div><div class="v">${Number(system.security).toFixed(4)}</div>
        `;
    }

    el.info.classList.add("is-visible");
}

function activityValue(system, layer = state.activityLayer) {
    if (layer === "none" || system.is_boundary) {
        return 0;
    }

    if (state.activityHours > 1) {
        return Math.max(
            0,
            Number(state.activityValues.get(Number(system.id)) || 0)
        );
    }

    return Math.max(
        0,
        Number(system.activity?.[layer] || 0)
    );
}

function isPvpKillLayer(layer = state.activityLayer) {
    return [
        "ship_kills",
        "pod_kills"
    ].includes(layer);
}

function activityIntensity(value) {
    if (state.activityMaximum <= 0 || value <= 0) {
        return 0;
    }

    const relative = THREE.MathUtils.clamp(
        Math.log1p(value) / Math.log1p(state.activityMaximum),
        0,
        1
    );

    if (!isPvpKillLayer()) {
        return relative;
    }

    // PvP kills use a hybrid scale: relative heat preserves contrast between
    // systems, while the absolute curve prevents a single 1-kill system from
    // becoming fully red simply because it is the current maximum.
    const absolute = 1 - Math.exp(-value / 7);

    return THREE.MathUtils.clamp(
        relative * 0.35 + absolute * 0.65,
        0,
        1
    );
}

function activityColor(intensity, palette = colors()) {
    if (state.activityLayer === "none") {
        return palette.accent.clone();
    }

    const endpoint = isPvpKillLayer()
        ? new THREE.Color("#ff1515")
        : new THREE.Color("#ffd35a");

    return palette.accent.clone().lerp(
        endpoint,
        THREE.MathUtils.clamp(intensity, 0, 1)
    );
}

async function fetchActivityHistory(layer, hours) {
    const cacheKey = `${layer}:${hours}`;

    if (state.activityHistoryCache.has(cacheKey)) {
        return state.activityHistoryCache.get(cacheKey);
    }

    const url = new URL(
        HISTORY_API_URL,
        window.location.origin
    );
    url.searchParams.set("metric", layer);
    url.searchParams.set("hours", String(hours));

    const response = await fetch(url, {
        headers: { Accept: "application/json" }
    });

    const payload = await response.json();

    if (!response.ok || !payload?.ok || !payload?.data) {
        throw new Error(
            payload?.error?.message ||
            `History API returned HTTP ${response.status}.`
        );
    }

    state.activityHistoryCache.set(cacheKey, payload);
    return payload;
}

function finalizeActivityLayer() {
    let maximum = 0;

    for (const mesh of state.systemMeshes) {
        maximum = Math.max(
            maximum,
            activityValue(mesh.userData.system)
        );
    }

    state.activityMaximum = maximum;

    for (const mesh of state.systemMeshes) {
        const value = activityValue(mesh.userData.system);
        const intensity = activityIntensity(value);

        mesh.userData.activityMultiplier =
            state.activityLayer === "none"
                ? 1
                : 0.9 + intensity * 1.85;
    }

    const active = state.activityLayer !== "none";
    const coverage = state.activityCoverage;
    const coverageText =
        active &&
        state.activityHours > 1 &&
        coverage
            ? ` · coverage ${coverage.available_hours}/${coverage.expected_hours}h${coverage.complete ? "" : " (partial)"}`
            : "";

    el.activityLegend.textContent = active
        ? `Intelligence layer: ${metricLabel().toLowerCase()} · ${windowLabel().toLowerCase()}${coverageText}`
        : "Intelligence layer: none";

    if (el.activityScale) {
        el.activityScale.hidden = !active;

        if (active && el.activityScaleBar) {
            el.activityScaleBar.style.setProperty(
                "--activity-scale-end",
                isPvpKillLayer() ? "#ff1515" : "#ffd35a"
            );
        }

        if (active && el.activityScaleMax) {
            el.activityScaleMax.textContent = maximum > 0
                ? `hottest system: ${formatNumber(maximum)}`
                : "no activity reported";
        }
    }

    updateSystemStyles();

    if (state.selected) {
        selectSystem(state.selected);
    }
}

async function applyActivityLayer() {
    state.activityLayer = el.activityLayer.value;
    state.activityHours = Number(el.activityWindow?.value || 1);
    state.activityCoverage = null;
    state.activityValues = new Map();

    const active = state.activityLayer !== "none";
    if (el.activityWindow) {
        el.activityWindow.disabled = !active;
    }

    const requestSerial = ++state.activityRequestSerial;

    if (!active || state.activityHours === 1) {
        state.historySources = [];
        populateSources(state.baseSources);
        finalizeActivityLayer();
        return;
    }

    el.activityLegend.textContent =
        `Intelligence layer: loading ${metricLabel().toLowerCase()} · ${windowLabel().toLowerCase()}…`;

    try {
        const payload = await fetchActivityHistory(
            state.activityLayer,
            state.activityHours
        );

        if (requestSerial !== state.activityRequestSerial) {
            return;
        }

        state.activityValues = new Map(
            (payload.data.systems || []).map(row => [
                Number(row.id),
                Number(row.value || 0)
            ])
        );
        state.activityCoverage = payload.data.coverage || null;
        state.historySources = payload.sources || [];
        populateSources([
            ...state.baseSources,
            ...state.historySources
        ]);
    } catch (error) {
        if (requestSerial !== state.activityRequestSerial) {
            return;
        }

        console.error("Unable to load Venal activity history.", error);
        state.activityValues = new Map();
        state.activityCoverage = {
            available_hours: 0,
            expected_hours: state.activityHours,
            complete: false
        };
        state.historySources = [];
        populateSources(state.baseSources);
    }

    finalizeActivityLayer();
}

function updateSystemStyles() {
    const palette = colors();

    for (const mesh of state.systemMeshes) {
        const system = mesh.userData.system;
        const selected = state.selected?.id === system.id;
        const hovered = state.hovered?.id === system.id;
        const value = activityValue(system);
        const intensity = activityIntensity(value);

        let color = activityColor(intensity, palette);

        if (state.activityLayer === "none") {
            if (selected) {
                color = palette.pale.clone();
            } else if (hovered) {
                color = palette.hot.clone();
            }
        }

        mesh.material.color.copy(color);

        const interactionMultiplier = selected
            ? 1.75
            : hovered
                ? 1.35
                : 1;

        mesh.scale.setScalar(
            mesh.userData.baseRadius *
            mesh.userData.activityMultiplier *
            interactionMultiplier
        );
    }

    for (const badge of state.activityBadges) {
        const system = badge.userData.system;
        const value = activityValue(system);
        const visible =
            state.activityLayer !== "none" &&
            value > 0;
        const badgeElement = badge.userData.el;

        badgeElement.hidden = !visible;

        if (!visible) {
            continue;
        }

        const intensity = activityIntensity(value);
        const color = activityColor(intensity, palette);
        const formatted = formatNumber(value);
        const selected = state.selected?.id === system.id;
        const hovered = state.hovered?.id === system.id;
        const digitAllowance = Math.max(0, formatted.length - 2) * 3;
        const size = Math.round(
            18 + intensity * 18 + digitAllowance +
            (selected ? 4 : hovered ? 2 : 0)
        );

        badgeElement.textContent = formatted;
        badgeElement.style.setProperty(
            "--activity-node-size",
            `${size}px`
        );
        badgeElement.style.setProperty(
            "--activity-node-color",
            `#${color.getHexString()}`
        );
        badgeElement.style.setProperty(
            "--activity-font-size",
            `${Math.max(8, Math.min(12, Math.round(size * 0.34)))}px`
        );
        badgeElement.classList.toggle(
            "is-selected",
            selected
        );
        badgeElement.classList.toggle(
            "is-hovered",
            hovered
        );
    }

    for (const mesh of state.boundaryMeshes) {
        const system = mesh.userData.system;
        const selected = state.selected?.id === system.id;
        const hovered = state.hovered?.id === system.id;

        mesh.material.color.copy(
            selected || hovered
                ? palette.hot
                : palette.boundary
        );

        mesh.scale.setScalar(
            mesh.userData.baseRadius *
            (selected ? 1.65 : hovered ? 1.3 : 1)
        );
    }

    for (const label of state.labels) {
        const selected =
            state.selected?.id === label.userData.systemId;
        const system = state.systemById.get(
            Number(label.userData.systemId)
        );
        const hasActivityBadge = Boolean(
            system &&
            state.activityLayer !== "none" &&
            activityValue(system) > 0
        );

        label.userData.el.classList.toggle(
            "is-selected",
            selected
        );
        label.userData.el.classList.toggle(
            "has-activity-badge",
            hasActivityBadge
        );
    }

    refreshLineColors();
}

function refreshLineColors() {
    const palette = colors();

    for (const lineSet of state.gateLineSets) {
        lineSet.lines.material.color.copy(
            palette.accent
        );
    }

    for (const lineSet of state.exitLineSets) {
        lineSet.lines.material.color.copy(
            palette.boundary
        );
    }
}

function pulseSystem(id, duration = 1100) {
    const mesh = state.systemMeshes.find(
        item => Number(item.userData.system.id) === id
    );

    if (!mesh) {
        return;
    }

    const start = performance.now();

    function pulse(now) {
        if (state.selected?.id === id) {
            return;
        }

        const time = Math.min(
            1,
            (now - start) / duration
        );
        const amplitude =
            Math.sin(time * Math.PI * 4) *
            (1 - time);

        const normalScale =
            mesh.userData.baseRadius *
            mesh.userData.activityMultiplier;

        mesh.scale.setScalar(
            normalScale *
            (1.2 + Math.max(0, amplitude) * 0.65)
        );

        if (time < 1) {
            requestAnimationFrame(pulse);
        } else {
            updateSystemStyles();
        }
    }

    requestAnimationFrame(pulse);
}

function focusSystem(system) {
    selectSystem(system);

    const collection = system.is_boundary
        ? state.boundaryMeshes
        : state.systemMeshes;

    const mesh = collection.find(
        item => item.userData.system.id === system.id
    );

    if (!mesh) {
        return;
    }

    const target = mesh.position.clone();
    const direction = camera.position
        .clone()
        .sub(controls.target)
        .normalize();
    const distance = Math.min(
        52,
        camera.position.distanceTo(controls.target)
    );

    const fromPosition = camera.position.clone();
    const fromTarget = controls.target.clone();
    const toPosition = target.clone().add(
        direction.multiplyScalar(distance)
    );

    const start = performance.now();
    const duration = 620;

    controls.autoRotate = false;
    el.autoRotate.checked = false;

    function step(now) {
        const time = Math.min(
            1,
            (now - start) / duration
        );
        const eased = 1 - Math.pow(1 - time, 3);

        camera.position.lerpVectors(
            fromPosition,
            toPosition,
            eased
        );
        controls.target.lerpVectors(
            fromTarget,
            target,
            eased
        );

        if (time < 1) {
            requestAnimationFrame(step);
        }
    }

    requestAnimationFrame(step);
}

function findByName() {
    const query = el.search.value
        .trim()
        .toLowerCase();

    if (!query || !state.data) {
        return;
    }

    const candidates = [
        ...state.data.systems,
        ...(state.data.boundary_systems || []).map(system => ({
            ...system,
            is_boundary: true
        }))
    ];

    let found = candidates.find(
        system => system.name.toLowerCase() === query
    );

    if (!found) {
        found = candidates.find(
            system => system.name.toLowerCase().includes(query)
        );
    }

    if (found) {
        if (found.is_boundary && !el.showExits.checked) {
            el.showExits.checked = true;
            setExitVisibility(true);
        }

        focusSystem(found);
        return;
    }

    el.search.animate(
        [
            { transform: "translateX(0)" },
            { transform: "translateX(-4px)" },
            { transform: "translateX(4px)" },
            { transform: "translateX(0)" }
        ],
        { duration: 180 }
    );
}

function setExitVisibility(visible) {
    if (state.exitGroup) {
        state.exitGroup.visible = visible;
    }
    if (state.boundaryGroup) {
        state.boundaryGroup.visible = visible;
    }
    if (state.boundaryLabelGroup) {
        state.boundaryLabelGroup.visible =
            visible && el.showLabels.checked;
    }
}

function escapeHtml(value) {
    return String(value).replace(
        /[&<>'"]/g,
        character => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            "'": "&#39;",
            '"': "&quot;"
        })[character]
    );
}

function formatNumber(value) {
    return Number(value || 0).toLocaleString("en-US");
}

function formatUtcTime(value) {
    if (!value) {
        return null;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return `${date.toISOString().slice(11, 16)} UTC`;
}

function formatUtcDateTime(value) {
    if (!value) {
        return "Unknown";
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return date.toISOString().replace("T", " ").slice(0, 19) + " UTC";
}

function populateSources(sources) {
    if (!sources.length) {
        el.sourceList.innerHTML = `
            <p class="venal-source-entry">
                Source metadata unavailable.
            </p>
        `;
        return;
    }

    const unique = [];
    const seen = new Set();

    for (const source of sources) {
        const key = [
            source.name || "",
            source.endpoint || "",
            source.role || ""
        ].join("|");

        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        unique.push(source);
    }

    el.sourceList.innerHTML = unique.map(source => {
        const official = source.official === true ||
            source.publisher === "CCP Games";
        const classification = official
            ? " · OFFICIAL"
            : source.name === "Guristas.net"
                ? " · DERIVED"
                : "";

        return `
            <div class="venal-source-entry">
                <strong>${escapeHtml(source.name || "Unknown source")}${source.publisher ? ` · ${escapeHtml(source.publisher)}` : ""}${classification}</strong>
                ${source.endpoint ? `<code>${escapeHtml(source.endpoint)}</code>` : ""}
                ${source.role ? `<span>${escapeHtml(source.role)}</span>` : ""}
                ${source.updated_at ? `<span>Updated: ${escapeHtml(formatUtcDateTime(source.updated_at))}</span>` : ""}
                ${source.retention ? `<span>Retention: ${escapeHtml(source.retention)}</span>` : ""}
                ${source.compatibility_date ? `<span>ESI compatibility: ${escapeHtml(source.compatibility_date)}</span>` : ""}
                ${source.stale ? `<span>Warning: serving stale cached data</span>` : ""}
            </div>
        `;
    }).join("");
}

renderer.domElement.addEventListener(
    "pointermove",
    event => {
        const rectangle = renderer.domElement
            .getBoundingClientRect();

        pointer.x =
            ((event.clientX - rectangle.left) /
                rectangle.width) * 2 - 1;
        pointer.y =
            -((event.clientY - rectangle.top) /
                rectangle.height) * 2 + 1;

        pointerClientX = event.clientX;
        pointerClientY = event.clientY;
    }
);

renderer.domElement.addEventListener(
    "pointerleave",
    () => {
        pointer.set(2, 2);
        state.hovered = null;
        el.tooltip.style.display = "none";
        updateSystemStyles();
    }
);

renderer.domElement.addEventListener(
    "click",
    () => {
        if (state.hovered) {
            selectSystem(state.hovered);
        }
    }
);

el.autoRotate.addEventListener(
    "change",
    () => {
        controls.autoRotate = el.autoRotate.checked;
    }
);

el.showLabels.addEventListener(
    "change",
    () => {
        if (state.labelGroup) {
            state.labelGroup.visible = el.showLabels.checked;
        }
        if (state.boundaryLabelGroup) {
            state.boundaryLabelGroup.visible =
                el.showLabels.checked &&
                el.showExits.checked;
        }
    }
);

el.showGates.addEventListener(
    "change",
    () => {
        if (state.gateGroup) {
            state.gateGroup.visible = el.showGates.checked;
        }
    }
);

el.showExits.addEventListener(
    "change",
    () => {
        setExitVisibility(el.showExits.checked);
    }
);

el.activityLayer.addEventListener(
    "change",
    () => applyActivityLayer()
);

if (el.activityWindow) {
    el.activityWindow.addEventListener(
        "change",
        () => applyActivityLayer()
    );
}

el.zScale.addEventListener(
    "input",
    () => applyVerticalScale(el.zScale.value)
);

el.findBtn.addEventListener(
    "click",
    findByName
);

el.search.addEventListener(
    "keydown",
    event => {
        if (event.key === "Enter") {
            findByName();
        }
    }
);

el.resetBtn.addEventListener(
    "click",
    () => {
        if (!state.initialCamera) {
            return;
        }

        camera.position.copy(state.initialCamera.pos);
        controls.target.copy(state.initialCamera.target);
        controls.autoRotate = true;
        el.autoRotate.checked = true;
        el.zScale.value = "1";
        applyVerticalScale(1);
        controls.update();
    }
);

function toggleSourcePanel(forceOpen = null) {
    const currentlyOpen = !el.sourcePanel.hidden;
    const nextOpen = forceOpen === null
        ? !currentlyOpen
        : Boolean(forceOpen);

    el.sourcePanel.hidden = !nextOpen;
    el.sourceBtn.setAttribute(
        "aria-expanded",
        nextOpen ? "true" : "false"
    );
}

el.sourceBtn.addEventListener(
    "click",
    () => toggleSourcePanel()
);

el.sourceClose.addEventListener(
    "click",
    () => toggleSourcePanel(false)
);

document.addEventListener(
    "keydown",
    event => {
        if (event.key === "Escape") {
            toggleSourcePanel(false);
        }
    }
);

document.addEventListener(
    "guristas:themechange",
    () => {
        updateSystemStyles();
    }
);

function updateHover() {
    if (!state.systemMeshes.length) {
        return;
    }

    raycaster.setFromCamera(
        pointer,
        camera
    );

    const clickable = el.showExits.checked
        ? [...state.systemMeshes, ...state.boundaryMeshes]
        : state.systemMeshes;

    const hit = raycaster.intersectObjects(
        clickable,
        false
    )[0];

    const hovered =
        hit?.object?.userData?.system || null;

    if (hovered?.id !== state.hovered?.id) {
        state.hovered = hovered;
        updateSystemStyles();
    }

    if (hovered) {
        const metric = state.activityLayer !== "none" &&
            !hovered.is_boundary
            ? ` · ${formatNumber(activityValue(hovered))}`
            : "";

        el.tooltip.innerHTML =
            `${escapeHtml(hovered.name)} ` +
            `<span class="sec">${securityDisplay(hovered.security)}</span>` +
            escapeHtml(metric);

        el.tooltip.style.display = "block";
        el.tooltip.style.left = `${pointerClientX}px`;
        el.tooltip.style.top = `${pointerClientY}px`;
    } else {
        el.tooltip.style.display = "none";
    }
}

function updateLabelDensity() {
    if (!state.labelGroup?.visible) {
        return;
    }

    const distance = camera.position.distanceTo(
        controls.target
    );
    let opacity = 1;

    if (distance > 310) {
        opacity = 0.35;
    }
    if (distance > 430) {
        opacity = 0.1;
    }

    for (const label of state.labels) {
        label.userData.el.style.opacity =
            state.selected?.id === label.userData.systemId
                ? "1"
                : String(opacity);
    }

    for (const label of state.boundaryLabels) {
        label.userData.el.style.opacity = String(
            Math.min(opacity, 0.5)
        );
    }
}

function animate() {
    requestAnimationFrame(animate);
    controls.update();
    updateHover();
    updateLabelDensity();
    renderer.render(scene, camera);
    labelRenderer.render(scene, camera);
}

animate();

function resizeMap() {
    const size = mapSize();

    camera.aspect = size.width / size.height;
    camera.updateProjectionMatrix();
    renderer.setSize(size.width, size.height);
    labelRenderer.setSize(size.width, size.height);
}

const resizeObserver = new ResizeObserver(
    resizeMap
);
resizeObserver.observe(el.map);
window.addEventListener("resize", resizeMap);

try {
    const payload = await fetchVenal();
    buildMap(payload);
} catch (error) {
    console.error(error);

    el.status.textContent =
        "Unable to load Venal intelligence data.";
    el.errorDetail.textContent =
        error instanceof Error
            ? error.message
            : String(error);
    el.errorBox.hidden = false;
}
