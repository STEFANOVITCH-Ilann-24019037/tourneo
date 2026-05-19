'use strict';

const ROUTE_COLORS = [
    '#e74c3c', '#2ecc71', '#3498db', '#f1c40f', '#9b59b6',
    '#1abc9c', '#e67e22', '#34495e', '#d35400', '#27ae60',
];

const state = {
    agencies:     [],
    trucks:       [],
    clients:      [],
    routes:       [],
    unassigned:   [],
    editingRoute: null,
};

let map;
let markersLayer;
let routesLayer;

// ── Init ──────────────────────────────────────────────────────────────

function initMap() {
    map = L.map('map').setView([46.603354, 1.888334], 6);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 20,
    }).addTo(map);

    markersLayer = L.layerGroup().addTo(map);
    routesLayer  = L.layerGroup().addTo(map);

    setupUI();
}

function setupUI() {
    const menuToggle = document.getElementById('menu-toggle');
    const sidebar    = document.querySelector('.sidebar');
    const overlay    = document.getElementById('mobile-overlay');

    function toggleMenu() {
        const isOpen = sidebar.classList.toggle('active');
        menuToggle.classList.toggle('active', isOpen);
        overlay.classList.toggle('active', isOpen);
        menuToggle.setAttribute('aria-expanded', String(isOpen));
    }

    menuToggle.addEventListener('click', toggleMenu);
    overlay.addEventListener('click', toggleMenu);

    ['fleet-file', 'orders-file'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => {
            if (window.innerWidth <= 768 && sidebar.classList.contains('active')) toggleMenu();
        });
    });

    const modal    = document.getElementById('data-modal');
    const viewBtn  = document.getElementById('view-data-btn');
    const closeBtn = document.querySelector('.close-modal');

    viewBtn.addEventListener('click', () => {
        renderDataTable();
        modal.hidden = false;
        closeBtn.focus();
    });
    closeBtn.addEventListener('click', () => { modal.hidden = true; });
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.hidden = true; });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) modal.hidden = true;
    });

    document.getElementById('fleet-file').addEventListener('change', onFleetUpload);
    document.getElementById('orders-file').addEventListener('change', onOrdersUpload);
    document.getElementById('generate-btn').addEventListener('click', onGenerate);
    document.getElementById('save-session-btn').addEventListener('click', exportSession);
    document.getElementById('load-session-file').addEventListener('change', (e) => {
        if (e.target.files[0]) importSession(e.target.files[0]);
        e.target.value = '';
    });
    document.getElementById('export-all-btn').addEventListener('click', exportAllRoutesCSV);
}

// ── Uploads ───────────────────────────────────────────────────────────

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
        document.getElementById('agency-count').textContent = state.agencies.length;
        document.getElementById('truck-count').textContent  = state.trucks.length;
        refreshMarkers();
    } catch (err) {
        alert(`Erreur lors de l'import de la flotte : ${err.message}`);
    } finally {
        hideLoading();
    }
}

async function onOrdersUpload(event) {
    if (!confirmReset('commandes')) { event.target.value = ''; return; }

    const file = event.target.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('orders_file', file);

    const logEl = document.getElementById('geocoding-log');
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

        document.getElementById('client-count').textContent = state.clients.length;
        const hasClients = state.clients.length > 0;
        document.getElementById('generate-btn').disabled  = !hasClients;
        document.getElementById('view-data-btn').disabled = !hasClients;

        refreshMarkers();

        if (hasClients) {
            const group = L.featureGroup(state.clients.map(c => L.marker([c.lat, c.lon])));
            map.fitBounds(group.getBounds().pad(0.1));
        }
    } catch (err) {
        alert(`Erreur lors de l'import des commandes : ${err.message}`);
    } finally {
        hideLoading();
    }
}

// ── Génération ────────────────────────────────────────────────────────

async function onGenerate() {
    if (state.agencies.length === 0 || state.trucks.length === 0) {
        alert('Veuillez importer la flotte (agences et camions) avant de générer.');
        return;
    }

    showLoading('Génération des tournées et calcul des itinéraires…');
    try {
        const result = await apiPost(
            '/api/generate',
            JSON.stringify({
                agencies: state.agencies,
                trucks:   state.trucks,
                clients:  state.clients,
                config:   getConfig(),
            }),
            'application/json'
        );

        state.routes       = result.routes;
        state.unassigned   = result.unassignedItems ?? [];
        state.editingRoute = null;

        renderAll();
    } catch (err) {
        alert(`Erreur lors de la génération : ${err.message}`);
    } finally {
        hideLoading();
    }
}

