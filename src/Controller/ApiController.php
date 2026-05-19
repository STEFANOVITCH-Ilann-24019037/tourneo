<?php

declare(strict_types=1);

namespace Tourneo\Controller;

use Tourneo\Service\CsvParser;
use Tourneo\Service\GeocodingService;
use Tourneo\Service\RoutingService;

class ApiController
{
    public function __construct(
        private readonly CsvParser        $csvParser,
        private readonly GeocodingService $geocodingService,
        private readonly RoutingService   $routingService,
    ) {}

    public function handleFleet(): void
    {
        $this->requireMethod('POST');
        $this->checkRateLimit();

        if (!isset($_FILES['fleet_file']) || $_FILES['fleet_file']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonError('Fichier de flotte manquant ou invalide.');
        }

        if ($_FILES['fleet_file']['size'] > 5 * 1024 * 1024) {
            $this->jsonError('Fichier trop volumineux (max 5 Mo).', 413);
        }

        $content = file_get_contents($_FILES['fleet_file']['tmp_name']);
        $rows    = $this->csvParser->parse($content);

        $agencyRows = [];
        $truckRows  = [];

        foreach ($rows as $row) {
            $type = strtolower(trim($row['type'] ?? ''));
            if ($type === 'agence') {
                $agencyRows[] = $row;
            } elseif ($type === 'camion') {
                $truckRows[] = $row;
            }
        }

        // Batch geocode all agencies in one request
        $agencyGeoItems = array_map(fn($r) => [
            'adresse'     => trim($r['adresse'] ?? ''),
            'ville'       => trim($r['ville'] ?? ''),
            'code_postal' => trim($r['code_postal'] ?? ''),
        ], $agencyRows);
        $agencyCoords = $this->geocodingService->geocodeBatch($agencyGeoItems);

        // Batch geocode only trucks that have an address
        $truckGeoIndices = [];
        $truckGeoItems   = [];
        foreach ($truckRows as $i => $row) {
            if (trim($row['adresse'] ?? '') !== '' && trim($row['ville'] ?? '') !== '') {
                $truckGeoIndices[] = $i;
                $truckGeoItems[]   = [
                    'adresse'     => trim($row['adresse'] ?? ''),
                    'ville'       => trim($row['ville'] ?? ''),
                    'code_postal' => trim($row['code_postal'] ?? ''),
                ];
            }
        }
        $truckGeoResults = $this->geocodingService->geocodeBatch($truckGeoItems);
        $truckCoordsMap  = [];
        foreach ($truckGeoIndices as $batchIdx => $truckIdx) {
            $truckCoordsMap[$truckIdx] = $truckGeoResults[$batchIdx];
        }

        $agencies = [];
        $trucks   = [];
        $errors   = [];

        foreach ($agencyRows as $i => $row) {
            $coords = $agencyCoords[$i] ?? ['lat' => null, 'lon' => null];
            if ($coords['lat'] !== null) {
                $agencies[] = [
                    'id_nom'      => trim($row['id_nom'] ?? ''),
                    'adresse'     => trim($row['adresse'] ?? ''),
                    'ville'       => trim($row['ville'] ?? ''),
                    'code_postal' => trim($row['code_postal'] ?? ''),
                    'lat'         => $coords['lat'],
                    'lon'         => $coords['lon'],
                ];
            } else {
                $errors[] = 'Agence non géocodée : ' . ($row['id_nom'] ?? '');
            }
        }

        foreach ($truckRows as $i => $row) {
            $truck = [
                'id'                  => trim($row['id_nom'] ?? ''),
                'volume_max'          => (float) ($row['volume_max'] ?? 100),
                'poids_max'           => (float) ($row['poids_max'] ?? 0),
                'consommation_l100km' => (float) ($row['consommation_l100km'] ?? 0),
                'adresse'             => trim($row['adresse'] ?? ''),
                'ville'               => trim($row['ville'] ?? ''),
                'code_postal'         => trim($row['code_postal'] ?? ''),
                'lat'                 => null,
                'lon'                 => null,
            ];

            if (isset($truckCoordsMap[$i])) {
                $coords = $truckCoordsMap[$i];
                if ($coords['lat'] !== null) {
                    $truck['lat'] = $coords['lat'];
                    $truck['lon'] = $coords['lon'];
                } else {
                    $errors[] = "Base du camion non géocodée : {$truck['id']}";
                }
            }

            $trucks[] = $truck;
        }

        $this->json(['agencies' => $agencies, 'trucks' => $trucks, 'errors' => $errors]);
    }

