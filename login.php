<?php
// ============================================================
// ENDPOINT LOGIN & LOGOUT
// ============================================================
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();
$action = $_GET['action'] ?? $body['action'] ?? 'login';

// ---------- GET: Cek status sesi ----------
if ($method === 'GET') {
    if (!empty($_SESSION['user'])) {
        jsonResponse(true, 'Sesi aktif', $_SESSION['user']);
    } else {
        jsonResponse(false, 'Belum login', null, 200);
    }

// ---------- POST ----------
} elseif ($method === 'POST') {

    // LOGIN
    if ($action === 'login') {
        $username = trim($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');

        if ($username === '') {
            jsonResponse(false, 'Username atau nama wajib diisi.', null, 400);
        }

        // Cek login via tabel `user` (yang berelasi dengan `karyawan`)
        // Mendukung login cepat: 'admin', 'budi', 'siti', 'agus',
        // atau via email ('admin@jamkerja.com'), maupun nama lengkap ('Budi Santoso').
        $prefix = preg_replace('/@.*$/', '', strtolower($username));

        $stmt = $pdo->prepare("
            SELECT 
                u.id_user,
                COALESCE(u.id_karyawan, 0) AS id_karyawan,
                u.email,
                u.kata_sandi,
                u.peran,
                COALESCE(k.nama, CASE WHEN u.peran = 'admin' THEN 'Administrator' ELSE u.email END) AS nama,
                COALESCE(k.jabatan, CASE WHEN u.peran = 'admin' THEN 'Super Admin' ELSE 'Pekerja' END) AS jabatan,
                COALESCE(k.tarif_per_jam, 0) AS tarif_per_jam
            FROM user u
            LEFT JOIN karyawan k ON u.id_karyawan = k.id_karyawan
            WHERE u.email = ?
               OR u.email LIKE ?
               OR k.nama = ?
               OR k.nama LIKE ?
            LIMIT 1
        ");
        $stmt->execute([
            $username,
            $prefix . '@%',
            $username,
            '%' . $username . '%'
        ]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(false, 'Akun "' . htmlspecialchars($username) . '" tidak ditemukan.', null, 401);
        }

        $storedPassword = (string)($user['kata_sandi'] ?? '');
        unset($user['kata_sandi']); // Hapus password agar tidak bocor ke frontend

        // Jika password diisi, lakukan verifikasi (bcrypt / plaintext)
        // Jika password dikosongkan (seperti tombol Quick Login), izinkan masuk
        if ($password !== '') {
            $valid = false;
            if (password_get_info($storedPassword)['algo'] !== null && password_get_info($storedPassword)['algo'] !== 0) {
                // Password di-hash dengan bcrypt/argon
                $valid = password_verify($password, $storedPassword);
            } else {
                // Plaintext fallback (untuk data development)
                $valid = ($password === $storedPassword);
            }

            if (!$valid) {
                jsonResponse(false, 'Password salah.', null, 401);
            }
        }

        // Simpan ke sesi
        $_SESSION['user'] = $user;

        jsonResponse(true, 'Login berhasil! Selamat datang, ' . $user['nama'], $user);

    // LOGOUT
    } elseif ($action === 'logout') {
        session_destroy();
        jsonResponse(true, 'Berhasil logout.');

    } else {
        jsonResponse(false, 'Aksi tidak dikenal.', null, 400);
    }

} else {
    jsonResponse(false, 'Metode HTTP tidak didukung.', null, 405);
}

