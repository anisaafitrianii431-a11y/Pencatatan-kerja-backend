<?php

declare(strict_types=1);

function getDatabaseConnection(): \PDO
{
    static $connection = null;

    if ($connection instanceof \PDO) {
        return $connection;
    }

    $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    $fileEnvironment = [];

    if (is_file($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('File konfigurasi lokal tidak dapat dibaca.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if (
                strlen($value) >= 2 &&
                (($value[0] === '"' && $value[-1] === '"') ||
                    ($value[0] === "'" && $value[-1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                $fileEnvironment[$key] = $value;
            }
        }
    }

    $readEnvironment = static function (string $key, string $default) use (
        $fileEnvironment,
    ): string {
        $systemValue = getenv($key);
        if ($systemValue !== false) {
            return $systemValue;
        }

        return $fileEnvironment[$key] ?? $default;
    };

    $host = $readEnvironment('DB_HOST', 'localhost');
    $port = $readEnvironment('DB_PORT', '3306');
    $name = $readEnvironment('DB_NAME', 'db_pencatatan_kerja');
    $user = $readEnvironment('DB_USER', 'root');
    $password = $readEnvironment('DB_PASS', '');

    if (
        !ctype_digit($port) ||
        (int) $port < 1 ||
        (int) $port > 65535 ||
        $host === '' ||
        $name === ''
    ) {
        throw new \RuntimeException('Konfigurasi database lokal tidak valid.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        (int) $port,
        $name,
    );

    try {
        $connection = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (\PDOException $exception) {
        error_log(
            'Database connection failed. Check the local database service and backend configuration.',
        );
        throw new \RuntimeException(
            'Koneksi database gagal. Periksa layanan database dan konfigurasi lokal.',
            0,
            $exception,
        );
    }

    return $connection;
}