// ── Édition des tournées ──────────────────────────────────────────────

function toggleEditRoute(index) {
    state.editingRoute = state.editingRoute === index ? null : index;
    renderLegend();
    renderMapState();
}

function removeStop(routeIndex, stopIndex) {
    const route      = state.routes[routeIndex];
    const [removed]  = route.points.splice(stopIndex, 1);
    route.totalVolume = Math.max(0, route.totalVolume - (removed.volume ?? 0));
    route.modified    = true;
    state.unassigned.push(removed);
    renderLegend();
    renderMapState();
}

function moveStop(fromRoute, stopIndex, toRoute) {
    const [stop] = state.routes[fromRoute].points.splice(stopIndex, 1);
    state.routes[fromRoute].totalVolume = Math.max(
        0, state.routes[fromRoute].totalVolume - (stop.volume ?? 0)
    );
    state.routes[fromRoute].modified = true;
    state.routes[toRoute].points.push(stop);
    state.routes[toRoute].totalVolume += stop.volume ?? 0;
    state.routes[toRoute].modified = true;
    renderLegend();
    renderMapState();
}

function moveStopUp(routeIndex, stopIndex) {
    if (stopIndex <= 0) return;
    const pts = state.routes[routeIndex].points;
    [pts[stopIndex - 1], pts[stopIndex]] = [pts[stopIndex], pts[stopIndex - 1]];
    state.routes[routeIndex].modified = true;
    renderLegend();
    renderMapState();
}

function moveStopDown(routeIndex, stopIndex) {
    const pts = state.routes[routeIndex].points;
    if (stopIndex >= pts.length - 1) return;
    [pts[stopIndex], pts[stopIndex + 1]] = [pts[stopIndex + 1], pts[stopIndex]];
    state.routes[routeIndex].modified = true;
    renderLegend();
    renderMapState();
}

function assignUnassigned(unassignedIndex, routeIndex) {
    const [stop] = state.unassigned.splice(unassignedIndex, 1);
    state.routes[routeIndex].points.push(stop);
    state.routes[routeIndex].totalVolume += stop.volume ?? 0;
    state.routes[routeIndex].modified = true;
    renderLegend();
    renderMapState();
}

