<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournéo – Planificateur de Tournées</title>
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

            <h1>Tournéo <span>MVP</span></h1>

            <div class="section">
                <label for="fleet-file" class="btn btn-outline">Importer Flotte</label>
                <input type="file" id="fleet-file" name="fleet_file" accept=".csv">
                <p class="stats">
                    Agences&nbsp;: <span id="agency-count">0</span> | Camions&nbsp;: <span id="truck-count">0</span>
                </p>
            </div>

            <div class="section">
                <label for="orders-file" class="btn btn-outline">Importer Commandes</label>
                <input type="file" id="orders-file" name="orders_file" accept=".csv">
                <p class="stats">Commandes chargées&nbsp;: <span id="client-count">0</span></p>
                <div id="geocoding-log" class="log-container" hidden></div>
            </div>

            <div class="section">
                <p class="config-title">Configuration des Coûts</p>
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
            </div>

            <div class="section">
                <button id="view-data-btn" class="btn btn-outline" disabled>Voir les données</button>
                <button id="generate-btn" class="btn btn-primary" disabled>Générer les tournées</button>
            </div>

            <div class="section" id="legend-section" hidden>
                <p class="legend-title">Légende &amp; Coûts</p>
                <div id="route-legend"></div>
                <div id="total-summary" class="total-summary"></div>
            </div>

            <p class="hint">
                Flotte : type, id_nom, adresse, ville, code_postal, volume_max, consommation_l100km<br>
                Commandes : nom_client, adresse, ville, code_postal, volume_m3
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
