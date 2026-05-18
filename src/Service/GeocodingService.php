<?php

declare(strict_types=1);

namespace Tourneo\Service;

class GeocodingService
{
    private const API_URL       = 'https://api-adresse.data.gouv.fr/search/';
    private const API_BATCH_URL = 'https://api-adresse.data.gouv.fr/search/csv/';
    private const TIMEOUT       = 10;
    private const BATCH_TIMEOUT = 60;

    /**
     * Géocode un seul point. Utilisé pour les recalculs individuels.
     */
    public function geocode(string $address): ?array
    {
        $url = self::API_URL . '?' . http_build_query(['q' => $address, 'limit' => 1]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
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

        if (!isset($data['features'][0]['geometry']['coordinates'])) {
            return null;
        }

        [$lon, $lat] = $data['features'][0]['geometry']['coordinates'];

        return ['lat' => (float) $lat, 'lon' => (float) $lon];
    }

    /**
     * Géocode un tableau d'éléments en une seule requête batch.
     * Chaque élément doit avoir les clés 'adresse', 'ville', 'code_postal'.
     * Retourne un tableau indexé de ['lat', 'lon'] (null si échec).
     */
    public function geocodeBatch(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $multiHandle = curl_multi_init();
        $handles     = [];

        foreach ($items as $index => $item) {
            $query = $this->buildQuery($item);
            $url   = self::API_URL . '?' . http_build_query([
                'q'            => $query,
                'limit'        => 1,
                'autocomplete' => 0,
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'Tourneo/1.0',
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[$index] = $ch;
        }

        do {
            curl_multi_exec($multiHandle, $running);
            if ($running > 0) {
                curl_multi_select($multiHandle);
            }
        } while ($running > 0);

        $results = array_fill(0, count($items), ['lat' => null, 'lon' => null]);

        foreach ($handles as $index => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response !== false && $httpCode === 200) {
                $results[$index] = $this->parseSingleResponse($response, $items[$index]);
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    private function parseSingleResponse(string $response, array $expected): array
    {
        $data = json_decode($response, true);
        $feature = $data['features'][0] ?? null;

        if (!is_array($feature) || !isset($feature['geometry']['coordinates'])) {
            return ['lat' => null, 'lon' => null];
        }

        $properties = $feature['properties'] ?? [];
        if (!$this->matchesExpectedLocation($properties, $expected)) {
            return ['lat' => null, 'lon' => null];
        }

        [$lon, $lat] = $feature['geometry']['coordinates'];

        return ['lat' => (float) $lat, 'lon' => (float) $lon];
    }

    private function buildQuery(array $item): string
    {
        $parts = array_filter([
            trim((string) ($item['adresse'] ?? '')),
            trim((string) ($item['code_postal'] ?? '')) . ' ' . trim((string) ($item['ville'] ?? '')),
            'France',
        ], fn(string $part): bool => trim($part) !== '');

        return implode(', ', $parts);
    }

    private function matchesExpectedLocation(array $properties, array $expected): bool
    {
        $expectedPostcode = trim((string) ($expected['code_postal'] ?? ''));
        $expectedCity     = $this->normalizeText((string) ($expected['ville'] ?? ''));

        $resultPostcode = trim((string) ($properties['postcode'] ?? ''));
        $resultCity     = $this->normalizeText((string) ($properties['city'] ?? ''));
        $resultDistrict = $this->normalizeText((string) ($properties['district'] ?? ''));
        $resultLabel    = $this->normalizeText((string) ($properties['label'] ?? ''));

        $postcodeMatches = $expectedPostcode === ''
            || $resultPostcode === $expectedPostcode
            || str_starts_with($resultPostcode, $expectedPostcode);

        if ($expectedCity === '') {
            return $postcodeMatches;
        }

        $cityMatches = $resultCity === $expectedCity
            || str_contains($resultDistrict, $expectedCity)
            || str_contains($resultLabel, $expectedCity);

        return $postcodeMatches && $cityMatches;
    }

    private function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
