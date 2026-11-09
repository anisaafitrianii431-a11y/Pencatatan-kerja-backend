<?php

declare(strict_types=1);

return [
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],
    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],
    'allowed_headers' => [
        'Accept',
        'Content-Type',
    ],
];
