'use strict';

/* ═══════════════════════════════════════════════════════════════════
   Tournéo · Frontend v2
   ═══════════════════════════════════════════════════════════════════ */

const ROUTE_COLORS = [
    '#b8ff4d', '#5ac8fa', '#ff7b54', '#c084fc', '#fbbf24',
    '#ff5c5c', '#4ade80', '#f472b6', '#22d3ee', '#fb923c',
];

const TILE_DARK  = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
const TILE_LIGHT = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';
const TILE_VOY   = 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';

const state = {
    agencies:     [],
    trucks:       [],
    clients:      [],
    routes:       [],
    unassigned:   [],
    editingRoute: null,
    showRoutes:   true,
    showMarkers:  true,
    heatmapOn:    false,
    tileMode:     'dark', // dark|light|voyager
};

let map, markersLayer, routesLayer, heatLayer, tileLayer;

/* ─── theme ─── */
const THEME_KEY = 'tourneo_theme';
function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    if (map && tileLayer) {
        tileLayer.setUrl(theme === 'light' ? TILE_LIGHT : (state.tileMode === 'voyager' ? TILE_VOY : TILE_DARK));
        state.tileMode = theme === 'light' ? 'light' : 'dark';
    }
    localStorage.setItem(THEME_KEY, theme);
}
function toggleTheme() {
    const cur = document.documentElement.getAttribute('data-theme') || 'dark';
    applyTheme(cur === 'dark' ? 'light' : 'dark');
}

/* ─── boot ─── */
window.addEventListener('load', () => {
    initMap();
    setupUI();
    applyTheme(localStorage.getItem(THEME_KEY) || 'dark');
    renderTemplates();
});

function initMap() {
    map = L.map('map', { zoomControl: false }).setView([46.603354, 1.888334], 6);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    tileLayer = L.tileLayer(TILE_DARK, {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> · <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd', maxZoom: 20,
    }).addTo(map);
    markersLayer = L.layerGroup().addTo(map);
    routesLayer  = L.layerGroup().addTo(map);
}

function setupUI() {
    // Top bar buttons
    $('#btn-theme').onclick      = toggleTheme;
    $('#btn-fullscreen').onclick = enterFullscreen;
    $('#btn-exit-fs').onclick    = exitFullscreen;
    $('#btn-heatmap').onclick    = toggleHeatmap;
    $('#btn-history').onclick    = openHistoryModal;
    $('#btn-compare').onclick    = openCompareModal;
    $('#btn-stats').onclick      = openStatsModal;

    // Sidebar inputs
    $('#fleet-file').addEventListener('change', onFleetUpload);
    $('#orders-file').addEventListener('change', onOrdersUpload);
    $('#generate-btn').addEventListener('click', onGenerate);
    $('#view-data-btn').addEventListener('click', () => { renderDataTable(); openModal('data-modal'); });
    $('#save-session-btn').addEventListener('click', exportSession);
    $('#load-session-file').addEventListener('change', (e) => {
        if (e.target.files[0]) importSession(e.target.files[0]);
        e.target.value = '';
    });
    $('#export-all-btn').addEventListener('click', exportAllRoutesCSV);
    $('#snapshot-btn').addEventListener('click', () => { saveHistorySnapshot(); toast('Snapshot enregistré dans l\'historique'); });
    $('#save-template-btn').addEventListener('click', () => {
        const name = $('#template-name').value;
        saveTemplate(name);
        $('#template-name').value = '';
    });

    // Collapsible sections
    document.querySelectorAll('.sb-head').forEach(h => {
        h.addEventListener('click', () => {
            const collapsed = h.getAttribute('data-collapsed') === 'true';
            h.setAttribute('data-collapsed', String(!collapsed));
        });
    });

    // Drawer tabs
    document.querySelectorAll('.drawer-tab').forEach(t => {
        t.onclick = () => {
            document.querySelectorAll('.drawer-tab').forEach(x => x.classList.remove('is-active'));
            document.querySelectorAll('.drawer-pane').forEach(x => x.classList.remove('is-active'));
            t.classList.add('is-active');
            $(`#pane-${t.dataset.pane}`).classList.add('is-active');
        };
    });
    $('#drawer-close').onclick = () => $('#drawer').classList.remove('is-open');

    // Map tools
    $('#map-zoom-fit').onclick = fitMapToData;
    $('#map-toggle-routes').onclick = () => {
        state.showRoutes = !state.showRoutes;
        $('#map-toggle-routes').classList.toggle('is-active', !state.showRoutes);
        renderMapState();
    };
    $('#map-toggle-markers').onclick = () => {
        state.showMarkers = !state.showMarkers;
        $('#map-toggle-markers').classList.toggle('is-active', !state.showMarkers);
        renderMapState();
    };
    $('#map-tile-toggle').onclick = () => {
        const cur = document.documentElement.getAttribute('data-theme');
        if (cur === 'light') { tileLayer.setUrl(TILE_VOY); }
        else if (state.tileMode === 'voyager') { tileLayer.setUrl(TILE_DARK); state.tileMode = 'dark'; }
        else { tileLayer.setUrl(TILE_VOY); state.tileMode = 'voyager'; }
    };

    // Modals (close on click outside / ×)
    document.querySelectorAll('.close-x[data-close]').forEach(b => {
        b.onclick = () => closeModal(b.dataset.close);
    });
    document.querySelectorAll('.modal').forEach(m => {
        m.addEventListener('click', (e) => { if (e.target === m) m.hidden = true; });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal').forEach(m => m.hidden = true);
            if (document.body.classList.contains('fullscreen-map')) exitFullscreen();
        }
    });

    // Mobile menu toggle
    const menuBtn = $('#menu-toggle');
    const sidebar = $('#sidebar');
    menuBtn?.addEventListener('click', () => {
        const open = sidebar.classList.toggle('active');
        menuBtn.classList.toggle('active', open);
        menuBtn.setAttribute('aria-expanded', String(open));
    });
}

const $ = (sel) => document.querySelector(sel);

/* ═══════════════════════════════════════════════════════════════════
   Uploads
   ═══════════════════════════════════════════════════════════════════ */

function confirmReset(type) {
    if (state.routes.length === 0 && state.clients.length === 0 && state.agencies.length === 0) return true;
    return confirm(`Des données sont déjà chargées. Importer un nouveau fichier ${type} réinitialisera l'état. Continuer ?`);
}

async function onFleetUpload(event) {
    if (!confirmReset('flotte')) { event.target.value = ''; return; }
    const file = event.target.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('fleet_file', file);

    showLoading('Géocodage des agences…');
    try {
        const data = await apiPost('/api/fleet', formData);
        state.agencies = data.agencies;
        state.trucks   = data.trucks;
        $('#agency-count').textContent = state.agencies.length;
        $('#truck-count').textContent  = state.trucks.length;
        $('#fleet-file-label').classList.add('has-data');
        $('#fleet-file-label-text').textContent = `${file.name}`;
        refreshMarkers();
        fitMapToData();
        toast(`Flotte chargée : ${state.agencies.length} agence(s), ${state.trucks.length} camion(s)`);
    } catch (err) {
        toast(`Erreur flotte : ${err.message}`, 'error');
    } finally { hideLoading(); }
}

