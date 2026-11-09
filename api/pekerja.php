<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();

if ($method === 'GET') {
    // Ambil daftar pekerja beserta akun loginnya
    $stmt = $pdo->query("
        SELECT k.id_karyawan, k.nama, k.jabatan, k.tarif_per_jam, k.created_at,
               u.id_user, u.email, u.peran, u.aktif,
               (SELECT COUNT(*) FROM penugasan p WHERE p.id_karyawan = k.id_karyawan) AS total_pekerjaan_aktif
        FROM karyawan k
        LEFT JOIN `user` u ON k.id_karyawan = u.id_karyawan
        ORDER BY k.id_karyawan DESC
    ");
    $data = $stmt->fetchAll();
    jsonResponse(true, "Daftar pekerja berhasil dimuat", $data);

} elseif ($method === 'POST') {
    $nama = trim($body['nama'] ?? '');
    $jabatan = trim($body['jabatan'] ?? '');
    $tarif = floatval($body['tarif_per_jam'] ?? 0);
    $email = trim($body['email'] ?? '');
    $password = trim($body['kata_sandi'] ?? 'karyawan123');
    $peran = $body['peran'] ?? 'karyawan';

    if (empty($nama)) {
        jsonResponse(false, "Nama pekerja wajib diisi", null, 400);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO karyawan (nama, jabatan, tarif_per_jam) VALUES (?, ?, ?)");
        $stmt->execute([$nama, $jabatan, $tarif]);
        $id_karyawan = $pdo->lastInsertId();

        // Buat akun user jika email diisi
        if (!empty($email)) {
            // Cek apakah email sudah terdaftar
            $cek = $pdo->prepare("SELECT id_user FROM `user` WHERE email = ?");
            $cek->execute([$email]);
            if ($cek->fetch()) {
                $pdo->rollBack();
                jsonResponse(false, "Email sudah terdaftar untuk pengguna lain", null, 409);
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUser = $pdo->prepare("INSERT INTO `user` (id_karyawan, email, kata_sandi, peran, aktif) VALUES (?, ?, ?, ?, 1)");
            $stmtUser->execute([$id_karyawan, $email, $hash, $peran]);
        }

        $pdo->commit();
        jsonResponse(true, "Data pekerja berhasil ditambahkan", ["id_karyawan" => $id_karyawan], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(false, "Gagal menyimpan data pekerja: " . $e->getMessage(), null, 500);
    }

} elseif ($method === 'PUT') {
    $id_karyawan = intval($body['id_karyawan'] ?? 0);
    $nama = trim($body['nama'] ?? '');
    $jabatan = trim($body['jabatan'] ?? '');
    $tarif = floatval($body['tarif_per_jam'] ?? 0);
    $email = trim($body['email'] ?? '');
    $aktif = isset($body['aktif']) ? ($body['aktif'] ? 1 : 0) : 1;

    if ($id_karyawan <= 0 || empty($nama)) {
        jsonResponse(false, "ID Karyawan dan Nama wajib valid", null, 400);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE karyawan SET nama = ?, jabatan = ?, tarif_per_jam = ? WHERE id_karyawan = ?");
        $stmt->execute([$nama, $jabatan, $tarif, $id_karyawan]);

        if (!empty($email)) {
            // Periksa apakah user sudah ada
            $cek = $pdo->prepare("SELECT id_user FROM `user` WHERE id_karyawan = ?");
            $cek->execute([$id_karyawan]);
            $existing = $cek->fetch();

            if ($existing) {
                $stmtUser = $pdo->prepare("UPDATE `user` SET email = ?, aktif = ? WHERE id_karyawan = ?");
                $stmtUser->execute([$email, $aktif, $id_karyawan]);
            } else {
                $hash = password_hash('karyawan123', PASSWORD_BCRYPT);
                $stmtUser = $pdo->prepare("INSERT INTO `user` (id_karyawan, email, kata_sandi, peran, aktif) VALUES (?, ?, ?, 'karyawan', ?)");
                $stmtUser->execute([$id_karyawan, $email, $hash, $aktif]);
            }
        }

        $pdo->commit();
        jsonResponse(true, "Data pekerja berhasil diperbarui");
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(false, "Gagal memperbarui data: " . $e->getMessage(), null, 500);
    }

} elseif ($method === 'DELETE') {
    $id_karyawan = intval($_GET['id_karyawan'] ?? $body['id_karyawan'] ?? 0);
    if ($id_karyawan <= 0) {
        jsonResponse(false, "ID Karyawan tidak valid", null, 400);
    }

    $stmt = $pdo->prepare("DELETE FROM karyawan WHERE id_karyawan = ?");
    $stmt->execute([$id_karyawan]);
    jsonResponse(true, "Data pekerja berhasil dihapus");
} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}

