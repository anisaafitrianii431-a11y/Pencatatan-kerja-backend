<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'response.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'middleware' . DIRECTORY_SEPARATOR . 'cors.php';

$corsConfig = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cors.php';
applyCors($corsConfig);

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';

$scriptDirectory = str_replace(
    '\\',
    '/',
    dirname($_SERVER['SCRIPT_NAME'] ?? ''),
);
$basePath = rtrim($scriptDirectory, '/.');

if (
    $basePath !== '' &&
    ($requestPath === $basePath ||
        str_starts_with($requestPath, $basePath . '/'))
) {
    $requestPath = substr($requestPath, strlen($basePath));
}

$requestPath = '/' . trim($requestPath, '/');
if ($requestPath === '//') {
    $requestPath = '/';
}

$routes = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php';
$route = $routes[$requestPath] ?? null;
$routeParameters = [];

if ($route === null) {
    foreach ($routes as $routePattern => $candidateRoute) {
        if (!str_contains($routePattern, '{id}')) {
            continue;
        }

        $pattern = str_replace(
            '\{id\}',
            '([^/]+)',
            preg_quote($routePattern, '#'),
        );

        if (preg_match('#^' . $pattern . '$#D', $requestPath, $matches)) {
            $route = $candidateRoute;
            $routeParameters = array_slice($matches, 1);
            break;
        }
    }
}

if ($route === null) {
    sendJsonResponse(false, 'Endpoint tidak ditemukan', null, 404);
}

$routeMethods = array_keys($route);
$allowedRouteMethods = [...$routeMethods, 'OPTIONS'];
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'OPTIONS') {
    header('Allow: ' . implode(', ', $allowedRouteMethods));
    sendJsonResponse(true, 'Preflight berhasil', null);
}

if (!isset($route[$requestMethod])) {
    header('Allow: ' . implode(', ', $allowedRouteMethods));
    sendJsonResponse(false, 'Method tidak diizinkan untuk endpoint ini', null, 405);
}

try {
    $route[$requestMethod](...$routeParameters);
} catch (Throwable $exception) {
    error_log('API route dispatch failed: ' . get_class($exception));
    sendJsonResponse(false, 'Terjadi kesalahan pada server', null, 500);
}
