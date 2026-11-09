<?php
// ==========================================================
// BACKEND KHUSUS ADMIN: PERHITUNGAN & PEMBAYARAN GAJI BULANAN
// ==========================================================
require_once __DIR__ . '/../config/database.php';

// Otorisasi: Wajib Admin
$currentUser = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();
$action = $_GET['action'] ?? $body['action'] ?? '';

// Formula SQL Durasi Kerja (Mendukung Shift Malam - F-02)
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
    $id_karyawan = isset($_GET['id_karyawan']) ? intval($_GET['id_karyawan']) : 0;

    // FITUR 1: RINCIAN / BREAKDOWN HARI KERJA & REIMBURSEMENT
    if ($action === 'detail' || isset($_GET['detail'])) {
        $id_gaji = intval($_GET['id_gaji'] ?? 0);
        if ($id_gaji > 0 && ($id_karyawan <= 0 || empty($periode))) {
            $stmtG = $pdo->prepare("SELECT id_karyawan, periode FROM gaji WHERE id_gaji = ?");
            $stmtG->execute([$id_gaji]);
            $gRow = $stmtG->fetch();
            if ($gRow) {
                $id_karyawan = intval($gRow['id_karyawan']);
                $periode = $gRow['periode'];
            }
        }

        if ($id_karyawan <= 0) {
            jsonResponse(false, "ID Karyawan wajib disertakan untuk melihat rincian", null, 400);
        }

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
        $stmtAbsensi->execute([$id_karyawan, $periode]);
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
        $stmtPengeluaran->execute([$id_karyawan, $periode]);
        $pengeluaranList = $stmtPengeluaran->fetchAll();

        jsonResponse(true, "Rincian presensi & reimbursement berhasil dimuat", [
            "id_karyawan" => $id_karyawan,
            "periode" => $periode,
            "absensi" => $absensiList,
            "pengeluaran" => $pengeluaranList
        ]);
    }

    $where = ["g.periode = ?"];
    $params = [$periode];

    if ($id_karyawan > 0) {
        $where[] = "g.id_karyawan = ?";
        $params[] = $id_karyawan;
    }

    $whereClause = "WHERE " . implode(" AND ", $where);

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
               ), 'Operasional Lapangan') AS nama_pekerjaan,
               (
                   SELECT GROUP_CONCAT(DISTINCT a.id_pekerjaan)
                   FROM absensi a
                   WHERE a.id_karyawan = g.id_karyawan
                     AND DATE_FORMAT(a.tanggal, '%Y-%m') = g.periode
                     AND a.status_verifikasi = 'disetujui'
               ) AS id_pekerjaan_list
        FROM gaji g
        JOIN karyawan k ON g.id_karyawan = k.id_karyawan
        $whereClause
        ORDER BY g.id_gaji DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse(true, "Data gaji periode $periode berhasil dimuat (Admin)", $data);

} elseif ($method === 'POST') {
    // 1. HITUNG GAJI OTOMATIS BULANAN OLEH ADMIN
    if ($action === 'hitung') {
        $periode = $body['periode'] ?? date('Y-m');

        $karyawanList = $pdo->query("SELECT id_karyawan, nama, tarif_per_jam FROM karyawan")->fetchAll();

        $dihitung = 0;
        $dilewati = 0;

        foreach ($karyawanList as $k) {
            $id_k = $k['id_karyawan'];
            $tarif = floatval($k['tarif_per_jam']);

            // F-03: Lindungi data gaji yang sudah berstatus 'sudah' dibayar agar tidak tertimpa
            $stmtCheck = $pdo->prepare("SELECT status_bayar, bonus, potongan, catatan_penyesuaian FROM gaji WHERE id_karyawan = ? AND periode = ?");
            $stmtCheck->execute([$id_k, $periode]);
            $existing = $stmtCheck->fetch();

            if ($existing && $existing['status_bayar'] === 'sudah') {
                $dilewati++;
                continue;
            }

            $bonus = $existing ? floatval($existing['bonus'] ?? 0) : 0.00;
            $potongan = $existing ? floatval($existing['potongan'] ?? 0) : 0.00;
            $catatanPenyesuaian = $existing ? ($existing['catatan_penyesuaian'] ?? null) : null;

            // Hitung jam disetujui periode ini (dukungan shift malam)
            $stmtJam = $pdo->prepare("
                SELECT IFNULL(SUM($sqlDurasi), 0) AS total_jam
                FROM absensi a
                WHERE a.id_karyawan = ? 
                  AND a.status_verifikasi = 'disetujui' 
                  AND a.jam_pulang IS NOT NULL
                  AND DATE_FORMAT(a.tanggal, '%Y-%m') = ?
            ");
            $stmtJam->execute([$id_k, $periode]);
            $totalJam = round(floatval($stmtJam->fetch()['total_jam']), 2);

            $totalUpah = round($totalJam * $tarif, 2);

            // F-05: Hitung talangan uang pribadi yang belum diganti
            $stmtGanti = $pdo->prepare("
                SELECT IFNULL(SUM(nominal), 0) AS total_ganti
                FROM pengeluaran
                WHERE id_karyawan = ? 
                  AND jenis_dana = 'pribadi' 
                  AND status_verifikasi = 'disetujui'
                  AND status_ganti = 'belum'
                  AND DATE_FORMAT(tanggal, '%Y-%m') = ?
            ");
            $stmtGanti->execute([$id_k, $periode]);
            $totalGanti = round(floatval($stmtGanti->fetch()['total_ganti']), 2);

            // Formula Total Dibayar = (Upah + Ganti Uang Pribadi + Bonus) - Potongan (Kasbon)
            $totalDibayar = max(0, round($totalUpah + $totalGanti + $bonus - $potongan, 2));

            $stmtSave = $pdo->prepare("
                INSERT INTO gaji (id_karyawan, periode, total_jam, tarif, total_upah, total_ganti, bonus, potongan, catatan_penyesuaian, total_dibayar, status_bayar)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'belum')
                ON DUPLICATE KEY UPDATE
                    total_jam = VALUES(total_jam),
                    tarif = VALUES(tarif),
                    total_upah = VALUES(total_upah),
                    total_ganti = VALUES(total_ganti),
                    total_dibayar = GREATEST(0, VALUES(total_upah) + VALUES(total_ganti) + bonus - potongan)
            ");
            $stmtSave->execute([$id_k, $periode, $totalJam, $tarif, $totalUpah, $totalGanti, $bonus, $potongan, $catatanPenyesuaian, $totalDibayar]);
            $dihitung++;
        }

        $pesan = "Perhitungan gaji periode $periode berhasil diproses untuk $dihitung pekerja.";
        if ($dilewati > 0) {
            $pesan .= " ($dilewati catatan dilewati karena sudah dibayar/final).";
        }

        jsonResponse(true, $pesan, [
            "dihitung" => $dihitung,
            "dilewati" => $dilewati
        ]);

    // 2. PENYESUAIAN GAJI: BONUS / INSENTIF & POTONGAN (KASBON)
    } elseif ($action === 'penyesuaian') {
        $id_gaji = intval($body['id_gaji'] ?? 0);
        $bonus = max(0, floatval($body['bonus'] ?? 0));
        $potongan = max(0, floatval($body['potongan'] ?? 0));
        $catatan = trim($body['catatan_penyesuaian'] ?? '');

        if ($id_gaji <= 0) {
            jsonResponse(false, "ID Gaji tidak valid", null, 400);
        }

        $stmtGet = $pdo->prepare("SELECT total_upah, total_ganti, status_bayar FROM gaji WHERE id_gaji = ?");
        $stmtGet->execute([$id_gaji]);
        $row = $stmtGet->fetch();

        if (!$row) {
            jsonResponse(false, "Data gaji tidak ditemukan", null, 404);
        }

        if ($row['status_bayar'] === 'sudah') {
            jsonResponse(false, "Gaji yang sudah berstatus lunas tidak dapat diubah penyesuaiannya.", null, 400);
        }

        $totalUpah = floatval($row['total_upah']);
        $totalGanti = floatval($row['total_ganti']);
        $newTotalDibayar = max(0, round($totalUpah + $totalGanti + $bonus - $potongan, 2));

        $stmtUpdate = $pdo->prepare("
            UPDATE gaji 
            SET bonus = ?,
                potongan = ?,
                catatan_penyesuaian = ?,
                total_dibayar = ?
            WHERE id_gaji = ?
        ");
        $stmtUpdate->execute([$bonus, $potongan, $catatan, $newTotalDibayar, $id_gaji]);

        jsonResponse(true, "Penyesuaian bonus & potongan kasbon berhasil disimpan!", [
            "id_gaji" => $id_gaji,
            "bonus" => $bonus,
            "potongan" => $potongan,
            "catatan_penyesuaian" => $catatan,
            "total_dibayar" => $newTotalDibayar
        ]);

    // 3. TANDAI GAJI SUDAH DIBAYAR (DENGAN METODE BAYAR, CATATAN & BUKTI TRANSFER)
    } elseif ($action === 'bayar') {
        $id_gaji = intval($body['id_gaji'] ?? 0);
        $metode = in_array($body['metode_bayar'] ?? '', ['transfer', 'tunai']) ? $body['metode_bayar'] : 'transfer';
        $catatanBayar = trim($body['catatan_bayar'] ?? '');
        $fotoBuktiRaw = $body['foto_bukti'] ?? $body['bukti_bayar'] ?? '';

        if ($id_gaji <= 0) {
            jsonResponse(false, "ID Gaji tidak valid", null, 400);
        }

        $stmtGet = $pdo->prepare("SELECT id_karyawan, periode, status_bayar FROM gaji WHERE id_gaji = ?");
        $stmtGet->execute([$id_gaji]);
        $g = $stmtGet->fetch();

        if (!$g) {
            jsonResponse(false, "Data gaji tidak ditemukan", null, 404);
        }

        if ($g['status_bayar'] === 'sudah') {
            jsonResponse(false, "Gaji ini sudah berstatus dibayar.", null, 400);
        }

        // Proses unggah foto bukti pembayaran / transfer (Base64)
        $buktiPath = null;
        if (!empty($fotoBuktiRaw) && strpos($fotoBuktiRaw, 'data:image') === 0) {
            if (preg_match('/^data:image\/(\w+);base64,/', $fotoBuktiRaw, $type)) {
                $imgData = substr($fotoBuktiRaw, strpos($fotoBuktiRaw, ',') + 1);
                $ext = strtolower($type[1]);
                if ($ext === 'jpeg') $ext = 'jpg';
                if (in_array($ext, ['jpg', 'png', 'webp'])) {
                    $decoded = base64_decode($imgData);
                    if ($decoded !== false) {
                        $filename = 'bukti_' . $g['id_karyawan'] . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        $uploadDir = __DIR__ . '/../uploads/bukti_bayar/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }
                        if (file_put_contents($uploadDir . $filename, $decoded)) {
                            $buktiPath = 'uploads/bukti_bayar/' . $filename;
                        }
                    }
                }
            }
        }

        $stmt = $pdo->prepare("
            UPDATE gaji 
            SET status_bayar = 'sudah', 
                tanggal_bayar = NOW(),
                metode_bayar = ?,
                catatan_bayar = ?,
                bukti_bayar = COALESCE(?, bukti_bayar)
            WHERE id_gaji = ?
        ");
        $stmt->execute([$metode, $catatanBayar, $buktiPath, $id_gaji]);

        // F-05: Update reimbursement uang pribadi menjadi sudah diganti
        $stmtReimburse = $pdo->prepare("
            UPDATE pengeluaran 
            SET status_ganti = 'sudah' 
            WHERE id_karyawan = ? 
              AND jenis_dana = 'pribadi' 
              AND status_verifikasi = 'disetujui'
              AND status_ganti = 'belum'
              AND DATE_FORMAT(tanggal, '%Y-%m') = ?
        ");
        $stmtReimburse->execute([$g['id_karyawan'], $g['periode']]);

        jsonResponse(true, "Pembayaran gaji berhasil dicatat sebagai LUNAS! (Metode: " . strtoupper($metode) . ")", [
            "id_gaji" => $id_gaji,
            "metode_bayar" => $metode,
            "catatan_bayar" => $catatanBayar,
            "bukti_bayar" => $buktiPath
        ]);
    } else {
        jsonResponse(false, "Aksi tidak dikenal", null, 400);
    }
} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}