async function onOrdersUpload(event) {
    if (!confirmReset('commandes')) { event.target.value = ''; return; }
    const file = event.target.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('orders_file', file);

    const logEl = $('#geocoding-log');
    logEl.hidden = false;
    logEl.innerHTML = '';

    const rawText  = await file.text();
    const lineCount = Math.max(0, rawText.split('\n').filter(l => l.trim()).length - 1);
    showLoading(`Géocodage de ${lineCount} adresse${lineCount > 1 ? 's' : ''}…`);
    try {
        const data = await apiPost('/api/orders', formData);
        state.clients = data.clients;

        const successCount = data.clients.length;
        addLog(`${successCount} / ${lineCount} adresses géocodées avec succès`, successCount < lineCount);
        data.logs.forEach(({ success, client }) => {
            if (!success) addLog(`Échec : ${client} (adresse non trouvée)`, true);
        });

        $('#client-count').textContent = state.clients.length;
        $('#orders-file-label').classList.add('has-data');
        $('#orders-file-label-text').textContent = `${file.name}`;
        const hasClients = state.clients.length > 0;
        $('#generate-btn').disabled  = !hasClients;
        $('#view-data-btn').disabled = !hasClients;
        refreshMarkers();
        fitMapToData();
    } catch (err) {
        toast(`Erreur commandes : ${err.message}`, 'error');
    } finally { hideLoading(); }
}

/* ═══════════════════════════════════════════════════════════════════
   Generate
   ═══════════════════════════════════════════════════════════════════ */

async function onGenerate() {
    if (state.agencies.length === 0 || state.trucks.length === 0) {
        toast('Importe la flotte (agences et camions) avant de générer.', 'warn');
        return;
    }
    showLoading('Génération des tournées et calcul des itinéraires…');
    try {
        const cfg = getConfig();
        const result = await apiPost('/api/generate',
            JSON.stringify({ agencies: state.agencies, trucks: state.trucks, clients: state.clients, config: cfg }),
            'application/json'
        );
        state.routes       = result.routes;
        state.unassigned   = result.unassignedItems ?? [];
        state.editingRoute = null;
        renderAll();
        $('#empty-state').hidden = true;
        $('#drawer').classList.add('is-open');
        $('#snapshot-btn').disabled = false;
        $('#export-all-btn').disabled = false;
        toast(`Génération OK · ${state.routes.length} tournée(s), ${state.unassigned.length} non affecté(s)`);
    } catch (err) {
        toast(`Erreur génération : ${err.message}`, 'error');
    } finally { hideLoading(); }
}

/* ═══════════════════════════════════════════════════════════════════
   Edit operations
   ═══════════════════════════════════════════════════════════════════ */

function toggleEditRoute(index) {
    state.editingRoute = state.editingRoute === index ? null : index;
    renderLegend();
    renderMapState();
}

function removeStop(routeIndex, stopIndex) {
    const route = state.routes[routeIndex];
    const [removed] = route.points.splice(stopIndex, 1);
    route.totalVolume = Math.max(0, route.totalVolume - (removed.volume ?? 0));
    route.modified = true;
    state.unassigned.push(removed);
    renderLegend(); renderMapState();
}

function moveStop(fromRoute, stopIndex, toRoute) {
    const [stop] = state.routes[fromRoute].points.splice(stopIndex, 1);
    state.routes[fromRoute].totalVolume = Math.max(0, state.routes[fromRoute].totalVolume - (stop.volume ?? 0));
    state.routes[fromRoute].modified = true;
    state.routes[toRoute].points.push(stop);
    state.routes[toRoute].totalVolume += stop.volume ?? 0;
    state.routes[toRoute].modified = true;
    renderLegend(); renderMapState();
}

function moveStopUp(routeIndex, stopIndex) {
    if (stopIndex <= 0) return;
    const pts = state.routes[routeIndex].points;
    [pts[stopIndex - 1], pts[stopIndex]] = [pts[stopIndex], pts[stopIndex - 1]];
    state.routes[routeIndex].modified = true;
    renderLegend(); renderMapState();
}

function moveStopDown(routeIndex, stopIndex) {
    const pts = state.routes[routeIndex].points;
    if (stopIndex >= pts.length - 1) return;
    [pts[stopIndex], pts[stopIndex + 1]] = [pts[stopIndex + 1], pts[stopIndex]];
    state.routes[routeIndex].modified = true;
    renderLegend(); renderMapState();
}

function assignUnassigned(unassignedIndex, routeIndex) {
    const [stop] = state.unassigned.splice(unassignedIndex, 1);
    state.routes[routeIndex].points.push(stop);
    state.routes[routeIndex].totalVolume += stop.volume ?? 0;
    state.routes[routeIndex].modified = true;
    renderLegend(); renderMapState();
}

async function recalculateRoute(index) {
    const route = state.routes[index];
    showLoading('Recalcul de l\'itinéraire…');
    try {
        const data = await apiPost('/api/recalculate',
            JSON.stringify({ agency: route.agency, truck: route.truck, points: route.points, config: getConfig() }),
            'application/json'
        );
        route.geometry  = data.geometry;
        route.distance  = data.distance;
        route.duration  = data.duration;
        route.fuelCost  = data.fuelCost;
        route.laborCost = data.laborCost;
        route.totalCost = data.totalCost;
        route.modified  = false;
        renderLegend(); renderMapState(); renderDashboard();
        toast('Itinéraire recalculé');
    } catch (err) {
        toast(`Erreur recalcul : ${err.message}`, 'error');
    } finally { hideLoading(); }
}

/* ═══════════════════════════════════════════════════════════════════
   Rendering
   ═══════════════════════════════════════════════════════════════════ */

function renderAll() { renderLegend(); renderMapState(); renderDashboard(); }

function renderDashboard() {
    const r = state.routes;
    if (r.length === 0) {
        ['kpi-trucks','kpi-fill','kpi-distance','kpi-cost','kpi-stops'].forEach(id => $(`#${id}`).textContent =
            id === 'kpi-distance' ? '0 km' : id === 'kpi-cost' ? '0 €' : id === 'kpi-fill' ? '0%' : '0/0');
        return;
    }
    const trucksUsed = r.length;
    const trucksTotal = state.trucks.length;
    const avgFill = r.reduce((s, x) => s + (x.totalVolume / (x.truck.volume_max || 1)) * 100, 0) / trucksUsed;
    const totalDist = r.reduce((s, x) => s + (x.distance ?? 0), 0);
    const totalCost = r.reduce((s, x) => s + (x.totalCost ?? 0), 0);
    const totalStops = r.reduce((s, x) => s + x.points.filter(p => !p.is_break).length, 0);

    $('#kpi-trucks').textContent   = `${trucksUsed}/${trucksTotal}`;
    $('#kpi-fill').textContent     = `${avgFill.toFixed(0)}%`;
    $('#kpi-distance').textContent = `${totalDist.toFixed(0)} km`;
    $('#kpi-cost').textContent     = `${totalCost.toFixed(0)} €`;
    $('#kpi-stops').textContent    = `${totalStops}/${totalStops + state.unassigned.length}`;
}

