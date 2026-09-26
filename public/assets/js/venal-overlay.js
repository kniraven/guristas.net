"use strict";

import * as THREE from "three";
import {
    CSS2DObject,
    CSS2DRenderer
} from "three/addons/renderers/CSS2DRenderer.js";

const API_URL = "/api/venal/overlay.php";
const REFRESH_INTERVAL_MS = 5 * 60 * 1000;
const ROTATION_RADIANS_PER_SECOND = 0.055;
const MAP_SPAN = 180;
const ORANGE = new THREE.Color("#ff6a00");
const RED = new THREE.Color("#ff1515");

const container = document.querySelector("#venalOverlay");

const state = {
    payload: null,
    root: new THREE.Group(),
    nodeMesh: null,
    labels: new Map(),
    badges: new Map(),
    systemIndexById: new Map(),
    baseRadiusById: new Map(),
    positionById: new Map(),
    lastUpdatedAt: null,
    refreshing: false
};

const scene = new THREE.Scene();
scene.background = null;
scene.add(state.root);

const camera = new THREE.PerspectiveCamera(
    44,
    Math.max(1, window.innerWidth) / Math.max(1, window.innerHeight),
    0.1,
    3000
);

const renderer = new THREE.WebGLRenderer({
    alpha: true,
    antialias: true,
    premultipliedAlpha: true,
    powerPreference: "high-performance"
});
renderer.setClearColor(0x000000, 0);
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
renderer.setSize(window.innerWidth, window.innerHeight, false);
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.sortObjects = false;
container.appendChild(renderer.domElement);

const labelRenderer = new CSS2DRenderer();
labelRenderer.setSize(window.innerWidth, window.innerHeight);
labelRenderer.domElement.className = "overlay-label-layer";
container.appendChild(labelRenderer.domElement);

const clock = new THREE.Clock();
const reusableMatrix = new THREE.Matrix4();
const reusableScale = new THREE.Vector3();
const reusableQuaternion = new THREE.Quaternion();

function normalizeMapPositions(systems) {
    const raw = systems.map(system => new THREE.Vector3(
        Number(system.position.x),
        Number(system.position.y),
        -Number(system.position.z)
    ));

    const box = new THREE.Box3().setFromPoints(raw);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const largest = Math.max(size.x, size.y, size.z) || 1;
    const scale = MAP_SPAN / largest;

    systems.forEach((system, index) => {
        const position = raw[index]
            .sub(center)
            .multiplyScalar(scale);

        // 1.00x geometry: no axis is exaggerated.
        state.positionById.set(Number(system.id), position);
    });
}

function activityIntensity(value, maximum) {
    if (value <= 0 || maximum <= 0) {
        return 0;
    }

    const relative = THREE.MathUtils.clamp(
        Math.log1p(value) / Math.log1p(maximum),
        0,
        1
    );
    const absolute = 1 - Math.exp(-value / 7);

    return THREE.MathUtils.clamp(
        relative * 0.35 + absolute * 0.65,
        0,
        1
    );
}

function heatColor(intensity) {
    return ORANGE.clone().lerp(
        RED,
        THREE.MathUtils.clamp(intensity, 0, 1)
    );
}

function buildMap(payload) {
    state.payload = payload;
    const systems = payload.data.systems || [];
    const edges = payload.data.edges || [];

    normalizeMapPositions(systems);

    const sphereGeometry = new THREE.SphereGeometry(1, 12, 8);
    const sphereMaterial = new THREE.MeshBasicMaterial({
        color: 0xffffff
    });

    state.nodeMesh = new THREE.InstancedMesh(
        sphereGeometry,
        sphereMaterial,
        systems.length
    );
    state.nodeMesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    state.nodeMesh.name = "Venal systems";
    state.root.add(state.nodeMesh);

    systems.forEach((system, index) => {
        const id = Number(system.id);
        const position = state.positionById.get(id);
        const securityDepth = THREE.MathUtils.clamp(
            Math.abs(Number(system.security)),
            0,
            1
        );
        const baseRadius = 0.82 + securityDepth * 0.45;

        state.systemIndexById.set(id, index);
        state.baseRadiusById.set(id, baseRadius);

        const labelAnchor = document.createElement("div");
        labelAnchor.className = "overlay-system-label-anchor";

        const labelElement = document.createElement("span");
        labelElement.className = "overlay-system-label";
        labelElement.textContent = system.name;
        labelAnchor.appendChild(labelElement);

        const label = new CSS2DObject(labelAnchor);
        label.position.copy(position);
        state.root.add(label);
        state.labels.set(id, labelElement);

        const badgeAnchor = document.createElement("div");
        badgeAnchor.className = "overlay-kill-anchor";

        const badgeElement = document.createElement("span");
        badgeElement.className = "overlay-kill-badge";
        badgeElement.hidden = true;
        badgeAnchor.appendChild(badgeElement);

        const badge = new CSS2DObject(badgeAnchor);
        badge.position.copy(position);
        state.root.add(badge);
        state.badges.set(id, badgeElement);
    });

    buildGateLines(edges);
    updateKills(payload);
    fitCamera();
}

