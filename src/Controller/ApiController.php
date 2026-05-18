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

        if (!isset($_FILES['fleet_file']) || $_FILES['fleet_file']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonError('Fichier de flotte manquant ou invalide.');
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

        if (!isset($_FILES['orders_file']) || $_FILES['orders_file']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonError('Fichier de commandes manquant ou invalide.');
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

        $fuelPrice    = (float) ($config['fuelPrice']    ?? 1.85);
        $defaultConso = (float) ($config['defaultConso'] ?? 15.0);
        $hourlyRate   = (float) ($config['hourlyRate']   ?? 25.0);

        $result    = $this->routingService->generateRoutes($agencies, $trucks, $clients);
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
