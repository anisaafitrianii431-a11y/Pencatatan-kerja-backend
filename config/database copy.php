<?php
// ============================================================
// KONFIGURASI DATABASE & FUNGSI HELPER GLOBAL
// ============================================================

// ---------- CORS Headers ----------
// Izinkan akses dinamis dari frontend (localhost di port berapa pun)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    if (preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    }
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ---------- Koneksi Database (PDO) ----------
// Sesuaikan dengan konfigurasi XAMPP / Laragon / MySQL Anda
$DB_HOST = 'localhost';
$DB_NAME = 'skema_gaji_dan_rekap';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database gagal: ' . $e->getMessage(),
        'data'    => null,
    ]);
    exit;
}

// ---------- Session ----------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 7, // 7 hari agar sesi tidak mudah hilang
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ============================================================
// FUNGSI HELPER
// ============================================================

/**
 * Kirim response JSON standar dan hentikan eksekusi.
 */
function jsonResponse(bool $success, string $message, $data = null, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ambil body request (JSON decode).
 */
function getRequestBody(): array
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    return is_array($body) ? $body : [];
}

/**
 * Wajib login. Kembalikan data user dari sesi, atau tolak dengan 401.
 */
function requireLogin(): array
{
    if (empty($_SESSION['user'])) {
        jsonResponse(false, 'Anda belum login. Silakan login terlebih dahulu.', null, 401);
    }
    return $_SESSION['user'];
}

/**
 * Wajib admin. Kembalikan data user jika peran = admin, atau tolak dengan 403.
 */
function requireAdmin(): array
{
    $user = requireLogin();
    if (($user['peran'] ?? '') !== 'admin') {
        jsonResponse(false, 'Akses ditolak. Halaman ini hanya untuk Administrator.', null, 403);
    }
    return $user;
}

