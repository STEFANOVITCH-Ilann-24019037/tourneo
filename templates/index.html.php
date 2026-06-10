<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournéo — Planificateur de tournées</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>

<div class="app-shell">

    <!-- ═══════ Top bar ═══════ -->
    <header class="topbar">
        <button id="menu-toggle" class="menu-toggle" aria-label="Menu" aria-expanded="false" data-testid="menu-toggle">
            <span></span><span></span><span></span>
        </button>

        <div class="brand" data-testid="brand">
            <span class="brand-logo">T</span>
            <span class="brand-name">Tourné<em>o</em></span>
            <span class="brand-tag">v2 · logistics</span>
        </div>

        <span class="topbar-sep"></span>

        <div class="topbar-kpis" id="topbar-kpis" data-testid="topbar-kpis">
            <div class="kpi"><span class="kpi-val" id="kpi-trucks" data-testid="kpi-trucks">0/0</span><span class="kpi-lbl">Camions</span></div>
            <div class="kpi"><span class="kpi-val" id="kpi-stops"  data-testid="kpi-stops">0/0</span><span class="kpi-lbl">Stops</span></div>
            <div class="kpi"><span class="kpi-val" id="kpi-fill" data-accent="true" data-testid="kpi-fill">0%</span><span class="kpi-lbl">Remplissage</span></div>
            <div class="kpi"><span class="kpi-val" id="kpi-distance" data-testid="kpi-distance">0 km</span><span class="kpi-lbl">Distance</span></div>
            <div class="kpi"><span class="kpi-val" id="kpi-cost" data-accent="true" data-testid="kpi-cost">0 €</span><span class="kpi-lbl">Coût total</span></div>
        </div>

        <div class="topbar-actions">
            <button class="icon-btn" id="btn-heatmap" title="Heatmap des livraisons" data-testid="btn-heatmap" aria-label="Heatmap">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="7" opacity=".4"/></svg>
            </button>
            <button class="icon-btn" id="btn-stats" title="Statistiques avancées" data-testid="btn-stats" aria-label="Stats">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18M7 14l4-4 4 4 5-5"/></svg>
            </button>
            <button class="icon-btn" id="btn-history" title="Historique des tournées" data-testid="btn-history" aria-label="Historique">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            </button>
            <button class="icon-btn" id="btn-compare" title="Comparer 2 scénarios" data-testid="btn-compare" aria-label="Comparer">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3v18M15 3v18M3 9h6M15 15h6M3 15h6M15 9h6"/></svg>
            </button>
            <button class="icon-btn" id="btn-fullscreen" title="Plein écran carte" data-testid="btn-fullscreen" aria-label="Plein écran">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9V3h6M21 9V3h-6M3 15v6h6M21 15v6h-6"/></svg>
            </button>
            <button class="icon-btn" id="btn-theme" title="Basculer thème clair/sombre" data-testid="btn-theme" aria-label="Thème">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <button class="btn-cta" id="generate-btn" disabled data-testid="generate-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                Générer
            </button>
            <button class="icon-btn exit-fs" id="btn-exit-fs" title="Quitter plein écran">×</button>
        </div>
    </header>

    <!-- ═══════ Sidebar ═══════ -->
    <aside class="sidebar" id="sidebar" aria-label="Panneau de contrôle">

        <div class="sb-section">
            <div class="sb-head" data-testid="head-flotte"><span class="sb-num">01</span><span class="sb-title">Flotte</span><span class="sb-chevron">▼</span></div>
            <div class="sb-body">
                <label class="file-up" id="fleet-file-label" data-testid="fleet-file-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                    <span id="fleet-file-label-text">Importer flotte (CSV)</span>
                    <input type="file" id="fleet-file" name="fleet_file" accept=".csv">
                </label>
                <div class="stats-row">
                    <div class="stat"><span class="stat-v" id="agency-count" data-state="ok" data-testid="agency-count">0</span><span class="stat-k">Agences</span></div>
                    <div class="stat"><span class="stat-v" id="truck-count"  data-state="ok" data-testid="truck-count">0</span><span class="stat-k">Camions</span></div>
                </div>
            </div>
        </div>

        <div class="sb-section">
            <div class="sb-head" data-testid="head-commandes"><span class="sb-num">02</span><span class="sb-title">Commandes</span><span class="sb-chevron">▼</span></div>
            <div class="sb-body">
                <label class="file-up" id="orders-file-label" data-testid="orders-file-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                    <span id="orders-file-label-text">Importer commandes (CSV)</span>
                    <input type="file" id="orders-file" name="orders_file" accept=".csv">
                </label>
                <div class="stats-row">
                    <div class="stat"><span class="stat-v" id="client-count" data-state="ok" data-testid="client-count">0</span><span class="stat-k">Commandes</span></div>
                </div>
                <div id="geocoding-log" class="log-box" hidden></div>
            </div>
        </div>

        <div class="sb-section">
            <div class="sb-head" data-testid="head-config"><span class="sb-num">03</span><span class="sb-title">Configuration</span><span class="sb-chevron">▼</span></div>
            <div class="sb-body">
                <div class="grid-2">
                    <div class="field"><label>Gazole €/L</label><input type="number" id="fuel-price" value="1.85" min="0" step="0.01" data-testid="fuel-price"></div>
                    <div class="field"><label>Conso L/100</label><input type="number" id="truck-conso" value="15" min="0" step="0.1" data-testid="truck-conso"></div>
                    <div class="field"><label>Salaire €/h</label><input type="number" id="hourly-rate" value="25" min="0" step="1" data-testid="hourly-rate"></div>
                    <div class="field"><label>Vitesse km/h</label><input type="number" id="avg-speed" value="60" min="10" max="200" step="5" data-testid="avg-speed"></div>
                    <div class="field"><label>Livraison min</label><input type="number" id="service-time" value="15" min="0" max="120" step="5" data-testid="service-time"></div>
                    <div class="field"><label>Heure départ</label><input type="time" id="start-time" value="08:00" data-testid="start-time"></div>
                    <div class="field"><label>OSRM timeout</label><input type="number" id="osrm-timeout" value="30" min="10" max="120" step="5" data-testid="osrm-timeout"></div>
                    <div class="field">
                        <label>Algorithme</label>
                        <select id="algo-mode" data-testid="algo-mode">
                            <option value="standard">Standard (2-opt)</option>
                            <option value="advanced">Avancé (2-opt + Or-opt)</option>
                        </select>
                    </div>
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" id="apply-breaks" data-testid="apply-breaks">
                    <span>Pauses réglementaires EU (4h30 max)</span>
                </label>
            </div>
        </div>

        <div class="sb-section">
            <div class="sb-head" data-testid="head-templates"><span class="sb-num">04</span><span class="sb-title">Modèles</span><span class="sb-chevron">▼</span></div>
            <div class="sb-body">
                <div id="templates-list"></div>
                <div class="template-save-row">
                    <input type="text" id="template-name" placeholder="Nom du modèle…" maxlength="50" data-testid="template-name">
                    <button class="btn btn-sm" id="save-template-btn" data-testid="save-template-btn">Enregistrer</button>
                </div>
            </div>
        </div>

        <div class="sb-section">
            <div class="sb-head" data-testid="head-actions"><span class="sb-num">05</span><span class="sb-title">Actions</span><span class="sb-chevron">▼</span></div>
            <div class="sb-body">
                <button class="btn btn-ghost" id="view-data-btn" disabled data-testid="view-data-btn">Voir les données</button>
                <div class="row-actions" style="margin-top: 6px;">
                    <button class="btn btn-ghost btn-sm" id="save-session-btn" data-testid="save-session-btn">Sauvegarder session</button>
                    <label class="btn btn-ghost btn-sm" for="load-session-file" data-testid="load-session-btn">Charger session</label>
                    <input type="file" id="load-session-file" accept=".json" hidden>
                </div>
                <div class="row-actions" style="margin-top: 6px;">
                    <button class="btn btn-ghost btn-sm" id="snapshot-btn" disabled data-testid="snapshot-btn">📸 Snapshot</button>
                    <button class="btn btn-ghost btn-sm" id="export-all-btn" disabled data-testid="export-all-btn">📥 Export CSV</button>
                </div>
            </div>
        </div>

        <p class="hint">
            <em>Flotte</em> · type, id_nom, adresse, ville, code_postal, volume_max, consommation_l100km, poids_max, chauffeur, tel_chauffeur, heure_debut/fin_chauffeur<br>
            <em>Commandes</em> · nom_client, adresse, ville, code_postal, volume_m3, poids_kg, heure_debut, heure_fin, priorite&nbsp;(1/2/3)
        </p>
    </aside>

    <!-- ═══════ Main map ═══════ -->
    <div class="main">
        <main id="map" aria-label="Carte des tournées"></main>

        <div class="map-tools">
            <button class="map-tool" id="map-zoom-fit" title="Recadrer sur tous les points" data-testid="map-zoom-fit">⛶</button>
            <button class="map-tool" id="map-toggle-routes" title="Masquer/afficher les tracés" data-testid="map-toggle-routes">↯</button>
            <button class="map-tool" id="map-toggle-markers" title="Masquer/afficher les points" data-testid="map-toggle-markers">⦿</button>
            <button class="map-tool" id="map-tile-toggle" title="Changer le style de carte" data-testid="map-tile-toggle">◐</button>
        </div>

        <div class="empty-state" id="empty-state">
            <div class="empty-card" data-testid="empty-card">
                <h3><span class="pulse-dot"></span>Prêt à planifier ?</h3>
                <p>Importe ta flotte puis tes commandes via la barre latérale, configure tes coûts, et clique sur <strong>Générer</strong>. L'algorithme calcule les meilleurs trajets avec OSRM et te les affiche ici, sur la carte.</p>
            </div>
        </div>

        <!-- Right drawer -->
        <aside class="drawer" id="drawer" aria-label="Détails des tournées">
            <div class="drawer-head">
                <div class="drawer-tabs">
                    <button class="drawer-tab is-active" data-pane="routes" data-testid="tab-routes">Tournées</button>
                    <button class="drawer-tab" data-pane="unassigned" data-testid="tab-unassigned">Non&nbsp;affectés</button>
                </div>
                <button class="icon-btn" id="drawer-close" title="Fermer le panneau" data-testid="drawer-close" aria-label="Fermer">×</button>
            </div>
            <div class="drawer-body">
                <div class="drawer-pane is-active" id="pane-routes" data-testid="pane-routes">
                    <div id="route-legend"></div>
                    <div id="summary-bar"></div>
                </div>
                <div class="drawer-pane" id="pane-unassigned" data-testid="pane-unassigned">
                    <div id="unassigned-list"></div>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- ═══════ Modals ═══════ -->