function renderLegend() {
    const legend = $('#route-legend');
    legend.innerHTML = '';
    state.routes.forEach((route, i) => legend.appendChild(buildRouteCard(route, i)));

    const summary = $('#summary-bar');
    if (state.routes.length > 0) {
        const totDist = state.routes.reduce((s, r) => s + (r.distance ?? 0), 0);
        const totCost = state.routes.reduce((s, r) => s + (r.totalCost ?? 0), 0);
        summary.innerHTML =
            `<div>Distance<br><b>${totDist.toFixed(1)} km</b></div>` +
            `<div>Coût total<br><b>${totCost.toFixed(2)} €</b></div>`;
        summary.style.display = 'grid';
    } else {
        summary.style.display = 'none';
    }

    // Unassigned tab
    const uList = $('#unassigned-list');
    if (state.unassigned.length === 0) {
        uList.innerHTML = '<div class="drawer-empty">Aucune commande non affectée 🎉</div>';
    } else {
        uList.innerHTML = '';
        state.unassigned.forEach((point, uIdx) => {
            const routeOptions = state.routes.map((r, i) =>
                `<option value="${i}">${escapeHtml(r.truck.id)}</option>`).join('');
            const item = document.createElement('div');
            item.className = 'unassigned-item';
            item.innerHTML =
                `<span class="stop-name" title="${escapeHtml(point.nom_client)}">${escapeHtml(point.nom_client)}</span>` +
                `<span class="stop-tag">${point.volume} m³</span>` +
                (point.poids_kg ? `<span class="stop-tag poids">${point.poids_kg} kg</span>` : '') +
                `<select class="assign-select"><option value="">→ Assigner</option>${routeOptions}</select>`;
            item.querySelector('.assign-select').addEventListener('change', (e) => {
                const to = parseInt(e.target.value, 10);
                if (!isNaN(to)) assignUnassigned(uIdx, to);
            });
            uList.appendChild(item);
        });
    }
}

function renderDepotMarkers() {
    if (!state.showMarkers) return;
    const agencyIcon = L.divIcon({ className: '', html: '<div class="agency-marker">A</div>', iconSize: [30, 30] });
    state.agencies.forEach(a => {
        L.marker([a.lat, a.lon], { icon: agencyIcon })
            .bindPopup(`<b>Agence — ${escapeHtml(a.id_nom)}</b>`)
            .addTo(markersLayer);
    });
    const agencyCoords = new Set(state.agencies.map(a => `${a.lat},${a.lon}`));
    const shown = new Set();
    state.trucks.forEach(t => {
        if (!t.lat || !t.lon) return;
        const k = `${t.lat},${t.lon}`;
        if (agencyCoords.has(k) || shown.has(k)) return;
        shown.add(k);
        const icon = L.divIcon({ className: '', html: '<div class="agency-marker truck-base">🚛</div>', iconSize: [32, 32] });
        L.marker([t.lat, t.lon], { icon })
            .bindPopup(`<b>Base ${escapeHtml(t.id)}</b><br>${escapeHtml(t.adresse || '')} ${escapeHtml(t.ville || '')}`)
            .addTo(markersLayer);
    });
}

function renderMapState() {
    markersLayer.clearLayers();
    routesLayer.clearLayers();
    if (heatLayer) { map.removeLayer(heatLayer); heatLayer = null; }

    const editing = state.editingRoute;
    renderDepotMarkers();

    state.routes.forEach((route, idx) => {
        const color = ROUTE_COLORS[idx % ROUTE_COLORS.length];
        const dimmed = editing !== null && idx !== editing;
        const lineOp = dimmed ? 0.12 : 0.75;
        const markerOp = dimmed ? 0.15 : 0.95;
        const radius = idx === editing ? 10 : 7;

        if (state.showRoutes && route.geometry) {
            L.geoJSON(route.geometry, { style: { color, weight: idx === editing ? 5 : 4, opacity: lineOp } }).addTo(routesLayer);
        }

        if (state.showMarkers) {
            route.points.forEach((p, order) => {
                L.circleMarker([p.lat, p.lon], {
                    radius, fillColor: color, color: '#fff', weight: 2,
                    opacity: markerOp, fillOpacity: markerOp,
                })
                .bindPopup(
                    `<b>${escapeHtml(p.nom_client)}</b><br>` +
                    `Camion : ${escapeHtml(route.truck.id)}<br>Ordre : ${order + 1}<br>` +
                    `Volume : ${p.volume} m³` +
                    (p.poids_kg ? `<br>Poids : ${p.poids_kg} kg` : '') +
                    (p.arrival_min != null ? `<br>Arrivée : ${minsToHHMM(p.arrival_min)}` : '')
                )
                .addTo(markersLayer);
            });
        }
    });

    state.unassigned.forEach(p => {
        L.circleMarker([p.lat, p.lon], {
            radius: 8, fillColor: '#ff5c5c', color: '#fff', weight: 2.5, opacity: 1, fillOpacity: 0.95,
        }).bindPopup(`<b>${escapeHtml(p.nom_client)}</b><br><span style="color:#ff5c5c">⚠ Non affecté</span>`)
        .addTo(markersLayer);
    });

    if (state.heatmapOn && typeof L.heatLayer === 'function') {
        const allClients = [
            ...state.routes.flatMap(r => r.points.filter(p => !p.is_break)),
            ...state.unassigned,
        ];
        const pts = allClients.map(p => [p.lat, p.lon, (p.volume || 1)]);
        if (pts.length > 0) {
            heatLayer = L.heatLayer(pts, { radius: 28, blur: 22, maxZoom: 11, gradient: { 0.2: '#5ac8fa', 0.5: '#b8ff4d', 0.8: '#fbbf24', 1: '#ff5c5c' } }).addTo(map);
        }
    }
}

function refreshMarkers() {
    markersLayer.clearLayers();
    renderDepotMarkers();
    state.clients.forEach(c => {
        L.circleMarker([c.lat, c.lon], { radius: 6, fillColor: '#5ac8fa', color: '#fff', weight: 1, fillOpacity: 0.85 })
            .bindPopup(`<b>${escapeHtml(c.nom_client)}</b><br>${escapeHtml(c.adresse)}<br>Volume : ${c.volume} m³` + (c.poids_kg ? `<br>Poids : ${c.poids_kg} kg` : ''))
            .addTo(markersLayer);
    });
}

