# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Démarrage

```bash
# Installer l'autoloader Composer (une seule fois)
composer install

# Démarrer le serveur de développement PHP (document root = public/)
php -S localhost:8080 -t public/

# Ouvrir http://localhost:8080
```

Le dossier `public/` est le document root. Toutes les requêtes non-fichiers passent par `public/index.php` via `.htaccess` (Apache) ou le routeur intégré PHP.

## Stack technique

- **Backend** : PHP 8.1+, zéro dépendance externe (PSR-4 via Composer autoload uniquement)
- **Frontend** : JavaScript ES2022 vanilla, Leaflet.js v1.9.4 (CDN)
- **APIs externes** : API Adresse `adresse.data.gouv.fr` (géocodage), OSRM `router.project-osrm.org` (routing routier)

## Architecture

```
public/          ← document root
  index.php      ← routeur + point d'entrée unique
  css/app.css
  js/app.js
  examples/      ← fichiers CSV d'exemple téléchargeables

src/
  Controller/
    AppController.php   ← rend templates/index.html.php
    ApiController.php   ← endpoints JSON : /api/fleet, /api/orders, /api/generate
  Service/
    CsvParser.php       ← parsing CSV robuste via fgetcsv (gère BOM, guillemets)
    GeocodingService.php← appelle API Adresse via cURL
    RoutingService.php  ← algorithme glouton + appel OSRM via cURL

templates/
  index.html.php  ← template HTML (aucune logique PHP, juste du HTML)
```

### Flux de données

```
Upload CSV flotte  → POST /api/fleet   → parse + géocode agences  → JSON {agencies, trucks}
Upload CSV commandes → POST /api/orders → parse + géocode clients   → JSON {clients, logs}
Clic "Générer"     → POST /api/generate → algorithme + OSRM        → JSON {routes, unassigned}
                                                                     ↓
                                                         JS rend les routes sur Leaflet
```

Le frontend JS tient l'état en mémoire (`state.agencies`, `state.trucks`, `state.clients`, `state.routes`) et renvoie ces données au serveur lors du `POST /api/generate`.

### Algorithme de tournées (`RoutingService::generateRoutes`)

Glouton "plus proche voisin" avec distance Haversine (remplace l'ancienne distance euclidienne incorrecte) :
1. Prend un camion disponible
2. Trouve l'agence la plus proche du premier point non affecté
3. Remplit le camion en ajoutant itérativement le point le plus proche jusqu'à saturation de la capacité
4. Répète jusqu'à épuisement des camions ou des commandes

### Calcul des coûts (dans `ApiController::handleGenerate`)

- **Carburant** : `(distance_km × conso_L/100 × prix_€/L)`
- **Main-d'œuvre** : `(durée_h × taux_horaire_€/h)`

## Formats CSV

**Flotte** (`public/examples/exemple_flotte.csv`) : `type,id_nom,adresse,ville,code_postal,volume_max`
- `type` : `agence` ou `camion`
- Les camions n'ont pas d'adresse ; les agences n'ont pas de `volume_max`

**Commandes** (`public/examples/exemple_tournees.csv`) : `nom_client,adresse,ville,code_postal,volume_m3`
- Le parser accepte aussi `nom` et `volume` comme noms de colonnes alternatifs
