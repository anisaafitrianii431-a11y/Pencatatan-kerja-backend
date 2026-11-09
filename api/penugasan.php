<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();

if ($method === 'GET') {
    $id_pekerjaan = isset($_GET['id_pekerjaan']) ? intval($_GET['id_pekerjaan']) : 0;
    $id_karyawan = isset($_GET['id_karyawan']) ? intval($_GET['id_karyawan']) : 0;

    if ($id_pekerjaan > 0) {
        // Hitung total jam seluruh pekerja pada proyek ini untuk rumus persentase kontribusi
        $stmtTotal = $pdo->prepare("
            SELECT IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(jam_pulang, jam_masuk))/3600), 0) AS total_jam_proyek
            FROM absensi 
            WHERE id_pekerjaan = ? AND jam_pulang IS NOT NULL AND status_verifikasi = 'disetujui'
        ");
        $stmtTotal->execute([$id_pekerjaan]);
        $totalProyek = floatval($stmtTotal->fetch()['total_jam_proyek'] ?? 0);

        // Ambil daftar pekerja yang ditugaskan beserta jam kerja & persentase kontribusi
        $stmt = $pdo->prepare("
            SELECT p.id_penugasan, p.id_pekerjaan, p.id_karyawan, p.peran_tugas, p.created_at,
                   k.nama, k.jabatan, k.tarif_per_jam,
                   (
                       SELECT IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(a.jam_pulang, a.jam_masuk))/3600), 0)
                       FROM absensi a
                       WHERE a.id_pekerjaan = p.id_pekerjaan 
                         AND a.id_karyawan = p.id_karyawan 
                         AND a.jam_pulang IS NOT NULL 
                         AND a.status_verifikasi = 'disetujui'
                   ) AS total_jam_pekerja
            FROM penugasan p
            JOIN karyawan k ON p.id_karyawan = k.id_karyawan
            WHERE p.id_pekerjaan = ?
            ORDER BY k.nama ASC
        ");
        $stmt->execute([$id_pekerjaan]);
        $pekerjaList = $stmt->fetchAll();

        // Hitung persentase kontribusi untuk masing-masing pekerja
        foreach ($pekerjaList as &$row) {
            $jam = floatval($row['total_jam_pekerja']);
            $row['persentase_kontribusi'] = ($totalProyek > 0) ? round(($jam / $totalProyek) * 100, 1) : 0;
        }

        jsonResponse(true, "Daftar pekerja pada pekerjaan berhasil dimuat", [
            "total_jam_proyek" => round($totalProyek, 2),
            "pekerja" => $pekerjaList
        ]);
    } elseif ($id_karyawan > 0) {
        // Ambil daftar pekerjaan yang ditugaskan untuk pekerja tertentu
        $stmt = $pdo->prepare("
            SELECT p.id_penugasan, p.peran_tugas,
                   pk.id_pekerjaan, pk.nama_pekerjaan, pk.deskripsi, pk.lokasi, pk.titik_lokasi, pk.status,
                   pk.tanggal_mulai, pk.target_selesai
            FROM penugasan p
            JOIN pekerjaan pk ON p.id_pekerjaan = pk.id_pekerjaan
            WHERE p.id_karyawan = ?
            ORDER BY pk.id_pekerjaan DESC
        ");
        $stmt->execute([$id_karyawan]);
        $data = $stmt->fetchAll();
        jsonResponse(true, "Daftar pekerjaan pekerja berhasil dimuat", $data);
    } else {
        // Ambil semua data penugasan
        $stmt = $pdo->query("
            SELECT p.*, k.nama AS nama_pekerja, pk.nama_pekerjaan
            FROM penugasan p
            JOIN karyawan k ON p.id_karyawan = k.id_karyawan
            JOIN pekerjaan pk ON p.id_pekerjaan = pk.id_pekerjaan
            ORDER BY p.id_penugasan DESC
        ");
        $data = $stmt->fetchAll();
        jsonResponse(true, "Semua penugasan berhasil dimuat", $data);
    }

} elseif ($method === 'POST') {
    $id_pekerjaan = intval($body['id_pekerjaan'] ?? 0);
    $id_karyawan = intval($body['id_karyawan'] ?? 0);
    $peran_tugas = trim($body['peran_tugas'] ?? 'Anggota Tim');

    if ($id_pekerjaan <= 0) {
        jsonResponse(false, "Pekerjaan belum ditentukan", null, 400);
    }

    // Alur Alternatif A1: Pekerja belum terdaftar dalam sistem
    $stmtKaryawan = $pdo->prepare("SELECT id_karyawan, nama FROM karyawan WHERE id_karyawan = ?");
    $stmtKaryawan->execute([$id_karyawan]);
    $karyawan = $stmtKaryawan->fetch();
    if (!$karyawan) {
        jsonResponse(false, "Pekerja tidak ditemukan", null, 404);
    }

    // Alur Alternatif A2: Pekerja sudah ditambahkan pada pekerjaan
    $stmtCek = $pdo->prepare("SELECT id_penugasan FROM penugasan WHERE id_pekerjaan = ? AND id_karyawan = ?");
    $stmtCek->execute([$id_pekerjaan, $id_karyawan]);
    if ($stmtCek->fetch()) {
        jsonResponse(false, "Pekerja sudah ada dalam pekerjaan ini", null, 409);
    }

    $stmtInsert = $pdo->prepare("
        INSERT INTO penugasan (id_pekerjaan, id_karyawan, peran_tugas)
        VALUES (?, ?, ?)
    ");
    $stmtInsert->execute([$id_pekerjaan, $id_karyawan, $peran_tugas]);

    jsonResponse(true, "Pekerja berhasil ditugaskan ke pekerjaan ini", [
        "id_penugasan" => $pdo->lastInsertId()
    ], 201);

} elseif ($method === 'DELETE') {
    $id_penugasan = intval($_GET['id_penugasan'] ?? $body['id_penugasan'] ?? 0);
    if ($id_penugasan <= 0) {
        jsonResponse(false, "ID Penugasan tidak valid", null, 400);
    }

    $stmt = $pdo->prepare("DELETE FROM penugasan WHERE id_penugasan = ?");
    $stmt->execute([$id_penugasan]);
    jsonResponse(true, "Penugasan pekerja berhasil dihapus");
} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}