    public function handleOrders(): void
    {
        $this->requireMethod('POST');
        $this->checkRateLimit();

        if (!isset($_FILES['orders_file']) || $_FILES['orders_file']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonError('Fichier de commandes manquant ou invalide.');
        }

        if ($_FILES['orders_file']['size'] > 5 * 1024 * 1024) {
            $this->jsonError('Fichier trop volumineux (max 5 Mo).', 413);
        }

        $content = file_get_contents($_FILES['orders_file']['tmp_name']);
        $rows    = $this->csvParser->parse($content);

        if ($rows === []) {
            $this->json(['clients' => [], 'logs' => []]);
        }

        // Batch geocode all orders in one request
        $geoItems = array_map(fn($r) => [
            'adresse'     => trim($r['adresse'] ?? ''),
            'ville'       => trim($r['ville'] ?? ''),
            'code_postal' => trim($r['code_postal'] ?? ''),
        ], $rows);
        $coords = $this->geocodingService->geocodeBatch($geoItems);

        $clients = [];
        $logs    = [];

        foreach ($rows as $i => $row) {
            $name = trim($row['nom_client'] ?? $row['nom'] ?? '');
            $c    = $coords[$i] ?? ['lat' => null, 'lon' => null];

            if ($c['lat'] !== null) {
                $clients[] = [
                    'nom_client'  => $name,
                    'adresse'     => trim($row['adresse'] ?? ''),
                    'ville'       => trim($row['ville'] ?? ''),
                    'code_postal' => trim($row['code_postal'] ?? ''),
                    'volume'      => (float) ($row['volume_m3'] ?? $row['volume'] ?? 1),
                    'poids_kg'    => (float) ($row['poids_kg'] ?? $row['poids'] ?? 0),
                    'tw_start'    => $this->parseTime($row['heure_debut'] ?? ''),
                    'tw_end'      => $this->parseTime($row['heure_fin']   ?? ''),
                    'lat'         => $c['lat'],
                    'lon'         => $c['lon'],
                ];
                $logs[] = ['success' => true, 'client' => $name];
            } else {
                $logs[] = ['success' => false, 'client' => $name];
            }
        }

        $this->json(['clients' => $clients, 'logs' => $logs]);
    }

    public function handleGenerate(): void
    {
        $this->requireMethod('POST');
        $this->checkRateLimit();

        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            $this->jsonError('Corps de requête JSON invalide.');
        }

        $agencies = $input['agencies'] ?? [];
        $trucks   = $input['trucks']   ?? [];
        $clients  = $input['clients']  ?? [];
        $config   = $input['config']   ?? [];

        if ($trucks === [] || $clients === []) {
            $this->jsonError('Données manquantes : camions et commandes sont requis.');
        }

        if (count($agencies) > 20)  $this->jsonError('Maximum 20 agences autorisées.', 422);
        if (count($trucks)   > 50)  $this->jsonError('Maximum 50 camions autorisés.', 422);
        if (count($clients)  > 500) $this->jsonError('Maximum 500 commandes autorisées.', 422);

        $this->validateCoords($agencies, 'Agence');
        $this->validateCoords(array_filter($clients, fn($c) => ($c['lat'] ?? null) !== null), 'Client');

        $fuelPrice    = (float) ($config['fuelPrice']    ?? 1.85);
        $defaultConso = (float) ($config['defaultConso'] ?? 15.0);
        $hourlyRate   = (float) ($config['hourlyRate']   ?? 25.0);

