<?php
// ============================================================
// ENDPOINT PEKERJAAN (PROYEK LAPANGAN)
// ============================================================
require_once __DIR__ . '/config/database.php';
$currentUser = requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = ($currentUser['peran'] === 'admin');
$kId = intval($currentUser['id_karyawan'] ?? 0);

if ($method === 'GET') {
    if ($isAdmin) {
        // Admin dapat melihat seluruh proyek
        $stmt = $pdo->query("SELECT id_pekerjaan, nama_pekerjaan, lokasi, status FROM pekerjaan ORDER BY id_pekerjaan DESC");
        $data = $stmt->fetchAll();
    } else {
        // Karyawan melihat proyek yang ditugaskan kepadanya, atau semua proyek aktif
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.id_pekerjaan, p.nama_pekerjaan, p.lokasi, p.status
            FROM pekerjaan p
            LEFT JOIN penugasan pn ON p.id_pekerjaan = pn.id_pekerjaan AND pn.id_karyawan = ?
            WHERE (pn.id_karyawan = ? OR p.status = 'berjalan')
            ORDER BY p.id_pekerjaan DESC
        ");
        $stmt->execute([$kId, $kId]);
        $data = $stmt->fetchAll();
    }

    jsonResponse(true, "Data proyek berhasil dimuat", $data);
} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}