function fitMapToData() {
    const pts = [
        ...state.agencies.map(a => [a.lat, a.lon]),
        ...state.trucks.filter(t => t.lat && t.lon).map(t => [t.lat, t.lon]),
        ...state.clients.map(c => [c.lat, c.lon]),
    ];
    if (pts.length === 0) return;
    map.fitBounds(L.latLngBounds(pts).pad(0.12));
}

/* ═══════════════════════════════════════════════════════════════════
   Route card & stop list (with drag&drop)
   ═══════════════════════════════════════════════════════════════════ */

function buildRouteCard(route, index) {
    const color = ROUTE_COLORS[index % ROUTE_COLORS.length];
    const isEditing = state.editingRoute === index;
    const hours = Math.floor(route.duration);
    const minutes = Math.round((route.duration % 1) * 60);

    const card = document.createElement('div');
    card.className = `route-card${isEditing ? ' is-editing' : ''}`;
    card.dataset.routeIndex = String(index);

    const depotLabel = route.agency.id_nom === route.truck.id
        ? escapeHtml(route.truck.id)
        : `${escapeHtml(route.truck.id)} · ${escapeHtml(route.agency.id_nom)}`;

    const consoLabel = route.truck.consommation_l100km > 0
        ? `${route.truck.consommation_l100km} L/100km` : 'conso défaut';

    const driverHtml = route.truck.chauffeur
        ? `<div class="route-driver">👤 ${escapeHtml(route.truck.chauffeur)}` +
          (route.truck.tel_chauffeur ? ` · ${escapeHtml(route.truck.tel_chauffeur)}` : '') + `</div>`
        : '';

    const realStops = route.points.filter(p => !p.is_break).length;
    const modified = route.modified ? '<span class="modified-dot" title="Modifié, recalcule pour MAJ"></span>' : '';

    card.innerHTML =
        `<div class="route-card-head">` +
            `<div class="route-color-pill" style="background:${color}"></div>` +
            `<span class="route-card-title">${depotLabel}</span>` +
            `<div class="route-card-actions">` +
                `<button class="mini-btn print-btn" title="Feuille de route imprimable">🖨</button>` +
                `<button class="mini-btn export-btn" title="Exporter en CSV">📥</button>` +
                `<button class="mini-btn edit-btn${isEditing ? ' is-active' : ''}" title="${isEditing ? 'Fermer' : 'Modifier'}">✎</button>` +
            `</div>` +
        `</div>` +
        driverHtml +
        `<div class="route-stats-grid">` +
            `<div class="stat-line">📦 <strong>${realStops}</strong> stops</div>` +
            `<div class="stat-line">${route.totalVolume.toFixed(1)}/${route.truck.volume_max} m³${route.totalPoids ? ' · ' + route.totalPoids + ' kg' : ''}</div>` +
            `<div class="stat-line">🛣 <strong>${route.distance.toFixed(1)}</strong> km${modified}</div>` +
            `<div class="stat-line">⏱ ${hours}h${minutes ? minutes + 'min' : ''}</div>` +
            `<div class="stat-line">⛽ ${consoLabel}</div>` +
            `<div class="route-cost-line">💰 ${route.totalCost.toFixed(2)} €</div>` +
        `</div>`;

    if (isEditing) card.appendChild(buildStopList(route, index));

    card.querySelector('.edit-btn').addEventListener('click', (e) => { e.stopPropagation(); toggleEditRoute(index); });
    card.querySelector('.export-btn').addEventListener('click', (e) => { e.stopPropagation(); exportRouteCSV(index); });
    card.querySelector('.print-btn').addEventListener('click', (e) => { e.stopPropagation(); printRoute(index); });

    card.addEventListener('click', (e) => {
        if (e.target.closest('button, select, .stop-item')) return;
        const pts = [route.agency, ...route.points];
        if (pts.length) map.fitBounds(L.latLngBounds(pts.map(p => [p.lat, p.lon])).pad(0.15));
    });

    return card;
}

function buildStopList(route, index) {
    const c = document.createElement('div');
    c.className = 'stop-list';
    c.dataset.routeIndex = String(index);

    const isOwnBase = route.agency.id_nom === route.truck.id;
    const depotName = isOwnBase
        ? `${route.agency.id_nom} — ${route.agency.ville || route.agency.adresse || 'base'}`
        : route.agency.id_nom;
    const depotIcon = isOwnBase ? '🚛' : '🏭';

    const dStart = document.createElement('div');
    dStart.className = 'depot-line';
    dStart.textContent = `${depotIcon} ${depotName} · départ`;
    c.appendChild(dStart);

    route.points.forEach((point, stopIdx) => {
        const isFirst = stopIdx === 0;
        const isLast  = stopIdx === route.points.length - 1;

        if (point.is_break) {
            const item = document.createElement('div');
            item.className = 'stop-break';
            item.textContent = '⏸  Pause réglementaire — 45 min';
            c.appendChild(item);
            return;
        }

        const moveOpts = state.routes.map((r, i) => i !== index
            ? `<option value="${i}">${escapeHtml(r.truck.id)}</option>` : '').join('');

        const statusCls = point.status ? `stop-${point.status}` : '';

        const item = document.createElement('div');
        item.className = `stop-item ${statusCls}`;
        item.draggable = true;
        item.dataset.routeIndex = String(index);
        item.dataset.stopIndex  = String(stopIdx);

        const twLabel = (point.tw_start != null || point.tw_end != null)
            ? `<span class="stop-tag tw">${point.tw_start != null ? minsToHHMM(point.tw_start) : ''}–${point.tw_end != null ? minsToHHMM(point.tw_end) : ''}</span>` : '';
        const arrLabel = point.arrival_min != null
            ? `<span class="stop-tag arr">↪${minsToHHMM(point.arrival_min)}</span>` : '';
        const p = point.priorite ?? 2;
        const prioBadge = p === 1 ? `<span class="priority-badge priority-urgent">URGENT</span>`
            : p === 3 ? `<span class="priority-badge priority-flexible">FLEX</span>` : '';

        item.innerHTML =
            `<span class="stop-handle" title="Glisser pour réorganiser">⠿</span>` +
            `<span class="stop-order">${stopIdx + 1}</span>` +
            prioBadge +
            `<span class="stop-name" title="${escapeHtml(point.nom_client)}">${escapeHtml(point.nom_client)}</span>` +
            twLabel + arrLabel +
            `<span class="stop-tag">${point.volume} m³</span>` +
            (point.poids_kg ? `<span class="stop-tag poids">${point.poids_kg} kg</span>` : '') +
            `<div class="stop-controls">` +
                `<span class="status-btns">` +
                    `<button class="btn-status${point.status === 'delivered' ? ' active' : ''}" data-action="status-delivered" title="Livré">✓</button>` +
                    `<button class="btn-status${point.status === 'failed'    ? ' active' : ''}" data-action="status-failed"    title="Raté">✗</button>` +
                    `<button class="btn-status${point.status === 'returned'  ? ' active' : ''}" data-action="status-returned"  title="Retour dépôt">↩</button>` +
                `</span>` +
                `<button class="mini-icon-btn" data-action="up"   title="Monter"    ${isFirst ? 'disabled' : ''}>▲</button>` +
                `<button class="mini-icon-btn" data-action="down" title="Descendre" ${isLast ? 'disabled' : ''}>▼</button>` +
                (moveOpts ? `<select class="move-select" title="Déplacer vers"><option value="">→</option>${moveOpts}</select>` : '') +
                `<button class="mini-icon-btn danger" data-action="remove" title="Retirer">×</button>` +
            `</div>`;

        item.querySelector('[data-action="up"]').addEventListener('click', () => moveStopUp(index, stopIdx));
        item.querySelector('[data-action="down"]').addEventListener('click', () => moveStopDown(index, stopIdx));
        item.querySelector('[data-action="remove"]').addEventListener('click', () => removeStop(index, stopIdx));
        item.querySelector('.move-select')?.addEventListener('change', (e) => {
            const to = parseInt(e.target.value, 10);
            if (!isNaN(to)) moveStop(index, stopIdx, to);
        });
        item.querySelectorAll('.btn-status').forEach(btn => {
            btn.addEventListener('click', () => setStopStatus(index, stopIdx, btn.dataset.action.replace('status-', '')));
        });

        attachDnD(item);
        c.appendChild(item);
    });

    const dEnd = document.createElement('div');
    dEnd.className = 'depot-line';
    dEnd.textContent = `${depotIcon} ${depotName} · retour`;
    c.appendChild(dEnd);

    const actions = document.createElement('div');
    actions.className = 'stop-row-actions';
    actions.innerHTML =
        `<button class="btn btn-primary btn-sm" id="recalc-${index}">↻ Recalculer</button>` +
        `<button class="btn btn-ghost  btn-sm" id="print-${index}">🖨 Feuille route</button>`;
    actions.querySelector(`#recalc-${index}`).addEventListener('click', () => recalculateRoute(index));
    actions.querySelector(`#print-${index}`).addEventListener('click', () => printRoute(index));
    c.appendChild(actions);

    return c;
}

