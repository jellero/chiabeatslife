<?php
declare(strict_types=1);

use Slim\Psr7\Factory\ServerRequestFactory;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php mancante\n");
    exit(1);
}
require_once $autoload;

$routes = require $root . '/config/routes.php';
if (!is_array($routes) || $routes === []) {
    fwrite(STDERR, "Nessuna route importata\n");
    exit(1);
}

$paths = [];
$hasHome = false;
foreach ($routes as $route) {
    if (!is_array($route)) {
        fwrite(STDERR, "Definizione route non valida\n");
        exit(1);
    }
    $path = (string) ($route['path'] ?? '');
    $page = (string) ($route['page'] ?? '');
    if ($path === '' || $page === '') {
        fwrite(STDERR, "Route incompleta\n");
        exit(1);
    }
    if (isset($paths[$path])) {
        fwrite(STDERR, "Route duplicata: {$path}\n");
        exit(1);
    }
    $paths[$path] = true;
    $hasHome = $hasHome || $path === '/';
    if (!is_file($root . '/storage/pages/' . basename($page) . '.json')) {
        fwrite(STDERR, "Snapshot mancante per {$path}: {$page}\n");
        exit(1);
    }
}
if (!$hasHome) {
    fwrite(STDERR, "Route home mancante\n");
    exit(1);
}

$reportFile = $root . '/storage/migration-report.json';
if (!is_file($reportFile)) {
    fwrite(STDERR, "Report migrazione mancante\n");
    exit(1);
}
$report = json_decode((string) file_get_contents($reportFile), true, flags: JSON_THROW_ON_ERROR);
if (($report['css_assets'] ?? 0) < 1 || ($report['js_assets'] ?? 0) < 1) {
    fwrite(STDERR, "CSS o JavaScript legacy non importati\n");
    exit(1);
}

$app = require $root . '/bootstrap/app.php';
$factory = new ServerRequestFactory();

$health = $app->handle($factory->createServerRequest('GET', '/api/v1/health'));
if ($health->getStatusCode() !== 200 || !str_contains((string) $health->getBody(), 'slim-4')) {
    fwrite(STDERR, "Health check Slim non valido\n");
    exit(1);
}

$home = $app->handle($factory->createServerRequest('GET', '/'));
if ($home->getStatusCode() !== 200 || strlen((string) $home->getBody()) < 500) {
    fwrite(STDERR, "Rendering home non valido\n");
    exit(1);
}

$notFound = $app->handle($factory->createServerRequest('GET', '/__migration-smoke-404__/'));
if ($notFound->getStatusCode() !== 404) {
    fwrite(STDERR, "Gestione 404 non valida\n");
    exit(1);
}

echo 'Smoke OK: ' . count($routes) . " route, " . (int) ($report['assets'] ?? 0) . " asset.\n";
