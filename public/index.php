<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

use Tourneo\Controller\AppController;
use Tourneo\Controller\ApiController;
use Tourneo\Service\CsvParser;
use Tourneo\Service\GeocodingService;
use Tourneo\Service\RoutingService;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://unpkg.com; style-src 'self' https://unpkg.com; img-src 'self' data: https://*.basemaps.cartocdn.com https://*.tile.openstreetmap.org; connect-src 'self' https://api-adresse.data.gouv.fr https://router.project-osrm.org; font-src 'self'; frame-ancestors 'none'");
header('X-XSS-Protection: 1; mode=block');

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

if (in_array($path, ['/api/fleet', '/api/orders', '/api/generate', '/api/recalculate'], true)) {
    $api = new ApiController(new CsvParser(), new GeocodingService(), new RoutingService());
    match ($path) {
        '/api/fleet'       => $api->handleFleet(),
        '/api/orders'      => $api->handleOrders(),
        '/api/generate'    => $api->handleGenerate(),
        '/api/recalculate' => $api->handleRecalculate(),
    };
} else {
    (new AppController())->index();
}