/* ─── Drag & drop ─── */
let dragSource = null;
function attachDnD(el) {
    el.addEventListener('dragstart', (e) => {
        dragSource = { route: parseInt(el.dataset.routeIndex, 10), stop: parseInt(el.dataset.stopIndex, 10) };
        el.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', JSON.stringify(dragSource));
    });
    el.addEventListener('dragend', () => {
        el.classList.remove('is-dragging');
        document.querySelectorAll('.stop-item.is-drop-target').forEach(x => x.classList.remove('is-drop-target'));
    });
    el.addEventListener('dragover', (e) => {
        if (!dragSource) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        document.querySelectorAll('.stop-item.is-drop-target').forEach(x => x.classList.remove('is-drop-target'));
        el.classList.add('is-drop-target');
    });
    el.addEventListener('drop', (e) => {
        e.preventDefault();
        if (!dragSource) return;
        const target = { route: parseInt(el.dataset.routeIndex, 10), stop: parseInt(el.dataset.stopIndex, 10) };
        const src = dragSource;
        dragSource = null;
        el.classList.remove('is-drop-target');
        if (src.route === target.route && src.stop === target.stop) return;

        // Reorder inside same route or move across routes
        if (src.route === target.route) {
            const pts = state.routes[src.route].points;
            const [moved] = pts.splice(src.stop, 1);
            const insertIdx = src.stop < target.stop ? target.stop : target.stop;
            pts.splice(insertIdx, 0, moved);
            state.routes[src.route].modified = true;
        } else {
            const [moved] = state.routes[src.route].points.splice(src.stop, 1);
            state.routes[src.route].totalVolume = Math.max(0, state.routes[src.route].totalVolume - (moved.volume ?? 0));
            state.routes[src.route].modified = true;
            state.routes[target.route].points.splice(target.stop, 0, moved);
            state.routes[target.route].totalVolume += moved.volume ?? 0;
            state.routes[target.route].modified = true;
        }
        renderLegend(); renderMapState();
    });
}

/* ═══════════════════════════════════════════════════════════════════
   Data table modal
   ═══════════════════════════════════════════════════════════════════ */

function renderDataTable() {
    const header = $('#table-header'); const body = $('#table-body');
    header.innerHTML = ''; body.innerHTML = '';
    if (state.clients.length === 0) return;
    const keys = Object.keys(state.clients[0]).filter(k => k !== 'lat' && k !== 'lon');
    keys.forEach(k => { const th = document.createElement('th'); th.textContent = k.toUpperCase(); header.appendChild(th); });
    state.clients.forEach(c => {
        const tr = document.createElement('tr');
        keys.forEach(k => { const td = document.createElement('td'); td.textContent = c[k] ?? ''; tr.appendChild(td); });
        body.appendChild(tr);
    });
}

/* ═══════════════════════════════════════════════════════════════════
   Sessions & CSV export
   ═══════════════════════════════════════════════════════════════════ */

function exportSession() {
    const payload = JSON.stringify({
        version: 2, agencies: state.agencies, trucks: state.trucks, clients: state.clients,
        routes: state.routes, unassigned: state.unassigned, config: getConfig(),
    }, null, 2);
    const blob = new Blob([payload], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = 'session_tourneo.json'; a.click();
    URL.revokeObjectURL(url);
    toast('Session exportée');
}

function importSession(file) {
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const d = JSON.parse(e.target.result);
            if (!d.version) throw new Error('Format invalide');
            state.agencies = d.agencies ?? []; state.trucks = d.trucks ?? [];
            state.clients = d.clients ?? []; state.routes = d.routes ?? [];
            state.unassigned = d.unassigned ?? []; state.editingRoute = null;
            $('#agency-count').textContent = state.agencies.length;
            $('#truck-count').textContent  = state.trucks.length;
            $('#client-count').textContent = state.clients.length;
            $('#generate-btn').disabled  = state.clients.length === 0;
            $('#view-data-btn').disabled = state.clients.length === 0;
            $('#snapshot-btn').disabled  = state.routes.length === 0;
            $('#export-all-btn').disabled = state.routes.length === 0;
            refreshMarkers();
            if (state.routes.length > 0) { renderAll(); $('#empty-state').hidden = true; $('#drawer').classList.add('is-open'); }
            fitMapToData();
            toast('Session chargée');
        } catch (err) { toast('Fichier de session invalide : ' + err.message, 'error'); }
    };
    reader.readAsText(file);
}

