<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();
$action = $_GET['action'] ?? $body['action'] ?? '';

if ($method === 'GET') {
    $periode = $_GET['periode'] ?? date('Y-m');
    $id_karyawan = isset($_GET['id_karyawan']) ? intval($_GET['id_karyawan']) : 0;

    $where = ["g.periode = ?"];
    $params = [$periode];

    if ($id_karyawan > 0) {
        $where[] = "g.id_karyawan = ?";
        $params[] = $id_karyawan;
    }

    $whereClause = "WHERE " . implode(" AND ", $where);

    $sql = "
        SELECT g.*,
               k.nama AS nama_karyawan, k.jabatan
        FROM gaji g
        JOIN karyawan k ON g.id_karyawan = k.id_karyawan
        $whereClause
        ORDER BY g.id_gaji DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse(true, "Data gaji periode $periode", $data);

} elseif ($method === 'POST') {
    // 1. HITUNG GAJI OTOMATIS BULANAN
    if ($action === 'hitung') {
        $periode = $body['periode'] ?? date('Y-m');

        // Ambil semua karyawan aktif
        $karyawanList = $pdo->query("SELECT id_karyawan, nama, tarif_per_jam FROM karyawan")->fetchAll();

        $dihitung = 0;
        foreach ($karyawanList as $k) {
            $id_k = $k['id_karyawan'];
            $tarif = floatval($k['tarif_per_jam']);

            // 1. Hitung total jam disetujui pada periode bulan ini
            $stmtJam = $pdo->prepare("
                SELECT IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(jam_pulang, jam_masuk))/3600), 0) AS total_jam
                FROM absensi
                WHERE id_karyawan = ? 
                  AND status_verifikasi = 'disetujui' 
                  AND jam_pulang IS NOT NULL
                  AND DATE_FORMAT(tanggal, '%Y-%m') = ?
            ");
            $stmtJam->execute([$id_k, $periode]);
            $totalJam = round(floatval($stmtJam->fetch()['total_jam']), 2);

            // 2. Hitung total upah
            $totalUpah = round($totalJam * $tarif, 2);

            // 3. Hitung total ganti (uang pribadi disetujui pada bulan ini)
            $stmtGanti = $pdo->prepare("
                SELECT IFNULL(SUM(nominal), 0) AS total_ganti
                FROM pengeluaran
                WHERE id_karyawan = ? 
                  AND jenis_dana = 'pribadi' 
                  AND status_verifikasi = 'disetujui'
                  AND DATE_FORMAT(tanggal, '%Y-%m') = ?
            ");
            $stmtGanti->execute([$id_k, $periode]);
            $totalGanti = round(floatval($stmtGanti->fetch()['total_ganti']), 2);

            $totalDibayar = $totalUpah + $totalGanti;

            // Simpan / Perbarui gaji untuk periode ini
            $stmtSave = $pdo->prepare("
                INSERT INTO gaji (id_karyawan, periode, total_jam, tarif, total_upah, total_ganti, total_dibayar, status_bayar)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'belum')
                ON DUPLICATE KEY UPDATE
                    total_jam = VALUES(total_jam),
                    tarif = VALUES(tarif),
                    total_upah = VALUES(total_upah),
                    total_ganti = VALUES(total_ganti),
                    total_dibayar = VALUES(total_dibayar)
            ");
            $stmtSave->execute([$id_k, $periode, $totalJam, $tarif, $totalUpah, $totalGanti, $totalDibayar]);
            $dihitung++;
        }

        jsonResponse(true, "Perhitungan gaji periode $periode berhasil diproses untuk $dihitung pekerja!");

    // 2. TANDAI SUDAH DIBAYAR
    } elseif ($action === 'bayar') {
        $id_gaji = intval($body['id_gaji'] ?? 0);
        if ($id_gaji <= 0) {
            jsonResponse(false, "ID Gaji tidak valid", null, 400);
        }

        $stmt = $pdo->prepare("
            UPDATE gaji 
            SET status_bayar = 'sudah', tanggal_bayar = NOW() 
            WHERE id_gaji = ?
        ");
        $stmt->execute([$id_gaji]);

        // Tandai juga pengeluaran pribadi karyawan pada periode tersebut menjadi sudah diganti
        $stmtGet = $pdo->prepare("SELECT id_karyawan, periode FROM gaji WHERE id_gaji = ?");
        $stmtGet->execute([$id_gaji]);
        $g = $stmtGet->fetch();
        if ($g) {
            $stmtReimburse = $pdo->prepare("
                UPDATE pengeluaran 
                SET status_ganti = 'sudah' 
                WHERE id_karyawan = ? 
                  AND jenis_dana = 'pribadi' 
                  AND status_verifikasi = 'disetujui'
                  AND DATE_FORMAT(tanggal, '%Y-%m') = ?
            ");
            $stmtReimburse->execute([$g['id_karyawan'], $g['periode']]);
        }

        jsonResponse(true, "Status gaji berhasil diperbarui menjadi SUDAH DIBAYAR!");
    } else {
        jsonResponse(false, "Aksi tidak dikenal", null, 400);
    }
} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}

