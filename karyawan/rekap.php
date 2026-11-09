<?php
// ==========================================================
// BACKEND KHUSUS KARYAWAN: REKAPITULASI PRIBADI & KONTRIBUSI
// ==========================================================
require_once __DIR__ . '/../config/database.php';

// Otorisasi: Wajib Login
$currentUser = requireLogin();
$type = $_GET['type'] ?? 'dashboard';
$kId = intval($currentUser['id_karyawan'] ?? 0);

// Formula SQL Durasi Kerja (Mendukung Shift Malam - F-02)
$sqlDurasi = "
    CASE 
        WHEN a.jam_pulang IS NOT NULL AND a.jam_pulang >= a.jam_masuk 
            THEN (TIME_TO_SEC(TIMEDIFF(a.jam_pulang, a.jam_masuk)) / 3600)
        WHEN a.jam_pulang IS NOT NULL AND a.jam_pulang < a.jam_masuk 
            THEN ((TIME_TO_SEC(TIMEDIFF(a.jam_pulang, a.jam_masuk)) + 86400) / 3600)
        ELSE 0 
    END
";

if ($type === 'dashboard') {
    // Ringkasan personal pekerja
    $stmtPekAktif = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id_pekerjaan) 
        FROM pekerjaan p 
        JOIN penugasan pn ON p.id_pekerjaan = pn.id_pekerjaan 
        WHERE pn.id_karyawan = ? AND p.status = 'berjalan'
    ");
    $stmtPekAktif->execute([$kId]);
    $pekerjaanAktif = $stmtPekAktif->fetchColumn();

    $stmtTotPek = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id_pekerjaan) 
        FROM pekerjaan p 
        JOIN penugasan pn ON p.id_pekerjaan = pn.id_pekerjaan 
        WHERE pn.id_karyawan = ?
    ");
    $stmtTotPek->execute([$kId]);
    $totalPekerjaan = $stmtTotPek->fetchColumn();

    $stmtAbsMenunggu = $pdo->prepare("SELECT COUNT(*) FROM absensi WHERE id_karyawan = ? AND status_verifikasi = 'menunggu'");
    $stmtAbsMenunggu->execute([$kId]);
    $absensiMenunggu = $stmtAbsMenunggu->fetchColumn();

    $stmtPengMenunggu = $pdo->prepare("SELECT COUNT(*) FROM pengeluaran WHERE id_karyawan = ? AND status_verifikasi = 'menunggu'");
    $stmtPengMenunggu->execute([$kId]);
    $pengeluaranMenunggu = $stmtPengMenunggu->fetchColumn();

    $stmtJam = $pdo->prepare("
        SELECT IFNULL(SUM($sqlDurasi), 0)
        FROM absensi a
        WHERE a.id_karyawan = ?
          AND a.status_verifikasi = 'disetujui' 
          AND a.jam_pulang IS NOT NULL
          AND DATE_FORMAT(a.tanggal, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
    ");
    $stmtJam->execute([$kId]);
    $totalJamBulanIni = $stmtJam->fetchColumn();

    $stmtPengeluaran = $pdo->prepare("
        SELECT IFNULL(SUM(nominal), 0)
        FROM pengeluaran 
        WHERE id_karyawan = ?
          AND status_verifikasi = 'disetujui'
          AND DATE_FORMAT(tanggal, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
    ");
    $stmtPengeluaran->execute([$kId]);
    $totalPengeluaranBulanIni = $stmtPengeluaran->fetchColumn();

    jsonResponse(true, "Dashboard stats personal berhasil dimuat (Karyawan)", [
        "total_pekerja" => 1,
        "pekerjaan_aktif" => intval($pekerjaanAktif),
        "total_pekerjaan" => intval($totalPekerjaan),
        "absensi_menunggu" => intval($absensiMenunggu),
        "pengeluaran_menunggu" => intval($pengeluaranMenunggu),
        "total_jam_bulan_ini" => round(floatval($totalJamBulanIni), 2),
        "total_pengeluaran_bulan_ini" => floatval($totalPengeluaranBulanIni)
    ]);

} elseif ($type === 'kontribusi') {
    // Persentase Kontribusi pekerja pada pekerjaan yang ditugaskan kepadanya atau yang pernah dikerjakannya
    $id_pekerjaan = isset($_GET['id_pekerjaan']) ? intval($_GET['id_pekerjaan']) : 0;
    $whereJob = ($id_pekerjaan > 0) ? "AND p.id_pekerjaan = $id_pekerjaan" : "";

    $sqlPekerjaan = "
        SELECT DISTINCT p.id_pekerjaan, p.nama_pekerjaan, p.status, p.lokasi 
        FROM pekerjaan p 
        LEFT JOIN penugasan pn ON p.id_pekerjaan = pn.id_pekerjaan AND pn.id_karyawan = ?
        LEFT JOIN absensi a ON p.id_pekerjaan = a.id_pekerjaan AND a.id_karyawan = ?
        WHERE (pn.id_karyawan IS NOT NULL OR a.id_karyawan IS NOT NULL) $whereJob
        ORDER BY p.id_pekerjaan DESC
    ";
    $stmtPek = $pdo->prepare($sqlPekerjaan);
    $stmtPek->execute([$kId, $kId]);
    $pekerjaanList = $stmtPek->fetchAll();

    $result = [];
    foreach ($pekerjaanList as $job) {
        $jId = $job['id_pekerjaan'];

        $stmtTot = $pdo->prepare("
            SELECT IFNULL(SUM($sqlDurasi), 0) AS total_jam
            FROM absensi a
            WHERE a.id_pekerjaan = ? AND a.status_verifikasi = 'disetujui' AND a.jam_pulang IS NOT NULL
        ");
        $stmtTot->execute([$jId]);
        $totalJamJob = floatval($stmtTot->fetch()['total_jam']);

        $stmtPekerja = $pdo->prepare("
            SELECT 
                k.id_karyawan, 
                k.nama, 
                k.jabatan,
                k.tarif_per_jam,
                IFNULL(SUM($sqlDurasi), 0) AS jam_pekerja
            FROM (
                SELECT id_karyawan, id_pekerjaan FROM penugasan WHERE id_pekerjaan = ?
                UNION
                SELECT id_karyawan, id_pekerjaan FROM absensi WHERE id_pekerjaan = ? AND status_verifikasi = 'disetujui'
            ) AS assigned
            JOIN karyawan k ON assigned.id_karyawan = k.id_karyawan
            LEFT JOIN absensi a ON a.id_pekerjaan = assigned.id_pekerjaan 
                               AND a.id_karyawan = assigned.id_karyawan 
                               AND a.status_verifikasi = 'disetujui'
                               AND a.jam_pulang IS NOT NULL
            WHERE assigned.id_pekerjaan = ?
            GROUP BY k.id_karyawan, k.nama, k.jabatan, k.tarif_per_jam
            ORDER BY jam_pekerja DESC
        ");
        $stmtPekerja->execute([$jId, $jId, $jId]);
        $pekerjaStats = $stmtPekerja->fetchAll();

        foreach ($pekerjaStats as &$ps) {
            $jP = floatval($ps['jam_pekerja']);
            $ps['jam_pekerja'] = round($jP, 2);
            $ps['total_jam_pekerja'] = round($jP, 2);
            $ps['persentase'] = ($totalJamJob > 0) ? round(($jP / $totalJamJob) * 100, 1) : 0;
            $ps['estimasi_upah'] = round($jP * floatval($ps['tarif_per_jam'] ?? 50000), 2);
            $ps['is_saya'] = (intval($ps['id_karyawan']) === $kId);
        }

        $result[] = [
            "id_pekerjaan" => $job['id_pekerjaan'],
            "nama_pekerjaan" => $job['nama_pekerjaan'],
            "lokasi" => $job['lokasi'] ?? 'Kantor Cabang / Lapangan',
            "status" => $job['status'],
            "total_jam_proyek" => round($totalJamJob, 2),
            "kontribusi" => $pekerjaStats,
            "kontributor" => $pekerjaStats
        ];
    }

    jsonResponse(true, "Data persentase kontribusi berhasil dimuat (Karyawan)", $result);

} elseif ($type === 'jam_kerja') {
    // Total jam kerja pribadi pekerja
    $sql = "
        SELECT k.id_karyawan, k.nama, k.jabatan, k.tarif_per_jam,
               COUNT(DISTINCT a.id_pekerjaan) AS total_proyek_dikerjakan,
               COUNT(DISTINCT CASE WHEN a.status_verifikasi = 'disetujui' THEN a.id_absensi ELSE NULL END) AS jumlah_sesi_disetujui,
               COUNT(a.id_absensi) AS total_sesi_kerja,
               IFNULL(SUM(CASE WHEN a.status_verifikasi = 'disetujui' THEN $sqlDurasi ELSE 0 END), 0) AS jam_disetujui,
               IFNULL(SUM(CASE WHEN a.status_verifikasi = 'menunggu' THEN $sqlDurasi ELSE 0 END), 0) AS jam_menunggu
        FROM karyawan k
        LEFT JOIN absensi a ON k.id_karyawan = a.id_karyawan AND a.jam_pulang IS NOT NULL
        WHERE k.id_karyawan = ?
        GROUP BY k.id_karyawan, k.nama, k.jabatan, k.tarif_per_jam
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$kId]);
    $data = $stmt->fetchAll();

    foreach ($data as &$row) {
        $jDisetujui = round(floatval($row['jam_disetujui']), 2);
        $row['jam_disetujui'] = $jDisetujui;
        $row['total_jam'] = $jDisetujui;
        $row['jam_menunggu'] = round(floatval($row['jam_menunggu']), 2);
        $estUpah = round($jDisetujui * floatval($row['tarif_per_jam']), 2);
        $row['estimasi_upah'] = $estUpah;
        $row['total_upah'] = $estUpah;
        $row['jumlah_sesi_disetujui'] = intval($row['jumlah_sesi_disetujui'] ?? $row['total_sesi_kerja']);
    }

    jsonResponse(true, "Rekap jam kerja pribadi berhasil dimuat (Karyawan)", $data);

} else {
    jsonResponse(false, "Tipe rekap tidak valid", null, 400);
}
