<?php
// ==========================================================
// API KARYAWAN: LIHAT SLIP GAJI BULANAN PRIBADI
// Karyawan hanya dapat melihat slip gajinya sendiri yang sudah ditetapkan
// ==========================================================

require_once __DIR__ . '/../config/database.php';

$currentUser = requireLogin();
$method = $_SERVER['REQUEST_METHOD'];
$kId = intval($currentUser['id_karyawan'] ?? 0);
$action = $_GET['action'] ?? '';

// Formula SQL Durasi Kerja (Mendukung Shift Malam)
$sqlDurasi = "
    CASE 
        WHEN a.jam_pulang >= a.jam_masuk 
            THEN (TIME_TO_SEC(TIMEDIFF(a.jam_pulang, a.jam_masuk)) / 3600)
        ELSE 
            ((TIME_TO_SEC(TIMEDIFF(a.jam_pulang, a.jam_masuk)) + 86400) / 3600)
    END
";

if ($method === 'GET') {
    $periode = $_GET['periode'] ?? date('Y-m');

    if ($kId <= 0) {
        jsonResponse(false, "Akun tidak terhubung dengan data karyawan", null, 403);
    }

    if ($action === 'detail' || $action === 'slip') {
        $id_gaji = intval($_GET['id_gaji'] ?? 0);

        $whereG = "WHERE g.id_karyawan = ?";
        $paramsG = [$kId];
        if ($id_gaji > 0) {
            $whereG .= " AND g.id_gaji = ?";
            $paramsG[] = $id_gaji;
        } else {
            $whereG .= " AND g.periode = ?";
            $paramsG[] = $periode;
        }

        $stmtG = $pdo->prepare("
            SELECT g.*,
                   k.nama AS nama_karyawan, k.kode_karyawan, k.jabatan, k.nomor_hp, k.email
            FROM gaji g
            JOIN karyawan k ON g.id_karyawan = k.id_karyawan
            $whereG
            LIMIT 1
        ");
        $stmtG->execute($paramsG);
        $gaji = $stmtG->fetch(PDO::FETCH_ASSOC);

        if (!$gaji) {
            jsonResponse(false, "Slip gaji tidak ditemukan", null, 404);
        }

        // Riwayat pembayaran
        $stmtPay = $pdo->prepare("
            SELECT id_pembayaran, tanggal_bayar, nominal_bayar, metode_pembayaran, nomor_referensi, bukti_pembayaran, catatan
            FROM pembayaran_gaji
            WHERE id_gaji = ?
            ORDER BY id_pembayaran ASC
        ");
        $stmtPay->execute([$gaji['id_gaji']]);
        $pembayaranList = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

        // Presensi disetujui
        $stmtAbsensi = $pdo->prepare("
            SELECT a.id_absensi, a.tanggal, a.jam_masuk, a.jam_pulang,
                   p.nama_pekerjaan,
                   ROUND($sqlDurasi, 2) AS durasi_jam
            FROM absensi a
            LEFT JOIN pekerjaan p ON a.id_pekerjaan = p.id_pekerjaan
            WHERE a.id_karyawan = ?
              AND DATE_FORMAT(a.tanggal, '%Y-%m') = ?
              AND a.status_verifikasi = 'disetujui'
              AND a.jam_pulang IS NOT NULL
            ORDER BY a.tanggal ASC, a.jam_masuk ASC
        ");
        $stmtAbsensi->execute([$kId, $gaji['periode']]);
        $absensiList = $stmtAbsensi->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, "Slip gaji berhasil dimuat", [
            'gaji' => $gaji,
            'pembayaran' => $pembayaranList,
            'absensi' => $absensiList
        ]);
    }

    // Default: Slip gaji pribadi untuk periode tertentu
    $stmt = $pdo->prepare("
        SELECT g.*,
               k.nama AS nama_karyawan, k.kode_karyawan, k.jabatan
        FROM gaji g
        JOIN karyawan k ON g.id_karyawan = k.id_karyawan
        WHERE g.id_karyawan = ? AND g.periode = ?
        ORDER BY g.id_gaji DESC
    ");
    $stmt->execute([$kId, $periode]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, "Data slip gaji periode $periode berhasil dimuat", $data);

} else {
    jsonResponse(false, "Akses ditolak. Operasi ini hanya untuk Administrator.", null, 403);
}
