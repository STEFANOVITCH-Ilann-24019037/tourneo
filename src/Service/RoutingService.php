<?php

declare(strict_types=1);

namespace Tourneo\Service;

class RoutingService
{
    private const OSRM_URL      = 'https://router.project-osrm.org/route/v1/driving/';
    private const EARTH_RADIUS  = 6371.0;
    private const ROUTE_TIMEOUT = 30;

    /**
     * Algorithme glouton du plus proche voisin.
     * Retourne routes assignées, nombre de non-affectés et les items non-affectés.
     */
    public function generateRoutes(array $agencies, array $trucks, array $clients): array
    {
        $unvisited       = array_values($clients);
        $availableTrucks = $trucks;
        $routes          = [];

        while ($unvisited !== [] && $availableTrucks !== []) {
            $truck = array_shift($availableTrucks);

            if (!empty($truck['lat']) && !empty($truck['lon'])) {
                $depot = [
                    'id_nom'      => $truck['id'],
                    'adresse'     => $truck['adresse']     ?? '',
                    'ville'       => $truck['ville']       ?? '',
                    'code_postal' => $truck['code_postal'] ?? '',
                    'lat'         => (float) $truck['lat'],
                    'lon'         => (float) $truck['lon'],
                ];
            } elseif ($agencies !== []) {
                ['item' => $depot] = $this->findNearest($unvisited[0], $agencies);
            } else {
                break;
            }

            $points      = [];
            $totalVolume = 0.0;
            $currentPos  = $depot;

            while ($unvisited !== []) {
                $capaciteRestante = (float)($truck['volume_max'] ?? 100) - $totalVolume;

                $result = $this->findNearestFitting($currentPos, $unvisited, $capaciteRestante);

                if ($result === null) {
                    break;
                }

                ['index' => $idx, 'item' => $nearest] = $result;
                $volume = (float) ($nearest['volume'] ?? 0);

                $points[]    = $nearest;
                $totalVolume += $volume;
                $currentPos  = $nearest;
                array_splice($unvisited, $idx, 1);
            }

            $totalPoids = array_sum(array_column($points, 'poids_kg'));

            $routes[] = [
                'truck'       => $truck,
                'agency'      => $depot,
                'points'      => $points,
                'totalVolume' => $totalVolume,
                'totalPoids'  => $totalPoids,
            ];
        }

        return [
            'routes'          => $routes,
            'unassigned'      => count($unvisited),
            'unassignedItems' => array_values($unvisited),
        ];
    }

    /**
     * Lance toutes les requêtes OSRM en parallèle via curl_multi.
     * Retourne un tableau indexé de résultats (null si la route a échoué).
     */
    public function fetchRoutesParallel(array $routes): array
    {
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($routes as $i => $route) {
            if (empty($route['points'])) {
                $handles[$i] = null;
                continue;
            }

            $url = $this->buildOsrmUrl($route['agency'], $route['points']);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::ROUTE_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'Tourneo/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh);
        } while ($running > 0);

        $results = [];
        foreach ($handles as $i => $ch) {
            if ($ch === null) {
                $results[$i] = null;
                continue;
            }

            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                $results[$i] = null;
                continue;
            }

            $data = json_decode($response, true);
            if (($data['code'] ?? '') !== 'Ok' || empty($data['routes'])) {
                $results[$i] = null;
                continue;
            }

            $results[$i] = [
                'geometry' => $data['routes'][0]['geometry'],
                'distance' => (float) $data['routes'][0]['distance'],
                'duration' => (float) $data['routes'][0]['duration'],
            ];
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Appelle OSRM pour une seule route (utilisé lors du recalcul manuel).
     */
    public function fetchRoute(array $agency, array $points): ?array
    {
        if ($points === []) {
            return null;
        }

        $url = $this->buildOsrmUrl($agency, $points);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::ROUTE_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Tourneo/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        if (($data['code'] ?? '') !== 'Ok' || empty($data['routes'])) {
            return null;
        }

        return [
            'geometry' => $data['routes'][0]['geometry'],
            'distance' => (float) $data['routes'][0]['distance'],
            'duration' => (float) $data['routes'][0]['duration'],
        ];
    }

    private function buildOsrmUrl(array $agency, array $points): string
    {
        $waypoints = [$agency, ...$points, $agency];
        $coords = implode(';', array_map(
            fn(array $p): string => "{$p['lon']},{$p['lat']}",
            $waypoints
        ));
        return self::OSRM_URL . $coords . '?overview=full&geometries=geojson';
    }

    private function findNearestFitting(array $origin, array $candidates, float $maxVolume): ?array
    {
        $minDist    = PHP_FLOAT_MAX;
        $nearestIdx = null;

        foreach ($candidates as $idx => $candidate) {
            if ((float) ($candidate['volume'] ?? 0) > $maxVolume) {
                continue;
            }
            $dist = $this->haversine(
                (float) $origin['lat'], (float) $origin['lon'],
                (float) $candidate['lat'], (float) $candidate['lon']
            );
            if ($dist < $minDist) {
                $minDist    = $dist;
                $nearestIdx = $idx;
            }
        }

        if ($nearestIdx === null) {
            return null;
        }

        return ['index' => $nearestIdx, 'item' => $candidates[$nearestIdx]];
    }

    private function findNearest(array $origin, array $candidates): array
    {
        $minDist    = PHP_FLOAT_MAX;
        $nearestIdx = 0;

        foreach ($candidates as $idx => $candidate) {
            $dist = $this->haversine(
                (float) $origin['lat'], (float) $origin['lon'],
                (float) $candidate['lat'], (float) $candidate['lon']
            );
            if ($dist < $minDist) {
                $minDist    = $dist;
                $nearestIdx = $idx;
            }
        }

        return ['index' => $nearestIdx, 'item' => $candidates[$nearestIdx]];
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2.0 * self::EARTH_RADIUS * asin(sqrt($a));
    }
}