        if (isset($config['osrmTimeout'])) {
            $this->routingService->setRouteTimeout((int) $config['osrmTimeout']);
        }

        $result    = $this->routingService->generateRoutes($agencies, $trucks, $clients, $config);
        $routeData = $this->routingService->fetchRoutesParallel($result['routes']);

        foreach ($result['routes'] as $i => &$route) {
            $data = $routeData[$i] ?? null;

            $route['geometry'] = $data['geometry'] ?? null;
            $route['distance'] = isset($data['distance']) ? $data['distance'] / 1000.0 : 0.0;
            $route['duration'] = isset($data['duration']) ? $data['duration'] / 3600.0 : 0.0;

            $conso = (float) ($route['truck']['consommation_l100km'] ?? 0);
            if ($conso <= 0) {
                $conso = $defaultConso;
            }

            $route['fuelCost']  = ($route['distance'] * $conso / 100.0) * $fuelPrice;
            $route['laborCost'] = $route['duration'] * $hourlyRate;
            $route['totalCost'] = $route['fuelCost'] + $route['laborCost'];
        }
        unset($route);

        $this->json($result);
    }

    public function handleRecalculate(): void
    {
        $this->requireMethod('POST');

        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            $this->jsonError('Corps de requête JSON invalide.');
        }

        $agency = $input['agency'] ?? null;
        $truck  = $input['truck']  ?? [];
        $points = $input['points'] ?? [];
        $config = $input['config'] ?? [];

        if ($agency === null) {
            $this->jsonError('Agence/dépôt manquant.');
        }

        $fuelPrice  = (float) ($config['fuelPrice']  ?? 1.85);
        $hourlyRate = (float) ($config['hourlyRate']  ?? 25.0);
        $conso      = (float) ($truck['consommation_l100km'] ?? 0);

        if ($conso <= 0) {
            $conso = (float) ($config['defaultConso'] ?? 15.0);
        }

        $routeData = $this->routingService->fetchRoute($agency, $points);

        $distance  = isset($routeData['distance']) ? $routeData['distance'] / 1000.0 : 0.0;
        $duration  = isset($routeData['duration']) ? $routeData['duration'] / 3600.0 : 0.0;
        $fuelCost  = ($distance * $conso / 100.0) * $fuelPrice;
        $laborCost = $duration * $hourlyRate;

        $this->json([
            'geometry'  => $routeData['geometry'] ?? null,
            'distance'  => $distance,
            'duration'  => $duration,
            'fuelCost'  => $fuelCost,
            'laborCost' => $laborCost,
            'totalCost' => $fuelCost + $laborCost,
        ]);
    }

    private function parseTime(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return $h * 60 + $min;
            }
        }
        return null;
    }

    private function checkRateLimit(): void
    {
        $now    = time();
        $window = 60;
        $limit  = 30;

        if (!isset($_SESSION['rl_reset']) || $now > $_SESSION['rl_reset']) {
            $_SESSION['rl_count'] = 0;
            $_SESSION['rl_reset'] = $now + $window;
        }

        if ($_SESSION['rl_count'] >= $limit) {
            $this->jsonError('Trop de requêtes. Veuillez patienter une minute.', 429);
        }

        $_SESSION['rl_count']++;
    }

    private function validateCoords(array $items, string $label): void
    {
        foreach ($items as $i => $item) {
            $lat = $item['lat'] ?? null;
            $lon = $item['lon'] ?? null;

            if (!is_numeric($lat) || !is_numeric($lon)) {
                $this->jsonError("$label #$i : coordonnées manquantes ou invalides.", 422);
            }

            $lat = (float) $lat;
            $lon = (float) $lon;

            if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
                $this->jsonError("$label #$i : coordonnées hors plage.", 422);
            }
        }
    }

    private function requireMethod(string $method): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== $method) {
            $this->jsonError('Méthode non autorisée.', 405);
        }
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private function jsonError(string $message, int $status = 400): never
    {
        $this->json(['error' => $message], $status);
    }
}