async function recalculateRoute(index) {
    const route = state.routes[index];
    showLoading('Recalcul de l\'itinéraire…');
    try {
        const data = await apiPost(
            '/api/recalculate',
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

        renderLegend();
        renderMapState();
    } catch (err) {
        alert(`Erreur lors du recalcul : ${err.message}`);
    } finally {
        hideLoading();
    }
}

// ── Rendu principal ───────────────────────────────────────────────────

function renderDepotMarkers() {
    const agencyIcon = L.divIcon({
        className: 'agency-icon',
        html: '<div class="agency-marker">A</div>',
        iconSize: [30, 30],
    });
    state.agencies.forEach(agency => {
        L.marker([agency.lat, agency.lon], { icon: agencyIcon })
            .bindPopup(`<b>Agence : ${escapeHtml(agency.id_nom)}</b>`)
            .addTo(markersLayer);
    });

    const agencyCoords = new Set(state.agencies.map(a => `${a.lat},${a.lon}`));
    const shownBases   = new Set();

    state.trucks.forEach(truck => {
        if (!truck.lat || !truck.lon) return;
        const key = `${truck.lat},${truck.lon}`;
        if (agencyCoords.has(key) || shownBases.has(key)) return;
        shownBases.add(key);
        const icon = L.divIcon({
            className: 'agency-icon',
            html: '<div class="agency-marker truck-base">🚛</div>',
            iconSize: [34, 34],
        });
        L.marker([truck.lat, truck.lon], { icon })
            .bindPopup(`<b>Base : ${escapeHtml(truck.id)}</b><br>${escapeHtml(truck.adresse || '')} ${escapeHtml(truck.ville || '')}`)
            .addTo(markersLayer);
    });
}

function renderAll() {
    document.getElementById('legend-section').hidden = false;
    renderLegend();
    renderMapState();
}

function renderLegend() {
    const legend = document.getElementById('route-legend');
    legend.innerHTML = '';

    state.routes.forEach((route, index) => {
        legend.appendChild(buildRouteCard(route, index));
    });

    if (state.unassigned.length > 0) {
        legend.appendChild(buildUnassignedSection());
    }

    updateTotals();
}

function renderMapState() {
    markersLayer.clearLayers();
    routesLayer.clearLayers();

    const editing = state.editingRoute;

    renderDepotMarkers();

    // Tournées
    state.routes.forEach((route, index) => {
        const color     = ROUTE_COLORS[index % ROUTE_COLORS.length];
        const dimmed    = editing !== null && index !== editing;
        const lineOp    = dimmed ? 0.12 : 0.7;
        const markerOp  = dimmed ? 0.15 : 0.9;
        const radius    = index === editing ? 10 : 8;

        if (route.geometry) {
            L.geoJSON(route.geometry, {
                style: { color, weight: index === editing ? 5 : 4, opacity: lineOp },
            }).addTo(routesLayer);
        }

        route.points.forEach((point, order) => {
            L.circleMarker([point.lat, point.lon], {
                radius,
                fillColor:   color,
                color:       '#fff',
                weight:      2,
                opacity:     markerOp,
                fillOpacity: markerOp,
            })
            .bindPopup(
                `<b>${escapeHtml(point.nom_client)}</b><br>` +
                `Camion : ${escapeHtml(route.truck.id)}<br>` +
                `Ordre : ${order + 1}<br>` +
                `Volume : ${point.volume} m³` +
                (point.poids_kg ? `<br>Poids : ${point.poids_kg} kg` : '') +
                (point.arrival_min != null ? `<br>Arrivée : ${minsToHHMM(point.arrival_min)}` : '') +
                ((point.tw_start != null || point.tw_end != null)
                    ? `<br>Créneau : ${point.tw_start != null ? minsToHHMM(point.tw_start) : ''}–${point.tw_end != null ? minsToHHMM(point.tw_end) : ''}`
                    : '')
            )
            .addTo(markersLayer);
        });
    });

    // Points non affectés (gris)
    state.unassigned.forEach(point => {
        L.circleMarker([point.lat, point.lon], {
            radius: 8, fillColor: '#888', color: '#fff',
            weight: 2, opacity: 0.6, fillOpacity: 0.6,
        })
        .bindPopup(`<b>${escapeHtml(point.nom_client)}</b><br><i>Non affecté</i>`)
        .addTo(markersLayer);
    });
}

// Utilisé avant génération pour afficher agences + bases camions + clients bruts
function refreshMarkers() {
    markersLayer.clearLayers();

    renderDepotMarkers();

    state.clients.forEach(client => {
        L.circleMarker([client.lat, client.lon], {
            radius: 6, fillColor: '#3498db', color: '#fff', weight: 1, fillOpacity: 0.8,
        })
        .bindPopup(
            `<b>${escapeHtml(client.nom_client)}</b><br>` +
            `${escapeHtml(client.adresse)}<br>Volume : ${client.volume} m³` +
            (client.poids_kg ? `<br>Poids : ${client.poids_kg} kg` : '')
        )
        .addTo(markersLayer);
    });
}

function updateTotals() {
    const totalCost = state.routes.reduce((s, r) => s + (r.totalCost ?? 0), 0);
    const totalDist = state.routes.reduce((s, r) => s + (r.distance  ?? 0), 0);
    document.getElementById('total-summary').innerHTML =
        `<div>Distance Totale : <b>${totalDist.toFixed(1)} km</b></div>` +
        `<div>Coût Total Estimé : <b>${totalCost.toFixed(2)} €</b></div>`;
}

// ── Construction des cartes de tournée ────────────────────────────────

function buildRouteCard(route, index) {
    const color     = ROUTE_COLORS[index % ROUTE_COLORS.length];
    const isEditing = state.editingRoute === index;
    const hours     = Math.floor(route.duration);
    const minutes   = Math.round((route.duration % 1) * 60);

    const card = document.createElement('div');
    card.className = `route-item${isEditing ? ' editing' : ''}`;
    card.style.borderLeftColor = color;

    const modifiedBadge = route.modified
        ? '<span class="badge-modified" title="Itinéraire modifié, pensez à recalculer">●</span>'
        : '';

    // Si le dépôt est la base propre du camion, ne pas doubler le nom
    const depotLabel = route.agency.id_nom === route.truck.id
        ? escapeHtml(route.truck.id)
        : `${escapeHtml(route.truck.id)} — ${escapeHtml(route.agency.id_nom)}`;

    const consoLabel = route.truck.consommation_l100km > 0
        ? `${route.truck.consommation_l100km} L/100km`
        : 'conso défaut';

    card.innerHTML =
        `<div class="route-header">` +
            `<span class="route-color" style="background:${color}" aria-hidden="true"></span>` +
            `<span class="route-info">${depotLabel}</span>` +
            `<button class="btn-edit${isEditing ? ' active' : ''}" title="${isEditing ? 'Fermer' : 'Modifier la tournée'}">✏️</button>` +
            `<button class="btn-export" title="Exporter en CSV">📥</button>` +
        `</div>` +
        `<div class="route-stats">` +
            `<div>📦 ${route.points.length} pts | ${route.totalVolume.toFixed(1)}/${route.truck.volume_max} m³` +
            (route.totalPoids ? ` | ${route.totalPoids} kg` : '') +
        `</div>` +
            `<div>🛣️ ${route.distance.toFixed(1)} km ${modifiedBadge}</div>` +
            `<div>⏱️ ${hours}h ${minutes}min</div>` +
            `<div>⛽ ${consoLabel}</div>` +
            `<div class="route-cost">Coût : ${route.totalCost.toFixed(2)} €</div>` +
        `</div>`;

    if (isEditing) {
        card.appendChild(buildStopList(route, index));
    }

    card.querySelector('.btn-edit').addEventListener('click', () => toggleEditRoute(index));
    card.querySelector('.btn-export').addEventListener('click', () => exportRouteCSV(index));

    card.addEventListener('click', (e) => {
        if (e.target.closest('button, select')) return;
        const pts = [route.agency, ...route.points];
        if (!pts.length) return;
        map.fitBounds(L.latLngBounds(pts.map(p => [p.lat, p.lon])).pad(0.15));
    });

    return card;
}

function buildStopList(route, index) {
    const container = document.createElement('div');
    container.className = 'stop-list';

    const isOwnBase   = route.agency.id_nom === route.truck.id;
    const depotIcon   = isOwnBase ? '🚛' : '🏭';
    const depotName   = isOwnBase
        ? `${route.agency.id_nom} — ${route.agency.ville || route.agency.adresse || 'base'}`
        : route.agency.id_nom;

    const depotStart = document.createElement('div');
    depotStart.className = 'stop-depot';
    depotStart.textContent = `${depotIcon} ${depotName} (départ)`;
    container.appendChild(depotStart);

    route.points.forEach((point, stopIdx) => {
        const isFirst = stopIdx === 0;
        const isLast  = stopIdx === route.points.length - 1;

        const moveOptions = state.routes
            .map((r, i) => i !== index
                ? `<option value="${i}">${escapeHtml(r.truck.id)}</option>`
                : '')
            .join('');

        const item = document.createElement('div');
        item.className = 'stop-item';
        const twLabel = (point.tw_start != null || point.tw_end != null)
            ? `<span class="stop-tw">${point.tw_start != null ? minsToHHMM(point.tw_start) : ''}–${point.tw_end != null ? minsToHHMM(point.tw_end) : ''}</span>`
            : '';
        const arrLabel = point.arrival_min != null
            ? `<span class="stop-arrival">↪${minsToHHMM(point.arrival_min)}</span>`
            : '';

        item.innerHTML =
            `<span class="stop-order">${stopIdx + 1}</span>` +
            `<span class="stop-name" title="${escapeHtml(point.nom_client)}">${escapeHtml(point.nom_client)}</span>` +
            twLabel + arrLabel +
            `<span class="stop-vol">${point.volume} m³</span>` +
            (point.poids_kg ? `<span class="stop-poids">${point.poids_kg} kg</span>` : '') +
            `<div class="stop-controls">` +
                `<button class="stop-btn" data-action="up"     title="Monter"    ${isFirst ? 'disabled' : ''}>▲</button>` +
                `<button class="stop-btn" data-action="down"   title="Descendre" ${isLast  ? 'disabled' : ''}>▼</button>` +
                (moveOptions
                    ? `<select class="stop-move" title="Déplacer vers une autre tournée"><option value="">→</option>${moveOptions}</select>`
                    : '') +
                `<button class="stop-btn stop-remove" data-action="remove" title="Retirer de la tournée">×</button>` +
            `</div>`;

        item.querySelector('[data-action="up"]').addEventListener('click',   () => moveStopUp(index, stopIdx));
        item.querySelector('[data-action="down"]').addEventListener('click', () => moveStopDown(index, stopIdx));
        item.querySelector('[data-action="remove"]').addEventListener('click', () => removeStop(index, stopIdx));
        item.querySelector('.stop-move')?.addEventListener('change', (e) => {
            const to = parseInt(e.target.value, 10);
            if (!isNaN(to)) moveStop(index, stopIdx, to);
        });

        container.appendChild(item);
    });

    const depotEnd = document.createElement('div');
    depotEnd.className = 'stop-depot';
    depotEnd.textContent = `${depotIcon} ${depotName} (retour)`;
    container.appendChild(depotEnd);

    const actions = document.createElement('div');
    actions.className = 'stop-actions';
    const recalcBtn = document.createElement('button');
    recalcBtn.className = 'btn btn-primary btn-sm';
    recalcBtn.textContent = 'Recalculer l\'itinéraire';
    recalcBtn.addEventListener('click', () => recalculateRoute(index));
    actions.appendChild(recalcBtn);
    container.appendChild(actions);

    return container;
}

function buildUnassignedSection() {
    const section = document.createElement('div');
    section.className = 'unassigned-section';

    const header = document.createElement('div');
    header.className = 'unassigned-header';

    const title = document.createElement('p');
    title.className = 'unassigned-title';
    title.textContent = `⚠️ Non affectés (${state.unassigned.length})`;

    const exportBtn = document.createElement('button');
    exportBtn.className = 'btn-export';
    exportBtn.title = 'Exporter en CSV';
    exportBtn.textContent = '📥';
    exportBtn.addEventListener('click', exportUnassignedCSV);

    header.appendChild(title);
    header.appendChild(exportBtn);
    section.appendChild(header);

    state.unassigned.forEach((point, uIdx) => {
        const routeOptions = state.routes
            .map((r, i) => `<option value="${i}">${escapeHtml(r.truck.id)}</option>`)
            .join('');

        const item = document.createElement('div');
        item.className = 'unassigned-item';
        item.innerHTML =
            `<span class="stop-name" title="${escapeHtml(point.nom_client)}">${escapeHtml(point.nom_client)}</span>` +
            `<span class="stop-vol">${point.volume} m³</span>` +
            (point.poids_kg ? `<span class="stop-poids">${point.poids_kg} kg</span>` : '') +
            `<select class="stop-assign" title="Assigner à une tournée">` +
                `<option value="">+ Tournée</option>${routeOptions}` +
            `</select>`;

        item.querySelector('.stop-assign').addEventListener('change', (e) => {
            const to = parseInt(e.target.value, 10);
            if (!isNaN(to)) assignUnassigned(uIdx, to);
        });

        section.appendChild(item);
    });

    return section;
}

// ── Tableau de données ────────────────────────────────────────────────

function renderDataTable() {
    const header = document.getElementById('table-header');
    const body   = document.getElementById('table-body');
    header.innerHTML = '';
    body.innerHTML   = '';

    if (state.clients.length === 0) return;

    const keys = Object.keys(state.clients[0]).filter(k => k !== 'lat' && k !== 'lon');

    keys.forEach(key => {
        const th = document.createElement('th');
        th.textContent = key.toUpperCase();
        header.appendChild(th);
    });

    state.clients.forEach(client => {
        const tr = document.createElement('tr');
        keys.forEach(key => {
            const td = document.createElement('td');
            td.textContent = client[key] ?? '';
            tr.appendChild(td);
        });
        body.appendChild(tr);
    });
}

// ── Session save / load ───────────────────────────────────────────────

function exportSession() {
    const payload = JSON.stringify({
        version:    1,
        agencies:   state.agencies,
        trucks:     state.trucks,
        clients:    state.clients,
        routes:     state.routes,
        unassigned: state.unassigned,
        config:     getConfig(),
    }, null, 2);
    const blob = new Blob([payload], { type: 'application/json' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'session_tourneo.json'; a.click();
    URL.revokeObjectURL(url);
}

function importSession(file) {
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const d = JSON.parse(e.target.result);
            if (!d.version) throw new Error('Format invalide');
            state.agencies   = d.agencies   ?? [];
            state.trucks     = d.trucks     ?? [];
            state.clients    = d.clients    ?? [];
            state.routes     = d.routes     ?? [];
            state.unassigned = d.unassigned ?? [];
            state.editingRoute = null;

            document.getElementById('agency-count').textContent = state.agencies.length;
            document.getElementById('truck-count').textContent  = state.trucks.length;
            document.getElementById('client-count').textContent = state.clients.length;
            document.getElementById('generate-btn').disabled  = state.clients.length === 0;
            document.getElementById('view-data-btn').disabled = state.clients.length === 0;

            refreshMarkers();
            if (state.routes.length > 0) renderAll();
        } catch (err) {
            alert('Fichier de session invalide : ' + err.message);
        }
    };
    reader.readAsText(file);
}

// ── Export CSV ────────────────────────────────────────────────────────

function arraysToCsv(rows) {
    return '﻿' + rows.map(row =>
        row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')
    ).join('\n');
}

function triggerDownload(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href     = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

function exportRouteCSV(index) {
    const route = state.routes[index];
    if (!route) return;

    const allPoints = [route.agency, ...route.points, route.agency];
    const rows = [['ordre', 'nom', 'adresse', 'ville', 'code_postal', 'volume_m3', 'poids_kg']];

    allPoints.forEach((point, order) => {
        rows.push([
            order,
            point.nom_client  ?? point.id_nom ?? '',
            point.adresse     ?? '',
            point.ville       ?? '',
            point.code_postal ?? '',
            point.volume      ?? 0,
            point.poids_kg    ?? 0,
        ]);
    });

    const truckId = (route.truck.id ?? 'camion').replace(/[^a-z0-9]/gi, '_');
    triggerDownload(arraysToCsv(rows), `tournee_${truckId}.csv`);
}

function exportAllRoutesCSV() {
    if (state.routes.length === 0) return;

    const rows = [['tournee', 'camion', 'ordre', 'nom', 'adresse', 'ville', 'code_postal', 'volume_m3', 'poids_kg', 'distance_km', 'duree_h', 'cout_total_eur']];

    state.routes.forEach((route, routeIdx) => {
        const allPoints = [route.agency, ...route.points, route.agency];
        allPoints.forEach((point, order) => {
            rows.push([
                routeIdx + 1,
                route.truck.id ?? '',
                order,
                point.nom_client  ?? point.id_nom ?? '',
                point.adresse     ?? '',
                point.ville       ?? '',
                point.code_postal ?? '',
                point.volume      ?? 0,
                point.poids_kg    ?? 0,
                order === 0 ? (route.distance?.toFixed(1) ?? '') : '',
                order === 0 ? (route.duration?.toFixed(2) ?? '') : '',
                order === 0 ? (route.totalCost?.toFixed(2) ?? '') : '',
            ]);
        });
    });

    triggerDownload(arraysToCsv(rows), 'toutes_les_tournees.csv');
}

function exportUnassignedCSV() {
    if (state.unassigned.length === 0) return;

    const rows = [['nom_client', 'adresse', 'ville', 'code_postal', 'volume_m3', 'poids_kg']];
    state.unassigned.forEach(point => {
        rows.push([
            point.nom_client  ?? '',
            point.adresse     ?? '',
            point.ville       ?? '',
            point.code_postal ?? '',
            point.volume      ?? 0,
            point.poids_kg    ?? 0,
        ]);
    });

    triggerDownload(arraysToCsv(rows), 'commandes_non_affectees.csv');
}

// ── Utilitaires ───────────────────────────────────────────────────────

function getConfig() {
    return {
        fuelPrice:    parseFloat(document.getElementById('fuel-price').value)    || 1.85,
        defaultConso: parseFloat(document.getElementById('truck-conso').value)   || 15,
        hourlyRate:   parseFloat(document.getElementById('hourly-rate').value)   || 25,
        avgSpeed:     parseFloat(document.getElementById('avg-speed').value)     || 60,
        serviceTime:  parseFloat(document.getElementById('service-time').value)  || 15,
        startTime:    document.getElementById('start-time').value                || '08:00',
        osrmTimeout:  parseInt(document.getElementById('osrm-timeout').value, 10) || 30,
    };
}

function minsToHHMM(mins) {
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

async function apiPost(url, body, contentType) {
    const headers  = contentType ? { 'Content-Type': contentType } : {};
    const response = await fetch(url, { method: 'POST', headers, body });
    const data     = await response.json();

    if (!response.ok) {
        throw new Error(data.error ?? `Erreur HTTP ${response.status}`);
    }

    return data;
}

function showLoading(text) {
    const overlay = document.getElementById('loading-overlay');
    document.getElementById('loading-text').textContent = text;
    overlay.style.display = 'flex';
    overlay.setAttribute('aria-busy', 'true');
}

function hideLoading() {
    const overlay = document.getElementById('loading-overlay');
    overlay.style.display = 'none';
    overlay.setAttribute('aria-busy', 'false');
}

function addLog(message, isError = false) {
    const log = document.getElementById('geocoding-log');
    const div = document.createElement('div');
    div.className   = isError ? 'log-error' : 'log-success';
    div.textContent = `> ${message}`;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

window.addEventListener('load', initMap);
