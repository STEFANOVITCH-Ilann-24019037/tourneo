<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournéo – Planificateur de Tournées</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>

    <button id="menu-toggle" class="menu-toggle" aria-label="Ouvrir le menu" aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <div id="mobile-overlay" class="mobile-overlay" aria-hidden="true"></div>

    <div class="app-container">
        <aside class="sidebar" aria-label="Panneau de contrôle">

            <div class="sidebar-brand">
                <h1>TOURNÉ<span class="brand-accent">O</span><span class="mvp-tag">MVP</span></h1>
                <p class="brand-tagline">// planification de tournées</p>
            </div>

            <div class="section">
                <div class="section-label">
                    <span class="section-num">01 —</span>
                    <span class="section-title">Flotte</span>
                </div>
                <label for="fleet-file" class="btn btn-outline">Importer Flotte CSV</label>
                <input type="file" id="fleet-file" name="fleet_file" accept=".csv">
                <div class="stats">
                    <div class="stat-item">
                        <span class="stat-val" id="agency-count">0</span>
                        <span class="stat-key">Agences</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-val" id="truck-count">0</span>
                        <span class="stat-key">Camions</span>
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-label">
                    <span class="section-num">02 —</span>
                    <span class="section-title">Commandes</span>
                </div>
                <label for="orders-file" class="btn btn-outline">Importer Commandes CSV</label>
                <input type="file" id="orders-file" name="orders_file" accept=".csv">
                <div class="stats">
                    <div class="stat-item">
                        <span class="stat-val" id="client-count">0</span>
                        <span class="stat-key">Commandes chargées</span>
                    </div>
                </div>
                <div id="geocoding-log" class="log-container" hidden></div>
            </div>

            <div class="section">
                <div class="section-label">
                    <span class="section-num">03 —</span>
                    <span class="section-title">Configuration</span>
                </div>
                <p class="config-title">Coûts</p>
                <div class="config-grid">
                    <div class="config-item">
                        <label for="fuel-price">Gazole (€/L)</label>
                        <input type="number" id="fuel-price" value="1.85" min="0" step="0.01">
                    </div>
                    <div class="config-item">
                        <label for="truck-conso">Conso défaut (L/100km)</label>
                        <input type="number" id="truck-conso" value="15" min="0" step="0.1" title="Utilisé pour les camions sans consommation définie dans le CSV">
                    </div>
                    <div class="config-item">
                        <label for="hourly-rate">Salaire (€/h)</label>
                        <input type="number" id="hourly-rate" value="25" min="0" step="1">
                    </div>
                </div>
                <p class="config-title config-title-sep">Tournées</p>
                <div class="config-grid">
                    <div class="config-item">
                        <label for="avg-speed">Vitesse moy. (km/h)</label>
                        <input type="number" id="avg-speed" value="60" min="10" max="200" step="5">
                    </div>
                    <div class="config-item">
                        <label for="service-time">Tps livraison (min)</label>
                        <input type="number" id="service-time" value="15" min="0" max="120" step="5">
                    </div>
                    <div class="config-item">
                        <label for="start-time">Heure de départ</label>
                        <input type="time" id="start-time" value="08:00">
                    </div>
                    <div class="config-item">
                        <label for="osrm-timeout">Timeout OSRM (s)</label>
                        <input type="number" id="osrm-timeout" value="30" min="10" max="120" step="5">
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-label">
                    <span class="section-num">04 —</span>
                    <span class="section-title">Actions</span>
                </div>
                <button id="view-data-btn" class="btn btn-outline" disabled>Voir les données</button>
                <button id="generate-btn" class="btn btn-primary" disabled>Générer les tournées</button>
                <div class="session-actions">
                    <button id="save-session-btn" class="btn btn-outline btn-sm">Sauvegarder session</button>
                    <label class="btn btn-outline btn-sm" for="load-session-file">Charger session</label>
                    <input type="file" id="load-session-file" accept=".json" hidden>
                </div>
            </div>

            <div class="section" id="legend-section" hidden>
                <div class="section-label">
                    <span class="section-num">05 —</span>
                    <span class="section-title">Légende &amp; Coûts</span>
                    <button id="export-all-btn" class="btn-export" title="Exporter toutes les tournées en CSV">📥 Tout</button>
                </div>
                <p class="legend-title">Légende &amp; Coûts</p>
                <div id="route-legend"></div>
                <div id="total-summary" class="total-summary"></div>
            </div>

            <p class="hint">
                Flotte : type, id_nom, adresse, ville, code_postal, volume_max, consommation_l100km, <em>poids_max</em><br>
                Commandes : nom_client, adresse, ville, code_postal, volume_m3, poids_kg, <em>heure_debut</em>, <em>heure_fin</em>
            </p>

        </aside>

        <main id="map" aria-label="Carte des tournées"></main>
    </div>

    <div id="data-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title" hidden>
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modal-title">Données Importées</h2>
                <button class="close-modal" aria-label="Fermer la fenêtre">&times;</button>
            </div>
            <div class="modal-body">
                <div id="data-table-container">
                    <table id="data-table">
                        <thead><tr id="table-header"></tr></thead>
                        <tbody id="table-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="loading-overlay" aria-live="polite" aria-busy="false">
        <div class="spinner" role="status" aria-label="Chargement en cours"></div>
        <p id="loading-text">Chargement…</p>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="/js/app.js"></script>

</body>
</html>