function buildGateLines(edges) {
    const solidVertices = [];
    const dashedVertices = [];

    for (const edge of edges) {
        const a = state.positionById.get(Number(edge.a));
        const b = state.positionById.get(Number(edge.b));

        if (!a || !b) {
            continue;
        }

        const target = edge.type === "cross_constellation"
            ? dashedVertices
            : solidVertices;

        target.push(
            a.x, a.y, a.z,
            b.x, b.y, b.z
        );
    }

    if (solidVertices.length) {
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute(
            "position",
            new THREE.Float32BufferAttribute(solidVertices, 3)
        );

        const material = new THREE.LineBasicMaterial({
            color: ORANGE,
            transparent: true,
            opacity: 0.72
        });

        state.root.add(new THREE.LineSegments(geometry, material));
    }

    if (dashedVertices.length) {
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute(
            "position",
            new THREE.Float32BufferAttribute(dashedVertices, 3)
        );

        const material = new THREE.LineDashedMaterial({
            color: ORANGE,
            transparent: true,
            opacity: 0.72,
            dashSize: 2.2,
            gapSize: 1.25
        });

        const lines = new THREE.LineSegments(geometry, material);
        lines.computeLineDistances();
        state.root.add(lines);
    }
}

function updateKills(payload) {
    const systems = payload.data.systems || [];
    const maximum = systems.reduce(
        (max, system) => Math.max(max, Number(system.ship_kills || 0)),
        0
    );

    for (const system of systems) {
        const id = Number(system.id);
        const index = state.systemIndexById.get(id);
        const position = state.positionById.get(id);

        if (index === undefined || !position || !state.nodeMesh) {
            continue;
        }

        const kills = Math.max(0, Number(system.ship_kills || 0));
        const intensity = activityIntensity(kills, maximum);
        const color = heatColor(intensity);
        const baseRadius = state.baseRadiusById.get(id) || 1;
        const activityMultiplier = 0.9 + intensity * 1.85;
        const radius = baseRadius * activityMultiplier;

        reusableScale.set(radius, radius, radius);
        reusableMatrix.compose(
            position,
            reusableQuaternion,
            reusableScale
        );

        state.nodeMesh.setMatrixAt(index, reusableMatrix);
        state.nodeMesh.setColorAt(index, color);

        const badge = state.badges.get(id);
        const label = state.labels.get(id);

        if (!badge || !label) {
            continue;
        }

        const visible = kills > 0;
        badge.hidden = !visible;
        label.classList.toggle("has-kills", visible);

        if (!visible) {
            continue;
        }

        const formatted = kills.toLocaleString("en-US");
        const digitAllowance = Math.max(0, formatted.length - 2) * 3;
        const size = Math.round(18 + intensity * 18 + digitAllowance);

        badge.textContent = formatted;
        badge.style.setProperty("--kill-size", `${size}px`);
        badge.style.setProperty("--kill-color", `#${color.getHexString()}`);
        badge.style.setProperty(
            "--kill-font-size",
            `${Math.max(8, Math.min(12, Math.round(size * 0.34)))}px`
        );
    }

    if (state.nodeMesh) {
        state.nodeMesh.instanceMatrix.needsUpdate = true;
        if (state.nodeMesh.instanceColor) {
            state.nodeMesh.instanceColor.needsUpdate = true;
        }
    }

    state.lastUpdatedAt = payload.data.activity?.updated_at || null;
}

function fitCamera() {
    camera.position.set(0, 116, 252);
    camera.lookAt(0, 0, 0);
}

async function fetchOverlayData() {
    const response = await fetch(API_URL, {
        cache: "no-store",
        headers: {
            Accept: "application/json"
        }
    });

    const payload = await response.json();

    if (!response.ok || !payload?.ok || !payload?.data) {
        throw new Error(
            payload?.error?.message ||
            `Venal overlay API returned HTTP ${response.status}.`
        );
    }

    return payload;
}

async function refreshActivity() {
    if (state.refreshing) {
        return;
    }

    state.refreshing = true;

    try {
        const payload = await fetchOverlayData();

        if (!state.nodeMesh) {
            buildMap(payload);
            return;
        }

        // Static Venal topology is effectively immutable during a stream.
        // Only replace the cheap activity values on refresh.
        updateKills(payload);
    } catch (error) {
        // Stream overlays should fail quietly rather than placing an error panel
        // over the broadcast. The previous successful frame remains visible.
        console.error("Unable to refresh Venal stream overlay.", error);
    } finally {
        state.refreshing = false;
    }
}

function resize() {
    const width = Math.max(1, window.innerWidth);
    const height = Math.max(1, window.innerHeight);

    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    renderer.setSize(width, height, false);
    labelRenderer.setSize(width, height);
}

function animate() {
    requestAnimationFrame(animate);

    const delta = Math.min(clock.getDelta(), 0.1);
    state.root.rotation.y += ROTATION_RADIANS_PER_SECOND * delta;

    renderer.render(scene, camera);
    labelRenderer.render(scene, camera);
}

window.addEventListener("resize", resize, { passive: true });

await refreshActivity();
window.setInterval(refreshActivity, REFRESH_INTERVAL_MS);
animate();
