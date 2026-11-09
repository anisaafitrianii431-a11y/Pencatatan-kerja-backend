<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();

if ($method === 'GET') {
    $id_pekerjaan = isset($_GET['id_pekerjaan']) ? intval($_GET['id_pekerjaan']) : 0;
    $id_karyawan = isset($_GET['id_karyawan']) ? intval($_GET['id_karyawan']) : 0;
    $tanggal = $_GET['tanggal'] ?? '';

    $where = [];
    $params = [];

    if ($id_pekerjaan > 0) {
        $where[] = "a.id_pekerjaan = ?";
        $params[] = $id_pekerjaan;
    }
    if ($id_karyawan > 0) {
        $where[] = "a.id_karyawan = ?";
        $params[] = $id_karyawan;
    }
    if (!empty($tanggal)) {
        $where[] = "a.tanggal = ?";
        $params[] = $tanggal;
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "
        SELECT a.*,
               k.nama AS nama_karyawan, k.jabatan, k.tarif_per_jam,
               p.nama_pekerjaan, p.lokasi AS lokasi_pekerjaan, p.titik_lokasi AS titik_pekerjaan,
               CASE 
                   WHEN a.jam_pulang IS NOT NULL AND a.jam_pulang >= a.jam_masuk 
                   THEN ROUND(TIME_TO_SEC(TIMEDIFF(a.jam_pulang, a.jam_masuk)) / 3600, 2)
                   ELSE 0 
               END AS durasi_jam
        FROM absensi a
        JOIN karyawan k ON a.id_karyawan = k.id_karyawan
        JOIN pekerjaan p ON a.id_pekerjaan = p.id_pekerjaan
        $whereClause
        ORDER BY a.tanggal DESC, a.jam_masuk DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse(true, "Data absensi berhasil dimuat", $data);

} elseif ($method === 'POST') {
    $action = $_GET['action'] ?? $body['action'] ?? '';

    // 1. ABSEN MASUK
    if ($action === 'masuk') {
        $id_pekerjaan = intval($body['id_pekerjaan'] ?? 0);
        $id_karyawan = intval($body['id_karyawan'] ?? 0);
        $tanggal = !empty($body['tanggal']) ? $body['tanggal'] : date('Y-m-d');
        $jam_masuk = !empty($body['jam_masuk']) ? $body['jam_masuk'] : date('H:i:s');
        $catatan = trim($body['catatan'] ?? '');
        $titik_lokasi = trim($body['titik_lokasi'] ?? '');
        $foto_lokasi = trim($body['foto_lokasi'] ?? '');

        if ($id_pekerjaan <= 0 || $id_karyawan <= 0) {
            jsonResponse(false, "Pekerjaan dan Karyawan wajib ditentukan", null, 400);
        }

        // Cek apakah karyawan sudah ditugaskan pada pekerjaan ini
        $cekTugas = $pdo->prepare("SELECT id_penugasan FROM penugasan WHERE id_pekerjaan = ? AND id_karyawan = ?");
        $cekTugas->execute([$id_pekerjaan, $id_karyawan]);
        if (!$cekTugas->fetch()) {
            // Jika belum ditugaskan, bisa auto-assign atau beritahu user
            // Secara fleksibel kita masukkan ke penugasan
            $autoAssign = $pdo->prepare("INSERT INTO penugasan (id_pekerjaan, id_karyawan, peran_tugas) VALUES (?, ?, 'Pelaksana Lapangan')");
            $autoAssign->execute([$id_pekerjaan, $id_karyawan]);
        }

        // Cek apakah ada sesi absensi yang belum absen pulang pada pekerjaan yang sama hari ini
        $cekAktif = $pdo->prepare("
            SELECT id_absensi FROM absensi 
            WHERE id_pekerjaan = ? AND id_karyawan = ? AND tanggal = ? AND jam_pulang IS NULL
        ");
        $cekAktif->execute([$id_pekerjaan, $id_karyawan, $tanggal]);
        if ($cekAktif->fetch()) {
            jsonResponse(false, "Anda masih memiliki sesi kerja aktif yang belum absen pulang hari ini.", null, 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO absensi (id_pekerjaan, id_karyawan, tanggal, jam_masuk, status_verifikasi, catatan, titik_lokasi, foto_lokasi)
            VALUES (?, ?, ?, ?, 'menunggu', ?, ?, ?)
        ");
        $stmt->execute([$id_pekerjaan, $id_karyawan, $tanggal, $jam_masuk, $catatan, $titik_lokasi, $foto_lokasi]);

        jsonResponse(true, "Absen masuk berhasil dicatat!", [
            "id_absensi" => $pdo->lastInsertId(),
            "jam_masuk" => $jam_masuk
        ], 201);

    // 2. ABSEN PULANG
    } elseif ($action === 'pulang') {
        $id_absensi = intval($body['id_absensi'] ?? 0);
        $jam_pulang = trim($body['jam_pulang'] ?? '');

        // Alur Alternatif A1: Jam pulang belum diisi
        if (empty($jam_pulang)) {
            jsonResponse(false, "Jam pulang harus diisi", null, 400);
        }

        $stmtCek = $pdo->prepare("SELECT * FROM absensi WHERE id_absensi = ?");
        $stmtCek->execute([$id_absensi]);
        $row = $stmtCek->fetch();

        if (!$row) {
            jsonResponse(false, "Catatan absensi tidak ditemukan", null, 404);
        }

        $jamMasuk = strtotime($row['jam_masuk']);
        $jamPulangTime = strtotime($jam_pulang);
        $isOvernight = !empty($body['is_overnight']);

        // Alur Alternatif A2: Jam pulang lebih kecil dari jam masuk
        if ($jamPulangTime <= $jamMasuk) {
            if ($isOvernight) {
                // Jika shift malam (melewati jam 00:00 tengah malam / +1 hari)
                $durasiDetik = ($jamPulangTime + 86400) - $jamMasuk;
            } else {
                jsonResponse(false, "Jam pulang tidak valid. Jam pulang ($jam_pulang) lebih kecil dari jam masuk (" . substr($row['jam_masuk'], 0, 5) . "). Centang 'Shift Malam' jika bekerja melewati tengah malam.", null, 400);
            }
        } else {
            $durasiDetik = $jamPulangTime - $jamMasuk;
        }

        // Hitung durasi jam kerja otomatis
        $durasiJam = round($durasiDetik / 3600, 2);

        $stmtUpdate = $pdo->prepare("
            UPDATE absensi 
            SET jam_pulang = ? 
            WHERE id_absensi = ?
        ");
        $stmtUpdate->execute([$jam_pulang, $id_absensi]);

        jsonResponse(true, "Absen pulang berhasil dicatat! Durasi kerja: {$durasiJam} jam.", [
            "id_absensi" => $id_absensi,
            "jam_pulang" => $jam_pulang,
            "durasi_jam" => $durasiJam
        ]);

    // 3. VERIFIKASI ABSENSI (ADMIN)
    } elseif ($action === 'verifikasi') {
        $id_absensi = intval($body['id_absensi'] ?? 0);
        $status = $body['status_verifikasi'] ?? '';

        if (!in_array($status, ['disetujui', 'ditolak', 'menunggu'])) {
            jsonResponse(false, "Status verifikasi tidak valid", null, 400);
        }

        $stmt = $pdo->prepare("UPDATE absensi SET status_verifikasi = ? WHERE id_absensi = ?");
        $stmt->execute([$status, $id_absensi]);

        jsonResponse(true, "Status absensi berhasil diubah menjadi: {$status}");

    // 4. MANUAL INPUT / EDIT BY ADMIN
    } else {
        $id_pekerjaan = intval($body['id_pekerjaan'] ?? 0);
        $id_karyawan = intval($body['id_karyawan'] ?? 0);
        $tanggal = $body['tanggal'] ?? date('Y-m-d');
        $jam_masuk = $body['jam_masuk'] ?? '';
        $jam_pulang = $body['jam_pulang'] ?? null;
        $catatan = $body['catatan'] ?? '';
        $status = $body['status_verifikasi'] ?? 'menunggu';

        if ($id_pekerjaan <= 0 || $id_karyawan <= 0 || empty($jam_masuk)) {
            jsonResponse(false, "Data absensi belum lengkap", null, 400);
        }

        if (!empty($jam_pulang) && strtotime($jam_pulang) <= strtotime($jam_masuk)) {
            jsonResponse(false, "Jam pulang tidak valid", null, 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO absensi (id_pekerjaan, id_karyawan, tanggal, jam_masuk, jam_pulang, status_verifikasi, catatan)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id_pekerjaan, $id_karyawan, $tanggal, $jam_masuk, $jam_pulang, $status, $catatan]);

        jsonResponse(true, "Data absensi berhasil disimpan", ["id_absensi" => $pdo->lastInsertId()], 201);
    }

} elseif ($method === 'DELETE') {
    $id = intval($_GET['id_absensi'] ?? $body['id_absensi'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM absensi WHERE id_absensi = ?");
    $stmt->execute([$id]);
    jsonResponse(true, "Data absensi berhasil dihapus");
} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}

