<?php
// ============================================================
// ENDPOINT ABSENSI (Jam Kerja / Presensi)
// ============================================================
require_once __DIR__ . '/config/database.php';
$currentUser = requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = ($currentUser['peran'] === 'admin');
$kId = intval($currentUser['id_karyawan']);

// Formula SQL Durasi Kerja (Mendukung Shift Malam)
$sqlDurasi = "
    CASE 
        WHEN a.jam_pulang IS NOT NULL AND a.jam_pulang >= a.jam_masuk 
            THEN (TIME_TO_SEC(TIMEDIFF(a.jam_pulang, a.jam_masuk)) / 3600)
        WHEN a.jam_pulang IS NOT NULL AND a.jam_pulang < a.jam_masuk 
            THEN ((TIME_TO_SEC(TIMEDIFF(a.jam_pulang, a.jam_masuk)) + 86400) / 3600)
        ELSE 0 
    END
";

if ($method === 'GET') {
    $where = [];
    $params = [];

    // Karyawan hanya bisa lihat data sendiri
    if (!$isAdmin) {
        $where[] = "a.id_karyawan = ?";
        $params[] = $kId;
    } elseif (!empty($_GET['id_karyawan'])) {
        $where[] = "a.id_karyawan = ?";
        $params[] = intval($_GET['id_karyawan']);
    }

    // Filter periode (YYYY-MM)
    if (!empty($_GET['periode'])) {
        $where[] = "DATE_FORMAT(a.tanggal, '%Y-%m') = ?";
        $params[] = $_GET['periode'];
    }

    // Filter status verifikasi
    if (!empty($_GET['status'])) {
        $where[] = "a.status_verifikasi = ?";
        $params[] = $_GET['status'];
    }

    // Filter id_pekerjaan
    if (!empty($_GET['id_pekerjaan'])) {
        $where[] = "a.id_pekerjaan = ?";
        $params[] = intval($_GET['id_pekerjaan']);
    }

    $whereClause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "
        SELECT a.id_absensi, a.id_karyawan, a.id_pekerjaan, a.tanggal, 
               a.jam_masuk, a.jam_pulang, a.status_verifikasi, a.catatan AS keterangan, a.catatan,
               ($sqlDurasi) AS durasi_jam,
               k.nama AS nama_karyawan, k.jabatan,
               p.nama_pekerjaan
        FROM absensi a
        JOIN karyawan k ON a.id_karyawan = k.id_karyawan
        LEFT JOIN pekerjaan p ON a.id_pekerjaan = p.id_pekerjaan
        $whereClause
        ORDER BY a.tanggal DESC, a.jam_masuk DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    // Bulatkan durasi_jam
    foreach ($data as &$row) {
        $row['durasi_jam'] = round(floatval($row['durasi_jam']), 2);
    }

    jsonResponse(true, "Data absensi berhasil dimuat", $data);

} elseif ($method === 'POST') {
    $body = getRequestBody();
    $action = $_GET['action'] ?? $body['action'] ?? 'create';

    if ($action === 'create') {
        // Catat absensi baru (karyawan)
        $id_pekerjaan = intval($body['id_pekerjaan'] ?? 0);
        $tanggal = $body['tanggal'] ?? date('Y-m-d');
        $jam_masuk = $body['jam_masuk'] ?? date('H:i:s');
        $keterangan = trim($body['keterangan'] ?? '');

        $stmt = $pdo->prepare("
            INSERT INTO absensi (id_karyawan, id_pekerjaan, tanggal, jam_masuk, catatan, status_verifikasi)
            VALUES (?, ?, ?, ?, ?, 'menunggu')
        ");
        $idPek = $id_pekerjaan > 0 ? $id_pekerjaan : null;
        $stmt->execute([$kId, $idPek, $tanggal, $jam_masuk, $keterangan]);

        jsonResponse(true, "Absensi masuk berhasil dicatat", ['id_absensi' => $pdo->lastInsertId()]);

    } elseif ($action === 'pulang') {
        // Catat jam pulang
        $id_absensi = intval($body['id_absensi'] ?? 0);
        $jam_pulang = $body['jam_pulang'] ?? date('H:i:s');

        $stmt = $pdo->prepare("UPDATE absensi SET jam_pulang = ? WHERE id_absensi = ? AND id_karyawan = ?");
        $stmt->execute([$jam_pulang, $id_absensi, $kId]);

        jsonResponse(true, "Jam pulang berhasil dicatat");

    } elseif ($action === 'verifikasi' && $isAdmin) {
        // Admin: Setujui atau tolak absensi
        $id_absensi = intval($body['id_absensi'] ?? 0);
        $status = $body['status'] ?? ''; // 'disetujui' atau 'ditolak'

        if (!in_array($status, ['disetujui', 'ditolak'])) {
            jsonResponse(false, "Status verifikasi tidak valid", null, 400);
        }

        $stmt = $pdo->prepare("UPDATE absensi SET status_verifikasi = ? WHERE id_absensi = ?");
        $stmt->execute([$status, $id_absensi]);

        jsonResponse(true, "Absensi berhasil di-{$status}");

    } else {
        jsonResponse(false, "Aksi tidak dikenal", null, 400);
    }

} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}

