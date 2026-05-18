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

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

$api = new ApiController(
    new CsvParser(),
    new GeocodingService(),
    new RoutingService()
);

match ($path) {
    '/api/fleet'        => $api->handleFleet(),
    '/api/orders'       => $api->handleOrders(),
    '/api/generate'     => $api->handleGenerate(),
    '/api/recalculate'  => $api->handleRecalculate(),
    default             => (new AppController())->index(),
};