function arraysToCsv(rows) {
    return '\uFEFF' + rows.map(row => row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
}

function triggerDownload(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a'); link.href = url; link.download = filename; link.click();
    URL.revokeObjectURL(url);
}

function exportRouteCSV(index) {
    const r = state.routes[index]; if (!r) return;
    const pts = [r.agency, ...r.points, r.agency];
    const rows = [['ordre','nom','adresse','ville','code_postal','volume_m3','poids_kg']];
    pts.forEach((p, o) => rows.push([o, p.nom_client ?? p.id_nom ?? '', p.adresse ?? '', p.ville ?? '', p.code_postal ?? '', p.volume ?? 0, p.poids_kg ?? 0]));
    triggerDownload(arraysToCsv(rows), `tournee_${(r.truck.id ?? 'camion').replace(/[^a-z0-9]/gi, '_')}.csv`);
}

function exportAllRoutesCSV() {
    if (state.routes.length === 0) return;
    const rows = [['tournee','camion','ordre','nom','adresse','ville','code_postal','volume_m3','poids_kg','distance_km','duree_h','cout_total_eur']];
    state.routes.forEach((r, i) => {
        const pts = [r.agency, ...r.points, r.agency];
        pts.forEach((p, o) => rows.push([
            i + 1, r.truck.id ?? '', o, p.nom_client ?? p.id_nom ?? '', p.adresse ?? '', p.ville ?? '', p.code_postal ?? '',
            p.volume ?? 0, p.poids_kg ?? 0,
            o === 0 ? (r.distance?.toFixed(1) ?? '') : '', o === 0 ? (r.duration?.toFixed(2) ?? '') : '', o === 0 ? (r.totalCost?.toFixed(2) ?? '') : '',
        ]));
    });
    triggerDownload(arraysToCsv(rows), 'toutes_les_tournees.csv');
}

/* ═══════════════════════════════════════════════════════════════════
   Misc
   ═══════════════════════════════════════════════════════════════════ */

function getConfig() {
    return {
        fuelPrice:    parseFloat($('#fuel-price').value)    || 1.85,
        defaultConso: parseFloat($('#truck-conso').value)   || 15,
        hourlyRate:   parseFloat($('#hourly-rate').value)   || 25,
        avgSpeed:     parseFloat($('#avg-speed').value)     || 60,
        serviceTime:  parseFloat($('#service-time').value)  || 15,
        startTime:    $('#start-time').value || '08:00',
        osrmTimeout:  parseInt($('#osrm-timeout').value, 10) || 30,
        applyBreaks:  $('#apply-breaks').checked,
        algoMode:     $('#algo-mode').value || 'standard',
    };
}

function setStopStatus(routeIndex, stopIndex, status) {
    const stop = state.routes[routeIndex].points[stopIndex];
    stop.status = stop.status === status ? null : status;
    renderLegend();
}

function printRoute(index) {
    const route = state.routes[index];
    const config = getConfig();
    const form = document.createElement('form');
    form.method = 'POST'; form.action = '/api/export/route'; form.target = '_blank'; form.style.display = 'none';
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'payload'; input.value = JSON.stringify({ route, config });
    form.appendChild(input); document.body.appendChild(form); form.submit();
    setTimeout(() => document.body.removeChild(form), 1000);
}

/* ═══════════════════════════════════════════════════════════════════
   Templates (localStorage)
   ═══════════════════════════════════════════════════════════════════ */

const TEMPLATES_KEY = 'tourneo_templates';
function getTemplates() { try { return JSON.parse(localStorage.getItem(TEMPLATES_KEY) || '[]'); } catch { return []; } }

function saveTemplate(name) {
    if (!name.trim()) { toast('Donne un nom au modèle', 'warn'); return; }
    if (state.agencies.length === 0 && state.clients.length === 0) { toast('Rien à enregistrer', 'warn'); return; }
    const tpls = getTemplates();
    const tpl = { name: name.trim(), agencies: state.agencies, trucks: state.trucks, clients: state.clients, savedAt: new Date().toLocaleDateString('fr-FR') };
    const ex = tpls.findIndex(t => t.name === name.trim());
    if (ex >= 0) tpls[ex] = tpl; else tpls.unshift(tpl);
    localStorage.setItem(TEMPLATES_KEY, JSON.stringify(tpls.slice(0, 10)));
    renderTemplates();
    toast('Modèle enregistré');
}

function loadTemplate(name) {
    const tpl = getTemplates().find(t => t.name === name); if (!tpl) return;
    if (!confirm(`Charger le modèle "${name}" ? L'état actuel sera remplacé.`)) return;
    state.agencies = tpl.agencies; state.trucks = tpl.trucks; state.clients = tpl.clients;
    state.routes = []; state.unassigned = []; state.editingRoute = null;
    $('#agency-count').textContent = state.agencies.length;
    $('#truck-count').textContent  = state.trucks.length;
    $('#client-count').textContent = state.clients.length;
    $('#generate-btn').disabled  = state.clients.length === 0;
    $('#view-data-btn').disabled = state.clients.length === 0;
    refreshMarkers(); renderAll(); fitMapToData();
}

function deleteTemplate(name) {
    if (!confirm(`Supprimer "${name}" ?`)) return;
    localStorage.setItem(TEMPLATES_KEY, JSON.stringify(getTemplates().filter(t => t.name !== name)));
    renderTemplates();
}

function renderTemplates() {
    const list = $('#templates-list');
    const tpls = getTemplates();
    if (tpls.length === 0) { list.innerHTML = '<p class="no-data">Aucun modèle enregistré.</p>'; return; }
    list.innerHTML = '';
    tpls.forEach(t => {
        const item = document.createElement('div');
        item.className = 'template-item';
        item.innerHTML =
            `<span class="template-info">` +
                `<strong>${escapeHtml(t.name)}</strong>` +
                `<small>${escapeHtml(t.savedAt)} — ${t.agencies.length} agence(s), ${t.clients.length} cmd</small>` +
            `</span>` +
            `<span class="template-actions">` +
                `<button class="btn btn-sm tpl-load">Charger</button>` +
                `<button class="btn btn-sm btn-danger tpl-delete">✕</button>` +
            `</span>`;
        item.querySelector('.tpl-load').addEventListener('click',   () => loadTemplate(t.name));
        item.querySelector('.tpl-delete').addEventListener('click', () => deleteTemplate(t.name));
        list.appendChild(item);
    });
}

/* ═══════════════════════════════════════════════════════════════════
   History snapshots (localStorage)
   ═══════════════════════════════════════════════════════════════════ */

const HISTORY_KEY = 'tourneo_history';
function getHistory() { try { return JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'); } catch { return []; } }

function saveHistorySnapshot(label) {
    if (state.routes.length === 0) { toast('Aucune tournée à snapshotter', 'warn'); return; }
    const hist = getHistory();
    const cfg  = getConfig();
    const totalDist = state.routes.reduce((s, r) => s + (r.distance ?? 0), 0);
    const totalCost = state.routes.reduce((s, r) => s + (r.totalCost ?? 0), 0);
    const totalStops = state.routes.reduce((s, r) => s + r.points.filter(p => !p.is_break).length, 0);
    const avgFill = state.routes.reduce((s, r) => s + (r.totalVolume / (r.truck.volume_max || 1)) * 100, 0) / state.routes.length;

    const snap = {
        id: Date.now(),
        date: new Date().toLocaleString('fr-FR'),
        label: label || `Snapshot ${new Date().toLocaleTimeString('fr-FR')}`,
        config: cfg,
        summary: {
            routes: state.routes.length,
            stops: totalStops,
            unassigned: state.unassigned.length,
            distance: totalDist,
            cost: totalCost,
            fill: avgFill,
            algo: cfg.algoMode,
        },
        agencies:   state.agencies,
        trucks:     state.trucks,
        clients:    state.clients,
        routes:     state.routes,
        unassigned: state.unassigned,
    };
    hist.unshift(snap);
    localStorage.setItem(HISTORY_KEY, JSON.stringify(hist.slice(0, 20)));
}

function deleteHistory(id) {
    if (!confirm('Supprimer ce snapshot ?')) return;
    localStorage.setItem(HISTORY_KEY, JSON.stringify(getHistory().filter(h => h.id !== id)));
    renderHistoryBody();
}

function loadHistory(id) {
    const h = getHistory().find(x => x.id === id); if (!h) return;
    if (!confirm(`Restaurer "${h.label}" ? L'état actuel sera remplacé.`)) return;
    state.agencies = h.agencies; state.trucks = h.trucks; state.clients = h.clients;
    state.routes = h.routes; state.unassigned = h.unassigned; state.editingRoute = null;
    $('#agency-count').textContent = state.agencies.length;
    $('#truck-count').textContent  = state.trucks.length;
    $('#client-count').textContent = state.clients.length;
    $('#generate-btn').disabled  = state.clients.length === 0;
    $('#view-data-btn').disabled = state.clients.length === 0;
    $('#snapshot-btn').disabled  = state.routes.length === 0;
    $('#export-all-btn').disabled = state.routes.length === 0;
    refreshMarkers(); renderAll(); fitMapToData();
    closeModal('history-modal');
    $('#empty-state').hidden = state.routes.length === 0 ? false : true;
    if (state.routes.length > 0) $('#drawer').classList.add('is-open');
    toast(`Snapshot "${h.label}" restauré`);
}

let compareSelection = [];
function toggleCompareSelect(id, el) {
    const idx = compareSelection.indexOf(id);
    if (idx >= 0) { compareSelection.splice(idx, 1); el.classList.remove('is-active'); }
    else if (compareSelection.length < 2) { compareSelection.push(id); el.classList.add('is-active'); }
    else { toast('Sélectionne max 2 snapshots', 'warn'); return; }
    if (compareSelection.length === 2) renderCompareModal();
}

function openHistoryModal() {
    renderHistoryBody();
    openModal('history-modal');
}

function renderHistoryBody() {
    const body = $('#history-body');
    const hist = getHistory();
    if (hist.length === 0) { body.innerHTML = '<p class="no-data">Aucune tournée historisée pour le moment. Génère puis clique sur 📸 Snapshot.</p>'; return; }
    body.innerHTML = '';
    hist.forEach(h => {
        const it = document.createElement('div');
        it.className = 'history-item';
        it.innerHTML =
            `<div class="history-date">${escapeHtml(h.date)}</div>` +
            `<div class="history-info">` +
                `<span class="history-name">${escapeHtml(h.label)}</span>` +
                `<span class="history-meta">${h.summary.routes} tournées · ${h.summary.stops} stops · ${h.summary.distance.toFixed(0)} km · ${h.summary.cost.toFixed(0)} € · algo:${h.summary.algo}</span>` +
            `</div>` +
            `<div class="history-actions">` +
                `<button class="btn btn-sm hist-load">↺ Charger</button>` +
                `<button class="btn btn-sm hist-del">✕</button>` +
            `</div>`;
        it.querySelector('.hist-load').addEventListener('click', () => loadHistory(h.id));
        it.querySelector('.hist-del').addEventListener('click', () => deleteHistory(h.id));
        body.appendChild(it);
    });
}

/* ═══════════════════════════════════════════════════════════════════
   Compare modal
   ═══════════════════════════════════════════════════════════════════ */

function openCompareModal() {
    compareSelection = [];
    const body = $('#compare-body');
    const hist = getHistory();
    if (hist.length < 2) {
        body.innerHTML = '<p class="no-data">Tu as besoin d\'au moins 2 snapshots pour comparer. Génère des tournées, clique sur 📸 Snapshot, change tes réglages, regénère, snapshotte à nouveau, puis reviens ici.</p>';
        openModal('compare-modal');
        return;
    }
    body.innerHTML = '<p style="color:var(--muted);font-size:.8rem;margin-bottom:14px;">Clique sur 2 snapshots pour les comparer côte à côte.</p>';
    const picker = document.createElement('div');
    picker.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;';
    hist.forEach(h => {
        const chip = document.createElement('button');
        chip.className = 'btn btn-sm';
        chip.style.width = 'auto';
        chip.textContent = h.label;
        chip.addEventListener('click', () => toggleCompareSelect(h.id, chip));
        picker.appendChild(chip);
    });
    body.appendChild(picker);
    const slot = document.createElement('div'); slot.id = 'compare-slot';
    body.appendChild(slot);
    openModal('compare-modal');
}

function renderCompareModal() {
    const hist = getHistory();
    const a = hist.find(h => h.id === compareSelection[0]);
    const b = hist.find(h => h.id === compareSelection[1]);
    if (!a || !b) return;
    const slot = $('#compare-slot');
    const delta = (av, bv, lowerBetter = true) => {
        if (bv === av) return '';
        const better = lowerBetter ? bv < av : bv > av;
        return `<span class="${better ? 'delta-better' : 'delta-worse'}">${bv > av ? '+' : ''}${(bv - av).toFixed(1)}</span>`;
    };
    const fmt = (v, u='') => `${v.toFixed(v < 10 ? 1 : 0)}${u}`;
    slot.innerHTML = `
        <div class="compare-grid">
            <div class="compare-col">
                <h3>📊 ${escapeHtml(a.label)}</h3>
                <div class="delta-row"><span class="label">Tournées</span><span class="value">${a.summary.routes}</span></div>
                <div class="delta-row"><span class="label">Stops</span><span class="value">${a.summary.stops}</span></div>
                <div class="delta-row"><span class="label">Non affectés</span><span class="value">${a.summary.unassigned}</span></div>
                <div class="delta-row"><span class="label">Distance</span><span class="value">${fmt(a.summary.distance, ' km')}</span></div>
                <div class="delta-row"><span class="label">Coût total</span><span class="value">${fmt(a.summary.cost, ' €')}</span></div>
                <div class="delta-row"><span class="label">Remplissage moy.</span><span class="value">${fmt(a.summary.fill, '%')}</span></div>
                <div class="delta-row"><span class="label">Algorithme</span><span class="value">${a.summary.algo}</span></div>
            </div>
            <div class="compare-col">
                <h3>📊 ${escapeHtml(b.label)}</h3>
                <div class="delta-row"><span class="label">Tournées</span><span class="value">${b.summary.routes} ${delta(a.summary.routes, b.summary.routes)}</span></div>
                <div class="delta-row"><span class="label">Stops</span><span class="value">${b.summary.stops} ${delta(a.summary.stops, b.summary.stops, false)}</span></div>
                <div class="delta-row"><span class="label">Non affectés</span><span class="value">${b.summary.unassigned} ${delta(a.summary.unassigned, b.summary.unassigned)}</span></div>
                <div class="delta-row"><span class="label">Distance</span><span class="value">${fmt(b.summary.distance, ' km')} ${delta(a.summary.distance, b.summary.distance)}</span></div>
                <div class="delta-row"><span class="label">Coût total</span><span class="value">${fmt(b.summary.cost, ' €')} ${delta(a.summary.cost, b.summary.cost)}</span></div>
                <div class="delta-row"><span class="label">Remplissage moy.</span><span class="value">${fmt(b.summary.fill, '%')} ${delta(a.summary.fill, b.summary.fill, false)}</span></div>
                <div class="delta-row"><span class="label">Algorithme</span><span class="value">${b.summary.algo}</span></div>
            </div>
        </div>
    `;
}

/* ═══════════════════════════════════════════════════════════════════
   Stats modal (Chart.js)
   ═══════════════════════════════════════════════════════════════════ */

const charts = {};
function openStatsModal() {
    if (state.routes.length === 0) { toast('Génère des tournées avant de voir les stats', 'warn'); return; }
    openModal('stats-modal');
    setTimeout(buildCharts, 80);
}

function buildCharts() {
    if (typeof Chart === 'undefined') return;
    const labels = state.routes.map(r => r.truck.id);
    const costs  = state.routes.map(r => +(r.totalCost ?? 0).toFixed(2));
    const dists  = state.routes.map(r => +(r.distance ?? 0).toFixed(1));
    const fills  = state.routes.map(r => +((r.totalVolume / (r.truck.volume_max || 1)) * 100).toFixed(1));
    const fuels  = state.routes.reduce((s, r) => s + (r.fuelCost ?? 0), 0);
    const labors = state.routes.reduce((s, r) => s + (r.laborCost ?? 0), 0);

    const isLight = document.documentElement.getAttribute('data-theme') === 'light';
    const txt = isLight ? '#0c0c10' : '#ededf0';
    const grid = isLight ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.05)';
    const accent = isLight ? '#1f8a3f' : '#b8ff4d';

    Object.values(charts).forEach(c => c?.destroy?.());

    const opts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { color: txt, font: { family: 'Manrope' } } } },
        scales: {
            x: { ticks: { color: txt, font: { family: 'JetBrains Mono', size: 10 } }, grid: { color: grid } },
            y: { ticks: { color: txt, font: { family: 'JetBrains Mono', size: 10 } }, grid: { color: grid } },
        },
    };

    charts.cost = new Chart($('#chart-cost'), {
        type: 'bar', data: { labels, datasets: [{ label: '€', data: costs, backgroundColor: accent, borderRadius: 4 }] }, options: opts,
    });
    charts.dist = new Chart($('#chart-dist'), {
        type: 'bar', data: { labels, datasets: [{ label: 'km', data: dists, backgroundColor: '#5ac8fa', borderRadius: 4 }] }, options: opts,
    });
    charts.fill = new Chart($('#chart-fill'), {
        type: 'bar', data: { labels, datasets: [{ label: '%', data: fills, backgroundColor: '#fbbf24', borderRadius: 4 }] }, options: opts,
    });
    charts.pie = new Chart($('#chart-pie'), {
        type: 'doughnut',
        data: { labels: ['Carburant', 'Main-d\'œuvre'], datasets: [{ data: [+fuels.toFixed(2), +labors.toFixed(2)], backgroundColor: [accent, '#5ac8fa'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: txt, font: { family: 'Manrope' } } } } },
    });
}

