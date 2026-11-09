<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    $connection = getDatabaseConnection();
    $statement = $connection->prepare('SELECT 1');
    $statement->execute();

    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('Query pengujian koneksi tidak berhasil.');
    }

    echo json_encode(
        [
            'success' => true,
            'message' => 'Koneksi database berhasil',
        ],
        JSON_UNESCAPED_UNICODE,
    );
} catch (Throwable) {
    error_log('Database connection test failed.');
    http_response_code(500);
    echo json_encode(
        [
            'success' => false,
            'message' => 'Koneksi database gagal. Periksa layanan database dan konfigurasi lokal.',
        ],
        JSON_UNESCAPED_UNICODE,
    );
}
