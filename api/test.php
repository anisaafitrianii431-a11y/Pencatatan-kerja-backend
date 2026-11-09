<?php
require_once __DIR__ . '/config/database.php';

try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    jsonResponse(true, "Koneksi database MySQL di Laragon BERHASIL!", [
        "database" => "db_jam_kerja",
        "tables" => $tables
    ], 200);
} catch (Exception $e) {
    jsonResponse(false, "Gagal terhubung ke database: " . $e->getMessage(), null, 200);
}
