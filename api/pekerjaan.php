<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();

if ($method === 'GET') {
    $id = isset($_GET['id_pekerjaan']) ? intval($_GET['id_pekerjaan']) : 0;
    $id_karyawan = isset($_GET['id_karyawan']) ? intval($_GET['id_karyawan']) : 0;

    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT p.*,
                (SELECT COUNT(*) FROM penugasan WHERE id_pekerjaan = p.id_pekerjaan) AS total_pekerja,
                (SELECT IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(jam_pulang, jam_masuk))/3600), 0) 
                 FROM absensi WHERE id_pekerjaan = p.id_pekerjaan AND jam_pulang IS NOT NULL AND status_verifikasi = 'disetujui') AS total_jam_kerja,
                (SELECT IFNULL(SUM(nominal), 0) FROM pengeluaran WHERE id_pekerjaan = p.id_pekerjaan AND status_verifikasi = 'disetujui') AS total_pengeluaran
            FROM pekerjaan p
            WHERE p.id_pekerjaan = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        if (!$data) {
            jsonResponse(false, "Pekerjaan tidak ditemukan", null, 404);
        }
        jsonResponse(true, "Detail pekerjaan berhasil dimuat", $data);
    } elseif ($id_karyawan > 0) {
        // Hanya ambil pekerjaan yang ditugaskan kepada pekerja/karyawan ini
        $stmt = $pdo->prepare("
            SELECT p.*,
                pn.peran_tugas,
                (SELECT COUNT(*) FROM penugasan WHERE id_pekerjaan = p.id_pekerjaan) AS total_pekerja,
                (SELECT IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(jam_pulang, jam_masuk))/3600), 0) 
                 FROM absensi WHERE id_pekerjaan = p.id_pekerjaan AND jam_pulang IS NOT NULL AND status_verifikasi = 'disetujui') AS total_jam_kerja,
                (SELECT IFNULL(SUM(nominal), 0) FROM pengeluaran WHERE id_pekerjaan = p.id_pekerjaan AND status_verifikasi = 'disetujui') AS total_pengeluaran
            FROM pekerjaan p
            JOIN penugasan pn ON p.id_pekerjaan = pn.id_pekerjaan
            WHERE pn.id_karyawan = ?
            ORDER BY p.id_pekerjaan DESC
        ");
        $stmt->execute([$id_karyawan]);
        $data = $stmt->fetchAll();
        jsonResponse(true, "Daftar pekerjaan ditugaskan berhasil dimuat", $data);
    } else {
        $stmt = $pdo->query("
            SELECT p.*,
                (SELECT COUNT(*) FROM penugasan WHERE id_pekerjaan = p.id_pekerjaan) AS total_pekerja,
                (SELECT IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(jam_pulang, jam_masuk))/3600), 0) 
                 FROM absensi WHERE id_pekerjaan = p.id_pekerjaan AND jam_pulang IS NOT NULL AND status_verifikasi = 'disetujui') AS total_jam_kerja,
                (SELECT IFNULL(SUM(nominal), 0) FROM pengeluaran WHERE id_pekerjaan = p.id_pekerjaan AND status_verifikasi = 'disetujui') AS total_pengeluaran
            FROM pekerjaan p
            ORDER BY p.id_pekerjaan DESC
        ");
        $data = $stmt->fetchAll();
        jsonResponse(true, "Daftar pekerjaan berhasil dimuat", $data);
    }

} elseif ($method === 'POST') {
    $nama = trim($body['nama_pekerjaan'] ?? '');
    $deskripsi = trim($body['deskripsi'] ?? '');
    $lokasi = trim($body['lokasi'] ?? '');
    $titik_lokasi = trim($body['titik_lokasi'] ?? '');
    $tanggal_mulai = !empty($body['tanggal_mulai']) ? $body['tanggal_mulai'] : null;
    $target_selesai = !empty($body['target_selesai']) ? $body['target_selesai'] : null;
    $status = $body['status'] ?? 'belum_mulai';

    if (empty($nama)) {
        jsonResponse(false, "Nama pekerjaan wajib diisi", null, 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO pekerjaan (nama_pekerjaan, deskripsi, lokasi, titik_lokasi, tanggal_mulai, target_selesai, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$nama, $deskripsi, $lokasi, $titik_lokasi, $tanggal_mulai, $target_selesai, $status]);
    $id = $pdo->lastInsertId();

    jsonResponse(true, "Pekerjaan berhasil dibuat", ["id_pekerjaan" => $id], 201);

} elseif ($method === 'PUT') {
    $id = intval($body['id_pekerjaan'] ?? 0);
    $nama = trim($body['nama_pekerjaan'] ?? '');
    $deskripsi = trim($body['deskripsi'] ?? '');
    $lokasi = trim($body['lokasi'] ?? '');
    $titik_lokasi = trim($body['titik_lokasi'] ?? '');
    $tanggal_mulai = !empty($body['tanggal_mulai']) ? $body['tanggal_mulai'] : null;
    $target_selesai = !empty($body['target_selesai']) ? $body['target_selesai'] : null;
    $status = $body['status'] ?? 'belum_mulai';

    if ($id <= 0 || empty($nama)) {
        jsonResponse(false, "ID Pekerjaan dan Nama wajib valid", null, 400);
    }

    $stmt = $pdo->prepare("
        UPDATE pekerjaan 
        SET nama_pekerjaan = ?, deskripsi = ?, lokasi = ?, titik_lokasi = ?, tanggal_mulai = ?, target_selesai = ?, status = ?
        WHERE id_pekerjaan = ?
    ");
    $stmt->execute([$nama, $deskripsi, $lokasi, $titik_lokasi, $tanggal_mulai, $target_selesai, $status, $id]);

    jsonResponse(true, "Pekerjaan berhasil diperbarui");

} elseif ($method === 'DELETE') {
    $id = intval($_GET['id_pekerjaan'] ?? $body['id_pekerjaan'] ?? 0);
    if ($id <= 0) {
        jsonResponse(false, "ID Pekerjaan tidak valid", null, 400);
    }

    $stmt = $pdo->prepare("DELETE FROM pekerjaan WHERE id_pekerjaan = ?");
    $stmt->execute([$id]);
    jsonResponse(true, "Pekerjaan berhasil dihapus");
} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}

