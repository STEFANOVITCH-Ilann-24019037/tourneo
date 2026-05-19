<?php

declare(strict_types=1);

namespace Tourneo\Service;

class RoutingService
{
    private const OSRM_URL         = 'https://router.project-osrm.org/route/v1/driving/';
    private const EARTH_RADIUS     = 6371.0;
    private const AVG_SPEED_KMH    = 60.0;
    private const SERVICE_TIME_MIN = 15.0;

    private int $routeTimeout = 30;

    /**
     * Algorithme glouton du plus proche voisin.
     * Retourne routes assignées, nombre de non-affectés et les items non-affectés.
     */
    public function setRouteTimeout(int $seconds): void
    {
        $this->routeTimeout = max(10, min(120, $seconds));
    }

    public function generateRoutes(array $agencies, array $trucks, array $clients, array $config = []): array
    {
        $unvisited       = array_values($clients);
        $availableTrucks = $trucks;
        $routes          = [];

        $avgSpeed    = max(10.0, (float) ($config['avgSpeed']    ?? self::AVG_SPEED_KMH));
        $serviceTime = max(0.0,  (float) ($config['serviceTime'] ?? self::SERVICE_TIME_MIN));
        $startTime   = $this->parseStartTime((string) ($config['startTime'] ?? '08:00'));

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

            $points         = [];
            $totalVolume    = 0.0;
            $totalPoids     = 0.0;
            $currentPos     = $depot;
            $poidsMax       = (float) ($truck['poids_max'] ?? 0);
            $currentTimeMin = $startTime;

            while ($unvisited !== []) {
                $capaciteRestante = (float)($truck['volume_max'] ?? 100) - $totalVolume;

                $result = $this->findNearestFitting(
                    $currentPos, $unvisited, $capaciteRestante,
                    $poidsMax, $totalPoids,
                    $currentTimeMin, $avgSpeed
                );

                if ($result === null) {
                    break;
                }

                ['index' => $idx, 'item' => $nearest, 'arrival_min' => $arrivalMin] = $result;
                $volume = (float) ($nearest['volume'] ?? 0);
                $poids  = (float) ($nearest['poids_kg'] ?? 0);

                $nearest['arrival_min'] = $arrivalMin;

                // Si arrivée avant le début du créneau, on attend sur place
                $departureMin   = max($arrivalMin, (int) ($nearest['tw_start'] ?? $arrivalMin));
                $currentTimeMin = $departureMin + $serviceTime;

                $points[]    = $nearest;
                $totalVolume += $volume;
                $totalPoids  += $poids;
                $currentPos  = $nearest;
                array_splice($unvisited, $idx, 1);
            }

            $points = $this->twoOptImprove($depot, $points);

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
                CURLOPT_TIMEOUT        => $this->routeTimeout,
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
            CURLOPT_TIMEOUT        => $this->routeTimeout,
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

    private function parseStartTime(string $value): float
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            return (float) $m[1] * 60.0 + (float) $m[2];
        }
        return 480.0; // 08:00 par défaut
    }

    private function twoOptImprove(array $depot, array $points): array
    {
        $n = count($points);
        if ($n < 3) {
            return $points;
        }

        $improved = true;
        while ($improved) {
            $improved = false;
            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $prevI = $i === 0 ? $depot : $points[$i - 1];
                    $nextJ = $j === $n - 1 ? $depot : $points[$j + 1];

                    $dBefore = $this->haversine(
                        (float) $prevI['lat'], (float) $prevI['lon'],
                        (float) $points[$i]['lat'], (float) $points[$i]['lon']
                    ) + $this->haversine(
                        (float) $points[$j]['lat'], (float) $points[$j]['lon'],
                        (float) $nextJ['lat'], (float) $nextJ['lon']
                    );

                    $dAfter = $this->haversine(
                        (float) $prevI['lat'], (float) $prevI['lon'],
                        (float) $points[$j]['lat'], (float) $points[$j]['lon']
                    ) + $this->haversine(
                        (float) $points[$i]['lat'], (float) $points[$i]['lon'],
                        (float) $nextJ['lat'], (float) $nextJ['lon']
                    );

                    if ($dAfter < $dBefore - 0.001) {
                        $segment = array_slice($points, $i, $j - $i + 1);
                        array_splice($points, $i, $j - $i + 1, array_reverse($segment));
                        $improved = true;
                    }
                }
            }
        }

        return $points;
    }

    private function findNearestFitting(
        array $origin, array $candidates, float $maxVolume,
        float $maxPoids = 0.0, float $currentPoids = 0.0,
        float $currentTimeMin = 0.0, float $avgSpeedKmh = self::AVG_SPEED_KMH
    ): ?array {
        $minDist    = PHP_FLOAT_MAX;
        $nearestIdx = null;
        $nearestArr = 0.0;

        foreach ($candidates as $idx => $candidate) {
            if ((float) ($candidate['volume'] ?? 0) > $maxVolume) {
                continue;
            }
            if ($maxPoids > 0 && $currentPoids + (float) ($candidate['poids_kg'] ?? 0) > $maxPoids) {
                continue;
            }

            $dist       = $this->haversine(
                (float) $origin['lat'], (float) $origin['lon'],
                (float) $candidate['lat'], (float) $candidate['lon']
            );
            $arrivalMin = $currentTimeMin + ($dist / $avgSpeedKmh) * 60.0;

            // Contrainte dure : hors créneau de livraison → skip
            $twEnd = $candidate['tw_end'] ?? null;
            if ($twEnd !== null && $arrivalMin > (float) $twEnd) {
                continue;
            }

            if ($dist < $minDist) {
                $minDist    = $dist;
                $nearestIdx = $idx;
                $nearestArr = $arrivalMin;
            }
        }

        if ($nearestIdx === null) {
            return null;
        }

        return ['index' => $nearestIdx, 'item' => $candidates[$nearestIdx], 'arrival_min' => (int) round($nearestArr)];
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