/* ═══════════════════════════════════════════════════════════════════
   Fullscreen / Heatmap
   ═══════════════════════════════════════════════════════════════════ */

function enterFullscreen() {
    document.body.classList.add('fullscreen-map');
    setTimeout(() => map.invalidateSize(), 250);
}
function exitFullscreen() {
    document.body.classList.remove('fullscreen-map');
    setTimeout(() => map.invalidateSize(), 250);
}

function toggleHeatmap() {
    state.heatmapOn = !state.heatmapOn;
    $('#btn-heatmap').classList.toggle('is-active', state.heatmapOn);
    renderMapState();
}

/* ═══════════════════════════════════════════════════════════════════
   Modals helpers
   ═══════════════════════════════════════════════════════════════════ */

function openModal(id) { const m = document.getElementById(id); if (m) m.hidden = false; }
function closeModal(id) { const m = document.getElementById(id); if (m) m.hidden = true; }

/* ═══════════════════════════════════════════════════════════════════
   Toasts, loading, utils
   ═══════════════════════════════════════════════════════════════════ */

function toast(msg, type = 'info') {
    const c = $('#toasts'); if (!c) return;
    const t = document.createElement('div');
    t.className = `toast ${type === 'error' ? 'is-error' : type === 'warn' ? 'is-warn' : ''}`;
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(40px)'; t.style.transition = 'all .3s'; }, 3000);
    setTimeout(() => t.remove(), 3500);
}

function showLoading(text) {
    const o = $('#loading-overlay'); $('#loading-text').textContent = text;
    o.style.display = 'flex'; o.setAttribute('aria-busy', 'true');
}
function hideLoading() {
    const o = $('#loading-overlay'); o.style.display = 'none'; o.setAttribute('aria-busy', 'false');
}

function addLog(message, isError = false) {
    const log = $('#geocoding-log');
    const d = document.createElement('div');
    d.className = isError ? 'log-error' : 'log-success';
    d.textContent = `> ${message}`;
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
}

function escapeHtml(v) {
    return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function minsToHHMM(mins) {
    const h = Math.floor(mins / 60); const m = mins % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

async function apiPost(url, body, contentType) {
    const headers = contentType ? { 'Content-Type': contentType } : {};
    const r = await fetch(url, { method: 'POST', headers, body });
    const d = await r.json();
    if (!r.ok) throw new Error(d.error ?? `Erreur HTTP ${r.status}`);
    return d;
}
