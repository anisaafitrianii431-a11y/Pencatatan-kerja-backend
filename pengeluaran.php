<?php
// ============================================================
// ENDPOINT PENGELUARAN (Belanja Material & Reimbursement)
// ============================================================
require_once __DIR__ . '/config/database.php';
$currentUser = requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = ($currentUser['peran'] === 'admin');
$kId = intval($currentUser['id_karyawan']);

if ($method === 'GET') {
    $where = [];
    $params = [];

    // Karyawan hanya bisa lihat data sendiri
    if (!$isAdmin) {
        $where[] = "pe.id_karyawan = ?";
        $params[] = $kId;
    } elseif (!empty($_GET['id_karyawan'])) {
        $where[] = "pe.id_karyawan = ?";
        $params[] = intval($_GET['id_karyawan']);
    }

    // Filter periode (YYYY-MM)
    if (!empty($_GET['periode'])) {
        $where[] = "DATE_FORMAT(pe.tanggal, '%Y-%m') = ?";
        $params[] = $_GET['periode'];
    }

    // Filter jenis dana
    if (!empty($_GET['jenis_dana'])) {
        $where[] = "pe.jenis_dana = ?";
        $params[] = $_GET['jenis_dana'];
    }

    // Filter status verifikasi
    if (!empty($_GET['status_verifikasi'])) {
        $where[] = "pe.status_verifikasi = ?";
        $params[] = $_GET['status_verifikasi'];
    }

    // Filter status ganti
    if (!empty($_GET['status_ganti'])) {
        $where[] = "pe.status_ganti = ?";
        $params[] = $_GET['status_ganti'];
    }

    $whereClause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "
        SELECT pe.*, 
               k.nama AS nama_karyawan, k.jabatan,
               p.nama_pekerjaan
        FROM pengeluaran pe
        JOIN karyawan k ON pe.id_karyawan = k.id_karyawan
        LEFT JOIN pekerjaan p ON pe.id_pekerjaan = p.id_pekerjaan
        $whereClause
        ORDER BY pe.tanggal DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse(true, "Data pengeluaran berhasil dimuat", $data);

} elseif ($method === 'POST') {
    $body = getRequestBody();
    $action = $_GET['action'] ?? $body['action'] ?? 'create';

    if ($action === 'create') {
        // Catat pengeluaran baru (karyawan)
        $id_pekerjaan = intval($body['id_pekerjaan'] ?? 0);
        $tanggal = $body['tanggal'] ?? date('Y-m-d');
        $nominal = floatval($body['nominal'] ?? 0);
        $jenis_dana = ($body['jenis_dana'] ?? 'pribadi') === 'kas' ? 'kantor' : ($body['jenis_dana'] ?? 'pribadi');
        $keterangan = trim($body['keterangan'] ?? '');

        if ($nominal <= 0) {
            jsonResponse(false, "Nominal pengeluaran harus lebih dari 0", null, 400);
        }

        // Proses unggah foto kwitansi/nota fisik (Base64 atau File upload)
        $foto_kwitansi = null;
        $foto_raw = $body['foto_kwitansi'] ?? $body['foto'] ?? '';

        if (!empty($foto_raw) && strpos($foto_raw, 'data:image') === 0) {
            if (preg_match('/^data:image\/(\w+);base64,/', $foto_raw, $type)) {
                $data = substr($foto_raw, strpos($foto_raw, ',') + 1);
                $ext = strtolower($type[1]);
                if ($ext === 'jpeg') $ext = 'jpg';
                if (in_array($ext, ['jpg', 'png', 'webp'])) {
                    $decoded = base64_decode($data);
                    if ($decoded !== false) {
                        $filename = 'nota_' . $kId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $uploadDir = __DIR__ . '/uploads/nota/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }
                        if (file_put_contents($uploadDir . $filename, $decoded)) {
                            $foto_kwitansi = 'uploads/nota/' . $filename;
                        }
                    }
                }
            }
        } elseif (!empty($_FILES['foto'])) {
            $file = $_FILES['foto'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext === 'jpeg') $ext = 'jpg';
                if (in_array($ext, ['jpg', 'png', 'webp'])) {
                    $filename = 'nota_' . $kId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $uploadDir = __DIR__ . '/uploads/nota/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                        $foto_kwitansi = 'uploads/nota/' . $filename;
                    }
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO pengeluaran (id_karyawan, id_pekerjaan, tanggal, nominal, jenis_dana, keterangan, foto_kwitansi, status_verifikasi, status_ganti)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'menunggu', 'belum')
        ");
        $stmt->execute([$kId, $id_pekerjaan ?: null, $tanggal, $nominal, $jenis_dana, $keterangan, $foto_kwitansi]);

        jsonResponse(true, "Pengeluaran dan nota belanja berhasil dicatat", [
            'id_pengeluaran' => $pdo->lastInsertId(),
            'foto_kwitansi'  => $foto_kwitansi
        ]);

    } elseif ($action === 'verifikasi' && $isAdmin) {
        // Admin: Setujui atau tolak pengeluaran
        $id_pengeluaran = intval($body['id_pengeluaran'] ?? 0);
        $status = $body['status'] ?? '';

        if (!in_array($status, ['disetujui', 'ditolak'])) {
            jsonResponse(false, "Status verifikasi tidak valid", null, 400);
        }

        $stmt = $pdo->prepare("UPDATE pengeluaran SET status_verifikasi = ? WHERE id_pengeluaran = ?");
        $stmt->execute([$status, $id_pengeluaran]);

        jsonResponse(true, "Pengeluaran berhasil di-{$status}");

    } else {
        jsonResponse(false, "Aksi tidak dikenal", null, 400);
    }

} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}

