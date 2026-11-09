<?php
// Nonaktifkan display_errors HTML agar respon selalu berupa JSON murni tanpa tag <br> atau <b>
error_reporting(0);
ini_set('display_errors', '0');

// CORS Headers untuk komunikasi dengan React Vite
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = "localhost";
$dbname = "db_jam_kerja";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // Kembalikan JSON dengan status 200 agar Apache tidak meng-intercept dengan halaman error HTML
    echo json_encode([
        "success" => false,
        "is_db_connected" => false,
        "message" => "Database MySQL di Laragon belum terhubung atau database 'db_jam_kerja' belum diimpor.",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Helper untuk membaca request body JSON
function getRequestBody() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// Helper respon JSON standar
function jsonResponse($success, $message, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}
