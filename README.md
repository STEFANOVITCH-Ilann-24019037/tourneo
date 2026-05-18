# Tournéo - Planificateur de Tournées de Livraison

Tournéo est un outil de planification de tournées de livraison (MVP) conçu pour optimiser la logistique en regroupant les commandes par agence et par camion, tout en calculant les itinéraires et les coûts associés.

## ▶️ Démarrage rapide

```bash
# Installer les dépendances (une seule fois)
composer install

# Lancer le serveur de développement
php -S localhost:8080 -t public/
```

Puis ouvre **http://localhost:8080** dans ton navigateur.

## 🚀 Fonctionnalités Clés

- **Importation de Flotte** : Chargement des agences et des camions via un fichier CSV.
- **Importation de Commandes** : Chargement des points de livraison via un fichier CSV.
- **Géocodage Automatique** : Conversion des adresses en coordonnées géographiques via l'API Adresse du gouvernement français.
- **Optimisation des Tournées** : Algorithme de regroupement des commandes par proximité et capacité des camions.
- **Calcul d'Itinéraires Réels** : Utilisation de l'API OSRM pour obtenir les distances et durées de trajet réelles par la route.
- **Estimation des Coûts** : Calcul des coûts de carburant et de main-d'œuvre basé sur des paramètres configurables.
- **Visualisation Interactive** : Affichage des tournées sur une carte Leaflet avec une légende détaillée.
- **Export CSV** : Exportation individuelle de chaque tournée au format CSV pour les chauffeurs.

## 🛠️ Stack Technique

- **Frontend** : HTML5, CSS3 (Vanilla), JavaScript (ES6+).
- **Cartographie** : [Leaflet.js](https://leafletjs.com/) avec fonds de carte CartoDB.
- **Géocodage** : [API Adresse (Etalab)](https://adresse.data.gouv.fr/api-gestion).
- **Routing** : [OSRM (Open Source Routing Machine)](http://project-osrm.org/).

## 📋 Formats de Fichiers CSV

### 1. Flotte (`exemple_flotte.csv`)
Le fichier doit contenir les colonnes suivantes :
- `type` : "agence" ou "camion".
- `id_nom` : Nom de l'agence ou identifiant du camion.
- `adresse`, `ville`, `code_postal` : Localisation (pour les agences).
- `volume_max` : Capacité maximale du camion (pour les camions).

### 2. Commandes (`exemple_tournees.csv`)
Le fichier doit contenir les colonnes suivantes :
- `nom_client` : Nom du destinataire.
- `adresse`, `ville`, `code_postal` : Adresse de livraison.
- `volume` : Volume de la commande (doit être inférieur ou égal à la capacité des camions).

## 📖 Mode d'Emploi

1. **Importer la Flotte** : Cliquez sur "Importer Flotte" et sélectionnez votre fichier CSV contenant les agences et camions.
2. **Importer les Commandes** : Cliquez sur "Importer Commandes" et sélectionnez votre fichier CSV de livraisons. L'application géocodera automatiquement les adresses.
3. **Configurer les Coûts** : Ajustez le prix du gazole, la consommation des camions et le taux horaire des chauffeurs dans la barre latérale.
4. **Générer les Tournées** : Cliquez sur "Générer les tournées". L'algorithme calculera les meilleurs trajets.
5. **Consulter et Exporter** : Visualisez les trajets sur la carte, consultez le résumé des coûts et exportez les feuilles de route via l'icône 📥 dans la légende.

## 🧠 Logique de Fonctionnement

### Géocodage
L'application utilise l'API `api-adresse.data.gouv.fr` pour transformer les adresses textuelles en coordonnées `lat/lon`. Un journal de bord affiche le succès ou l'échec de chaque adresse.

### Algorithme de Tournées
L'algorithme actuel suit une logique de "plus proche voisin" (Greedy) :
1. Il sélectionne un camion disponible.
2. Il identifie l'agence la plus proche du premier point de livraison non visité.
3. Il remplit le camion en ajoutant successivement le point le plus proche de la position actuelle, tant que la capacité (`volume_max`) n'est pas dépassée.
4. Il répète l'opération jusqu'à ce que toutes les commandes soient traitées ou que la flotte soit épuisée.

### Calcul des Coûts
- **Carburant** : `(Distance totale / 100) * Consommation * Prix du gazole`.
- **Main-d'œuvre** : `Durée totale (heures) * Taux horaire`.