<div id="data-modal" class="modal" role="dialog" aria-modal="true" hidden data-testid="data-modal">
    <div class="modal-box" style="max-width: 1000px;">
        <div class="modal-head">
            <h2>Données importées</h2>
            <button class="close-x" data-close="data-modal" data-testid="close-data-modal">×</button>
        </div>
        <div class="modal-body">
            <table id="data-table">
                <thead><tr id="table-header"></tr></thead>
                <tbody id="table-body"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="history-modal" class="modal" role="dialog" aria-modal="true" hidden data-testid="history-modal">
    <div class="modal-box" style="max-width: 720px;">
        <div class="modal-head">
            <h2>Historique des tournées</h2>
            <button class="close-x" data-close="history-modal" data-testid="close-history-modal">×</button>
        </div>
        <div class="modal-body" id="history-body">
            <p class="no-data">Aucune tournée historisée pour le moment.</p>
        </div>
    </div>
</div>

<div id="compare-modal" class="modal" role="dialog" aria-modal="true" hidden data-testid="compare-modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Comparaison de scénarios</h2>
            <button class="close-x" data-close="compare-modal" data-testid="close-compare-modal">×</button>
        </div>
        <div class="modal-body" id="compare-body">
            <p class="no-data">Sélectionne 2 snapshots depuis l'historique pour les comparer.</p>
        </div>
    </div>
</div>

<div id="stats-modal" class="modal" role="dialog" aria-modal="true" hidden data-testid="stats-modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Statistiques avancées</h2>
            <button class="close-x" data-close="stats-modal" data-testid="close-stats-modal">×</button>
        </div>
        <div class="modal-body">
            <div class="stats-grid">
                <div class="chart-card"><h4>Coût par camion (€)</h4><canvas id="chart-cost"></canvas></div>
                <div class="chart-card"><h4>Distance par camion (km)</h4><canvas id="chart-dist"></canvas></div>
                <div class="chart-card"><h4>Remplissage par camion (%)</h4><canvas id="chart-fill"></canvas></div>
                <div class="chart-card"><h4>Répartition des coûts</h4><canvas id="chart-pie"></canvas></div>
            </div>
        </div>
    </div>
</div>

<div id="loading-overlay" aria-live="polite" aria-busy="false">
    <div class="spinner" role="status" aria-label="Chargement"></div>
    <p id="loading-text">Chargement…</p>
</div>

<div id="toasts" aria-live="polite"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script src="https://unpkg.com/chart.js@4.4.0/dist/chart.umd.js"></script>
<script src="/js/app.js"></script>
</body>
</html>
