<?php
// ==========================================================
// BACKEND KHUSUS KARYAWAN: LIHAT SLIP GAJI BULANAN PRIBADI
// ==========================================================
require_once __DIR__ . '/../config/database.php';

// Otorisasi: Wajib Login
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

    // FITUR 1: RINCIAN / BREAKDOWN HARI KERJA PRIBADI
    if ($action === 'detail' || isset($_GET['detail'])) {
        // 1. Rincian Presensi Harian Disetujui & Gaji yang Diterima
        $stmtAbsensi = $pdo->prepare("
            SELECT a.id_absensi, a.tanggal, a.jam_masuk, a.jam_pulang, a.catatan,
                   p.nama_pekerjaan,
                   k.tarif_per_jam AS tarif,
                   ROUND($sqlDurasi, 2) AS durasi_jam,
                   ROUND($sqlDurasi * k.tarif_per_jam, 2) AS gaji_diterima
            FROM absensi a
            JOIN karyawan k ON a.id_karyawan = k.id_karyawan
            LEFT JOIN pekerjaan p ON a.id_pekerjaan = p.id_pekerjaan
            WHERE a.id_karyawan = ?
              AND DATE_FORMAT(a.tanggal, '%Y-%m') = ?
              AND a.status_verifikasi = 'disetujui'
              AND a.jam_pulang IS NOT NULL
            ORDER BY a.tanggal ASC, a.jam_masuk ASC
        ");
        $stmtAbsensi->execute([$kId, $periode]);
        $absensiList = $stmtAbsensi->fetchAll();

        // 2. Rincian Pengeluaran Pribadi (Reimbursement) Disetujui
        $stmtPengeluaran = $pdo->prepare("
            SELECT pe.id_pengeluaran, pe.tanggal, pe.nominal, pe.keterangan, pe.foto_kwitansi, pe.status_ganti,
                   p.nama_pekerjaan
            FROM pengeluaran pe
            LEFT JOIN pekerjaan p ON pe.id_pekerjaan = p.id_pekerjaan
            WHERE pe.id_karyawan = ?
              AND DATE_FORMAT(pe.tanggal, '%Y-%m') = ?
              AND pe.jenis_dana = 'pribadi'
              AND pe.status_verifikasi = 'disetujui'
            ORDER BY pe.tanggal ASC
        ");
        $stmtPengeluaran->execute([$kId, $periode]);
        $pengeluaranList = $stmtPengeluaran->fetchAll();

        jsonResponse(true, "Rincian presensi & reimbursement pribadi berhasil dimuat", [
            "id_karyawan" => $kId,
            "periode" => $periode,
            "absensi" => $absensiList,
            "pengeluaran" => $pengeluaranList
        ]);
    }

    $sql = "
        SELECT g.*,
               k.nama AS nama_karyawan, k.jabatan,
               IFNULL((
                   SELECT GROUP_CONCAT(DISTINCT p.nama_pekerjaan SEPARATOR ', ')
                   FROM absensi a
                   JOIN pekerjaan p ON a.id_pekerjaan = p.id_pekerjaan
                   WHERE a.id_karyawan = g.id_karyawan
                     AND DATE_FORMAT(a.tanggal, '%Y-%m') = g.periode
                     AND a.status_verifikasi = 'disetujui'
               ), 'Operasional Lapangan') AS nama_pekerjaan
        FROM gaji g
        JOIN karyawan k ON g.id_karyawan = k.id_karyawan
        WHERE g.id_karyawan = ? AND g.periode = ?
        ORDER BY g.id_gaji DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$kId, $periode]);
    $data = $stmt->fetchAll();

    jsonResponse(true, "Data slip gaji periode $periode berhasil dimuat (Karyawan)", $data);

} else {
    // Karyawan tidak diizinkan menghitung atau mengubah status bayar gaji
    jsonResponse(false, "Akses ditolak. Perhitungan dan pembayaran gaji hanya dapat dilakukan oleh Administrator.", null, 403);
}
