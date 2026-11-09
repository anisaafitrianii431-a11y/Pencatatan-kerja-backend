<?php

declare(strict_types=1);

function applyCors(array $config): void
{
    header('Vary: Origin');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = $config['allowed_origins'] ?? [];

    if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
        sendJsonResponse(false, 'Origin tidak diizinkan', null, 403);
    }

    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        header(
            'Access-Control-Allow-Methods: ' .
                implode(', ', $config['allowed_methods'] ?? []),
        );
        header(
            'Access-Control-Allow-Headers: ' .
                implode(', ', $config['allowed_headers'] ?? []),
        );
        header('Access-Control-Max-Age: 600');
    }
}
