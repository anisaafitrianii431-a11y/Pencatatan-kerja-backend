<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();

// Tangani upload file bukti/faktur jika menggunakan multipart/form-data
if (isset($_FILES['foto_kwitansi']) && $_FILES['foto_kwitansi']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $ext = pathinfo($_FILES['foto_kwitansi']['name'], PATHINFO_EXTENSION);
    $filename = 'kwitansi_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    $targetPath = $uploadDir . $filename;
    if (move_uploaded_file($_FILES['foto_kwitansi']['tmp_name'], $targetPath)) {
        $_POST['foto_kwitansi'] = 'uploads/' . $filename;
    }
}

// Gabungkan body JSON dengan $_POST jika ada
if (!empty($_POST)) {
    $body = array_merge($body, $_POST);
}

$action = $_GET['action'] ?? $body['action'] ?? '';

if ($method === 'GET') {
    // 1. REKAP PENGELUARAN (Tabel Per Pekerjaan: Uang Pribadi, Uang Kantor, Total)
    if ($action === 'rekap') {
        $sql = "
            SELECT p.id_pekerjaan, p.nama_pekerjaan,
                   IFNULL(SUM(CASE WHEN pg.jenis_dana = 'pribadi' AND pg.status_verifikasi = 'disetujui' THEN pg.nominal ELSE 0 END), 0) AS uang_pribadi,
                   IFNULL(SUM(CASE WHEN pg.jenis_dana = 'kantor' AND pg.status_verifikasi = 'disetujui' THEN pg.nominal ELSE 0 END), 0) AS uang_kantor,
                   IFNULL(SUM(CASE WHEN pg.status_verifikasi = 'disetujui' THEN pg.nominal ELSE 0 END), 0) AS total_pengeluaran,
                   IFNULL(SUM(CASE WHEN pg.jenis_dana = 'pribadi' AND pg.status_verifikasi = 'disetujui' AND pg.status_ganti = 'belum' THEN pg.nominal ELSE 0 END), 0) AS pribadi_belum_ganti
            FROM pekerjaan p
            LEFT JOIN pengeluaran pg ON p.id_pekerjaan = pg.id_pekerjaan
            GROUP BY p.id_pekerjaan, p.nama_pekerjaan
            ORDER BY p.id_pekerjaan ASC
        ";
        $stmt = $pdo->query($sql);
        $rekap = $stmt->fetchAll();

        // Hitung Grand Total
        $totalPribadi = 0;
        $totalKantor = 0;
        $grandTotal = 0;
        foreach ($rekap as $r) {
            $totalPribadi += floatval($r['uang_pribadi']);
            $totalKantor += floatval($r['uang_kantor']);
            $grandTotal += floatval($r['total_pengeluaran']);
        }

        jsonResponse(true, "Rekap pengeluaran berhasil dimuat", [
            "daftar" => $rekap,
            "summary" => [
                "total_uang_pribadi" => $totalPribadi,
                "total_uang_kantor" => $totalKantor,
                "grand_total" => $grandTotal
            ]
        ]);
    }

    // 2. DAFTAR PENGELUARAN BIASA (Dengan Filter)
    $id_pekerjaan = isset($_GET['id_pekerjaan']) ? intval($_GET['id_pekerjaan']) : 0;
    $id_karyawan = isset($_GET['id_karyawan']) ? intval($_GET['id_karyawan']) : 0;
    $jenis_dana = $_GET['jenis_dana'] ?? '';

    $where = [];
    $params = [];

    if ($id_pekerjaan > 0) {
        $where[] = "pg.id_pekerjaan = ?";
        $params[] = $id_pekerjaan;
    }
    if ($id_karyawan > 0) {
        $where[] = "pg.id_karyawan = ?";
        $params[] = $id_karyawan;
    }
    if (!empty($jenis_dana) && in_array($jenis_dana, ['pribadi', 'kantor'])) {
        $where[] = "pg.jenis_dana = ?";
        $params[] = $jenis_dana;
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "
        SELECT pg.*,
               k.nama AS nama_karyawan,
               p.nama_pekerjaan
        FROM pengeluaran pg
        JOIN karyawan k ON pg.id_karyawan = k.id_karyawan
        JOIN pekerjaan p ON pg.id_pekerjaan = p.id_pekerjaan
        $whereClause
        ORDER BY pg.tanggal DESC, pg.id_pengeluaran DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse(true, "Daftar pengeluaran berhasil dimuat", $data);

} elseif ($method === 'POST') {
    // 1. UPDATE STATUS VERIFIKASI (ADMIN)
    if ($action === 'verifikasi') {
        $id = intval($body['id_pengeluaran'] ?? 0);
        $status = $body['status_verifikasi'] ?? '';
        if (!in_array($status, ['disetujui', 'ditolak', 'menunggu'])) {
            jsonResponse(false, "Status verifikasi tidak valid", null, 400);
        }
        $stmt = $pdo->prepare("UPDATE pengeluaran SET status_verifikasi = ? WHERE id_pengeluaran = ?");
        $stmt->execute([$status, $id]);
        jsonResponse(true, "Status verifikasi pengeluaran diperbarui");

    // 2. UPDATE STATUS GANTI / REIMBURSEMENT (ADMIN)
    } elseif ($action === 'status_ganti') {
        $id = intval($body['id_pengeluaran'] ?? 0);
        $status = $body['status_ganti'] ?? '';
        if (!in_array($status, ['sudah', 'belum'])) {
            jsonResponse(false, "Status penggantian tidak valid", null, 400);
        }
        $stmt = $pdo->prepare("UPDATE pengeluaran SET status_ganti = ? WHERE id_pengeluaran = ?");
        $stmt->execute([$status, $id]);
        jsonResponse(true, "Status penggantian dana diperbarui");

    // 3. TAMBAH CATATAN PENGELUARAN (PEKERJA / ADMIN)
    } else {
        $id_pekerjaan = intval($body['id_pekerjaan'] ?? 0);
        $id_karyawan = intval($body['id_karyawan'] ?? 0);
        $tanggal = !empty($body['tanggal']) ? $body['tanggal'] : date('Y-m-d');
        $nominal = floatval($body['nominal'] ?? 0);
        $jenis_dana = $body['jenis_dana'] ?? 'pribadi';
        $keterangan = trim($body['keterangan'] ?? '');
        $foto_kwitansi = trim($body['foto_kwitansi'] ?? '');

        if ($id_pekerjaan <= 0 || $id_karyawan <= 0 || $nominal <= 0) {
            jsonResponse(false, "Pekerjaan, Karyawan, dan Nominal pengeluaran wajib valid", null, 400);
        }

        if (!in_array($jenis_dana, ['kantor', 'pribadi'])) {
            jsonResponse(false, "Jenis dana harus 'kantor' atau 'pribadi'", null, 400);
        }

        // Bawaan: status_verifikasi = 'menunggu', status_ganti = 'belum'
        $stmt = $pdo->prepare("
            INSERT INTO pengeluaran (id_pekerjaan, id_karyawan, tanggal, nominal, jenis_dana, keterangan, foto_kwitansi, status_verifikasi, status_ganti)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'menunggu', 'belum')
        ");
        $stmt->execute([$id_pekerjaan, $id_karyawan, $tanggal, $nominal, $jenis_dana, $keterangan, $foto_kwitansi]);

        jsonResponse(true, "Pengeluaran berhasil dicatat", [
            "id_pengeluaran" => $pdo->lastInsertId()
        ], 201);
    }

} elseif ($method === 'DELETE') {
    $id = intval($_GET['id_pengeluaran'] ?? $body['id_pengeluaran'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM pengeluaran WHERE id_pengeluaran = ?");
    $stmt->execute([$id]);
    jsonResponse(true, "Pengeluaran berhasil dihapus");
} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}

