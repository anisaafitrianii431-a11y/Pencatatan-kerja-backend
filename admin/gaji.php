<?php
// =========================================================================
// API ADMIN: PENGGAJIAN KARYAWAN (CV. PUTRA ANUGRAH MANDIRI)
// 1. Data dan Perhitungan Gaji (Per Jam, Per Hari, Per Bulan, Snapshot)
// 2. Pembayaran Gaji (Transfer / Tunai, Bukti Bayar, Parsial & Penuh)
// 3. Riwayat dan Slip Gaji (Filter, Riwayat Transaksi, Cetak Slip)
// =========================================================================

require_once __DIR__ . '/../config/database.php';

// Otorisasi Khusus Admin
$currentUser = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();
$action = $_GET['action'] ?? $body['action'] ?? '';

// Formula SQL Durasi Kerja (Mendukung Shift Malam)
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

    // ==============================================================
    // A. KALKULASI & PRATINJAU GAJI (MENU 1)
    // ==============================================================
    if ($action === 'kalkulasi') {
        $id_karyawan = isset($_GET['id_karyawan']) ? intval($_GET['id_karyawan']) : 0;

        $whereK = "WHERE k.status = 'aktif'";
        $paramsK = [];
        if ($id_karyawan > 0) {
            $whereK .= " AND k.id_karyawan = ?";
            $paramsK[] = $id_karyawan;
        }

        // Ambil data seluruh karyawan aktif
        $stmtK = $pdo->prepare("
            SELECT k.id_karyawan, k.kode_karyawan, k.nama, k.jabatan, k.nomor_hp, k.email,
                   k.jenis_upah,
                   COALESCE(k.nominal_upah, k.tarif_per_jam, 0) AS tarif_master
            FROM karyawan k
            $whereK
            ORDER BY k.id_karyawan ASC
        ");
        $stmtK->execute($paramsK);
        $karyawanList = $stmtK->fetchAll(PDO::FETCH_ASSOC);

        $results = [];

        foreach ($karyawanList as $k) {
            $kId = intval($k['id_karyawan']);
            $jenisUpah = $k['jenis_upah'] ?: 'per_jam';
            $tarifMaster = floatval($k['tarif_master']);

            // 1. Ambil agregasi presensi HANYA yang berstatus 'disetujui' dan sudah ada jam_pulang
            $stmtPresensi = $pdo->prepare("
                SELECT 
                    COUNT(a.id_absensi) AS total_sesi,
                    COUNT(DISTINCT a.tanggal) AS total_hari,
                    IFNULL(SUM($sqlDurasi), 0) AS total_jam
                FROM absensi a
                WHERE a.id_karyawan = ?
                  AND DATE_FORMAT(a.tanggal, '%Y-%m') = ?
                  AND a.status_verifikasi = 'disetujui'
                  AND a.jam_pulang IS NOT NULL
            ");
            $stmtPresensi->execute([$kId, $periode]);
            $presensi = $stmtPresensi->fetch(PDO::FETCH_ASSOC);

            $totalJamActual = round(floatval($presensi['total_jam'] ?? 0), 2);
            $totalHariActual = intval($presensi['total_hari'] ?? 0);
            $totalSesiActual = intval($presensi['total_sesi'] ?? 0);

            // 2. Cek apakah record gaji sudah pernah disimpan/ditetapkan untuk periode ini
            $stmtGaji = $pdo->prepare("
                SELECT g.*
                FROM gaji g
                WHERE g.id_karyawan = ? AND g.periode = ?
                LIMIT 1
            ");
            $stmtGaji->execute([$kId, $periode]);
            $gajiRow = $stmtGaji->fetch(PDO::FETCH_ASSOC);

            if ($gajiRow) {
                // Gunakan data snapshot yang tersimpan
                $isSaved = true;
                $statusPenetapan = $gajiRow['status_penetapan'] ?: 'ditetapkan';
                $statusBayar = $gajiRow['status_bayar'] ?: 'belum';
                $jenisUpahUsed = $gajiRow['jenis_upah'] ?: $jenisUpah;
                $tarifUsed = floatval($gajiRow['tarif']);
                $totalJamUsed = floatval($gajiRow['total_jam']);
                $totalHariUsed = intval($gajiRow['total_hari']);
                $totalUpahUsed = floatval($gajiRow['total_upah']);
                $bonusUsed = floatval($gajiRow['bonus']);
                $potonganUsed = floatval($gajiRow['potongan']);
                $catatanPenyesuaian = $gajiRow['catatan_penyesuaian'] ?? '';
                $gajiBersihUsed = floatval($gajiRow['gaji_bersih']);
                $totalDibayar = floatval($gajiRow['total_dibayar']);
                $sisaPembayaran = floatval($gajiRow['sisa_pembayaran']);
                $idGaji = intval($gajiRow['id_gaji']);
                $tanggalDitetapkan = $gajiRow['tanggal_ditetapkan'] ?? null;
                $ditetapkanOleh = $gajiRow['ditetapkan_oleh'] ?? null;
            } else {
                // Hitung otomatis baru (Draft / Preview)
                $isSaved = false;
                $statusPenetapan = 'draft';
                $statusBayar = 'belum';
                $jenisUpahUsed = $jenisUpah;
                $tarifUsed = $tarifMaster;
                $totalJamUsed = $totalJamActual;
                $totalHariUsed = $totalHariActual;

                // Hitung sesuai jenis upah
                if ($jenisUpahUsed === 'per_jam') {
                    $totalUpahUsed = round($totalJamUsed * $tarifUsed, 2);
                } elseif ($jenisUpahUsed === 'per_hari') {
                    $totalUpahUsed = round($totalHariUsed * $tarifUsed, 2);
                } else { // per_bulan
                    $totalUpahUsed = round($tarifUsed, 2);
                }

                $bonusUsed = 0.00;
                $potonganUsed = 0.00;
                $catatanPenyesuaian = '';
                $gajiBersihUsed = max(0, $totalUpahUsed);
                $totalDibayar = 0.00;
                $sisaPembayaran = $gajiBersihUsed;
                $idGaji = null;
                $tanggalDitetapkan = null;
                $ditetapkanOleh = null;
            }

            $results[] = [
                'id_gaji' => $idGaji,
                'id_karyawan' => $kId,
                'kode_karyawan' => $k['kode_karyawan'] ?: 'KRY-' . str_pad($kId, 3, '0', STR_PAD_LEFT),
                'nama_karyawan' => $k['nama'],
                'jabatan' => $k['jabatan'],
                'periode' => $periode,
                'jenis_upah' => $jenisUpahUsed,
                'tarif' => $tarifUsed,
                'total_jam' => $totalJamUsed,
                'total_hari' => $totalHariUsed,
                'total_sesi' => $totalSesiActual,
                'total_upah' => $totalUpahUsed,
                'bonus' => $bonusUsed,
                'potongan' => $potonganUsed,
                'catatan_penyesuaian' => $catatanPenyesuaian,
                'gaji_bersih' => $gajiBersihUsed,
                'total_dibayar' => $totalDibayar,
                'sisa_pembayaran' => $sisaPembayaran,
                'status_bayar' => $statusBayar,
                'status_penetapan' => $statusPenetapan,
                'sudah_ditetapkan' => ($statusPenetapan === 'ditetapkan'),
                'is_saved' => $isSaved,
                'tanggal_ditetapkan' => $tanggalDitetapkan,
                'ditetapkan_oleh' => $ditetapkanOleh
            ];
        }

        jsonResponse(true, "Kalkulasi gaji periode $periode berhasil dimuat", $results);
    }

    // ==============================================================
    // B. RINCIAN SESI PRESENSI DISETUJUI & DETAIL PENGERJAAN
    // ==============================================================
    if ($action === 'detail') {
        $id_karyawan = intval($_GET['id_karyawan'] ?? 0);
        $id_gaji = intval($_GET['id_gaji'] ?? 0);

        if ($id_gaji > 0 && $id_karyawan <= 0) {
            $stmtG = $pdo->prepare("SELECT id_karyawan, periode FROM gaji WHERE id_gaji = ?");
            $stmtG->execute([$id_gaji]);
            $gRow = $stmtG->fetch(PDO::FETCH_ASSOC);
            if ($gRow) {
                $id_karyawan = intval($gRow['id_karyawan']);
                $periode = $gRow['periode'];
            }
        }

        if ($id_karyawan <= 0) {
            jsonResponse(false, "ID Karyawan wajib disertakan", null, 400);
        }

        // Ambil info karyawan
        $stmtK = $pdo->prepare("SELECT * FROM karyawan WHERE id_karyawan = ?");
        $stmtK->execute([$id_karyawan]);
        $karyawan = $stmtK->fetch(PDO::FETCH_ASSOC);

        // Ambil seluruh sesi absensi yang berstatus 'disetujui' pada periode ini
        $stmtAbsensi = $pdo->prepare("
            SELECT a.id_absensi, a.tanggal, a.jam_masuk, a.jam_pulang,
                   p.nama_pekerjaan,
                   ROUND($sqlDurasi, 2) AS durasi_jam
            FROM absensi a
            LEFT JOIN pekerjaan p ON a.id_pekerjaan = p.id_pekerjaan
            WHERE a.id_karyawan = ?
              AND DATE_FORMAT(a.tanggal, '%Y-%m') = ?
              AND a.status_verifikasi = 'disetujui'
              AND a.jam_pulang IS NOT NULL
            ORDER BY a.tanggal ASC, a.jam_masuk ASC
        ");
        $stmtAbsensi->execute([$id_karyawan, $periode]);
        $absensiList = $stmtAbsensi->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, "Rincian presensi disetujui berhasil dimuat", [
            'karyawan' => $karyawan,
            'periode' => $periode,
            'absensi' => $absensiList
        ]);
    }

    // ==============================================================
    // C. DAFTAR GAJI SIAP DIBAYAR (MENU 2: PEMBAYARAN GAJI)
    // ==============================================================
    if ($action === 'pembayaran_list') {
        $filterStatus = $_GET['status_bayar'] ?? 'semua'; // semua, belum, sebagian, sudah

        $where = ["g.status_penetapan = 'ditetapkan'"];
        $params = [];

        if (!empty($_GET['periode']) && $_GET['periode'] !== 'semua') {
            $where[] = "g.periode = ?";
            $params[] = $_GET['periode'];
        }

        if ($filterStatus !== 'semua') {
            $where[] = "g.status_bayar = ?";
            $params[] = $filterStatus;
        }

        $whereClause = "WHERE " . implode(" AND ", $where);

        $sql = "
            SELECT g.*,
                   k.nama AS nama_karyawan, k.kode_karyawan, k.jabatan, k.nomor_hp,
                   (
                       SELECT COUNT(*) 
                       FROM pembayaran_gaji pb 
                       WHERE pb.id_gaji = g.id_gaji
                   ) AS jumlah_transaksi_bayar,
                   (
                       SELECT MAX(pb.tanggal_bayar) 
                       FROM pembayaran_gaji pb 
                       WHERE pb.id_gaji = g.id_gaji
                   ) AS tanggal_bayar_terakhir
            FROM gaji g
            JOIN karyawan k ON g.id_karyawan = k.id_karyawan
            $whereClause
            ORDER BY 
                CASE g.status_bayar 
                    WHEN 'belum' THEN 1 
                    WHEN 'sebagian' THEN 2 
                    ELSE 3 
                END,
                g.id_gaji DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, "Daftar pembayaran gaji berhasil dimuat", $list);
    }

    // ==============================================================
    // D. RIWAYAT PEMBAYARAN PER GAJI (HISTORICAL PAYMENTS)
    // ==============================================================
    if ($action === 'pembayaran_history') {
        $id_gaji = intval($_GET['id_gaji'] ?? 0);
        if ($id_gaji <= 0) {
            jsonResponse(false, "ID Gaji tidak valid", null, 400);
        }

        $stmt = $pdo->prepare("
            SELECT pb.*
            FROM pembayaran_gaji pb
            WHERE pb.id_gaji = ?
            ORDER BY pb.id_pembayaran DESC
        ");
        $stmt->execute([$id_gaji]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, "Riwayat pembayaran berhasil dimuat", $payments);
    }

    // ==============================================================
    // E. RIWAYAT & SLIP GAJI (MENU 3: RIWAYAT & DETAIL SLIP)
    // ==============================================================
    if ($action === 'slip') {
        $id_gaji = intval($_GET['id_gaji'] ?? 0);
        if ($id_gaji <= 0) {
            jsonResponse(false, "ID Gaji wajib disertakan", null, 400);
        }

        $stmt = $pdo->prepare("
            SELECT g.*,
                   k.nama AS nama_karyawan, k.kode_karyawan, k.jabatan, k.nomor_hp, k.email
            FROM gaji g
            JOIN karyawan k ON g.id_karyawan = k.id_karyawan
            WHERE g.id_gaji = ?
        ");
        $stmt->execute([$id_gaji]);
        $gaji = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$gaji) {
            jsonResponse(false, "Data slip gaji tidak ditemukan", null, 404);
        }

        // Ambil riwayat pembayaran transaksi
        $stmtPay = $pdo->prepare("
            SELECT * FROM pembayaran_gaji 
            WHERE id_gaji = ? 
            ORDER BY id_pembayaran ASC
        ");
        $stmtPay->execute([$id_gaji]);
        $pembayaranList = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

        // Ambil sesi absensi yang mendasari perhitungan ini
        $stmtAbsensi = $pdo->prepare("
            SELECT a.id_absensi, a.tanggal, a.jam_masuk, a.jam_pulang,
                   p.nama_pekerjaan,
                   ROUND($sqlDurasi, 2) AS durasi_jam
            FROM absensi a
            LEFT JOIN pekerjaan p ON a.id_pekerjaan = p.id_pekerjaan
            WHERE a.id_karyawan = ?
              AND DATE_FORMAT(a.tanggal, '%Y-%m') = ?
              AND a.status_verifikasi = 'disetujui'
              AND a.jam_pulang IS NOT NULL
            ORDER BY a.tanggal ASC, a.jam_masuk ASC
        ");
        $stmtAbsensi->execute([$gaji['id_karyawan'], $gaji['periode']]);
        $absensiList = $stmtAbsensi->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, "Detail slip gaji berhasil dimuat", [
            'gaji' => $gaji,
            'pembayaran' => $pembayaranList,
            'absensi' => $absensiList
        ]);
    }

    // ==============================================================
    // F. DEFAULT / RIWAYAT UMUM GAJI
    // ==============================================================
    $filterKaryawan = intval($_GET['id_karyawan'] ?? 0);
    $filterStatus = $_GET['status_bayar'] ?? 'semua';
    $search = trim($_GET['search'] ?? '');

    $where = [];
    $params = [];

    if (!empty($_GET['periode']) && $_GET['periode'] !== 'semua') {
        $where[] = "g.periode = ?";
        $params[] = $_GET['periode'];
    }

    if ($filterKaryawan > 0) {
        $where[] = "g.id_karyawan = ?";
        $params[] = $filterKaryawan;
    }

    if ($filterStatus !== 'semua' && in_array($filterStatus, ['belum', 'sebagian', 'sudah'])) {
        $where[] = "g.status_bayar = ?";
        $params[] = $filterStatus;
    }

    if (!empty($search)) {
        $where[] = "(k.nama LIKE ? OR k.kode_karyawan LIKE ? OR k.jabatan LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereClause = count($where) ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "
        SELECT g.*,
               k.nama AS nama_karyawan, k.kode_karyawan, k.jabatan, k.nomor_hp,
               (
                   SELECT COUNT(*) 
                   FROM pembayaran_gaji pb 
                   WHERE pb.id_gaji = g.id_gaji
               ) AS total_transaksi_pembayaran
        FROM gaji g
        JOIN karyawan k ON g.id_karyawan = k.id_karyawan
        $whereClause
        ORDER BY g.periode DESC, g.id_gaji DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, "Data riwayat gaji berhasil dimuat", $data);

} elseif ($method === 'POST') {

    // ==============================================================
    // 1. TETAPKAN / SIMPAN HASIL PERHITUNGAN GAJI (SNAPSHOT)
    // ==============================================================
    if ($action === 'tetapkan' || $action === 'hitung') {
        $targetPeriode = $body['periode'] ?? date('Y-m');
        $targetIdKaryawan = isset($body['id_karyawan']) ? intval($body['id_karyawan']) : 0;

        $whereK = "WHERE k.status = 'aktif'";
        $paramsK = [];
        if ($targetIdKaryawan > 0) {
            $whereK .= " AND k.id_karyawan = ?";
            $paramsK[] = $targetIdKaryawan;
        }

        $stmtK = $pdo->prepare("
            SELECT k.id_karyawan, k.kode_karyawan, k.nama, k.jenis_upah,
                   COALESCE(k.nominal_upah, k.tarif_per_jam, 0) AS tarif_master
            FROM karyawan k
            $whereK
        ");
        $stmtK->execute($paramsK);
        $karyawanList = $stmtK->fetchAll(PDO::FETCH_ASSOC);

        $ditetapkanCount = 0;
        $dilewatiCount = 0;

        foreach ($karyawanList as $k) {
            $kId = intval($k['id_karyawan']);

            // Cek apakah data gaji sudah pernah ada
            $stmtCheck = $pdo->prepare("
                SELECT id_gaji, status_bayar, total_dibayar, bonus, potongan, catatan_penyesuaian
                FROM gaji
                WHERE id_karyawan = ? AND periode = ?
            ");
            $stmtCheck->execute([$kId, $targetPeriode]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            // Jika sudah ada pembayaran (sebagian/sudah), lindungi agar total_dibayar tidak hilang
            $existingDibayar = $existing ? floatval($existing['total_dibayar'] ?? 0) : 0.00;
            $bonus = $existing ? floatval($existing['bonus'] ?? 0) : 0.00;
            $potongan = $existing ? floatval($existing['potongan'] ?? 0) : 0.00;
            $catatanPenyesuaian = $existing ? ($existing['catatan_penyesuaian'] ?? null) : null;

            // Jika request membawa custom penyesuaian untuk satu karyawan
            if ($targetIdKaryawan > 0 && isset($body['bonus'])) {
                $bonus = max(0, floatval($body['bonus']));
            }
            if ($targetIdKaryawan > 0 && isset($body['potongan'])) {
                $potongan = max(0, floatval($body['potongan']));
            }
            if ($targetIdKaryawan > 0 && isset($body['catatan_penyesuaian'])) {
                $catatanPenyesuaian = trim($body['catatan_penyesuaian']);
            }

            // Hitung jam & hari aktual HANYA dari absensi yang disetujui
            $stmtPresensi = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT a.tanggal) AS total_hari,
                    IFNULL(SUM($sqlDurasi), 0) AS total_jam
                FROM absensi a
                WHERE a.id_karyawan = ?
                  AND DATE_FORMAT(a.tanggal, '%Y-%m') = ?
                  AND a.status_verifikasi = 'disetujui'
                  AND a.jam_pulang IS NOT NULL
            ");
            $stmtPresensi->execute([$kId, $targetPeriode]);
            $pres = $stmtPresensi->fetch(PDO::FETCH_ASSOC);

            $totalJam = round(floatval($pres['total_jam'] ?? 0), 2);
            $totalHari = intval($pres['total_hari'] ?? 0);
            $jenisUpah = $k['jenis_upah'] ?: 'per_jam';
            $tarifSnapshot = floatval($k['tarif_master']);

            // Hitung upah pokok sesuai skema upah
            if ($jenisUpah === 'per_jam') {
                $totalUpah = round($totalJam * $tarifSnapshot, 2);
            } elseif ($jenisUpah === 'per_hari') {
                $totalUpah = round($totalHari * $tarifSnapshot, 2);
            } else { // per_bulan
                $totalUpah = round($tarifSnapshot, 2);
            }

            // Gaji Bersih = Total Upah + Bonus - Potongan
            $gajiBersih = max(0, round($totalUpah + $bonus - $potongan, 2));
            $sisaPembayaran = max(0, round($gajiBersih - $existingDibayar, 2));

            // Status bayar akurat
            if ($existingDibayar <= 0) {
                $statusBayar = 'belum';
            } elseif ($sisaPembayaran <= 0) {
                $statusBayar = 'sudah';
            } else {
                $statusBayar = 'sebagian';
            }

            $currentUserName = $currentUser['nama'] ?? 'Administrator';

            if ($existing) {
                // Update snapshot record yang ada
                $stmtUp = $pdo->prepare("
                    UPDATE gaji
                    SET jenis_upah = ?,
                        tarif = ?,
                        total_jam = ?,
                        total_hari = ?,
                        total_upah = ?,
                        total_ganti = 0.00,
                        bonus = ?,
                        potongan = ?,
                        catatan_penyesuaian = ?,
                        gaji_bersih = ?,
                        sisa_pembayaran = ?,
                        status_bayar = ?,
                        status_penetapan = 'ditetapkan',
                        tanggal_ditetapkan = NOW(),
                        ditetapkan_oleh = ?
                    WHERE id_gaji = ?
                ");
                $stmtUp->execute([
                    $jenisUpah,
                    $tarifSnapshot,
                    $totalJam,
                    $totalHari,
                    $totalUpah,
                    $bonus,
                    $potongan,
                    $catatanPenyesuaian,
                    $gajiBersih,
                    $sisaPembayaran,
                    $statusBayar,
                    $currentUserName,
                    $existing['id_gaji']
                ]);
            } else {
                // Insert snapshot baru
                $stmtIn = $pdo->prepare("
                    INSERT INTO gaji (
                        id_karyawan, periode, jenis_upah, total_jam, total_hari, tarif,
                        total_upah, total_ganti, bonus, potongan, catatan_penyesuaian,
                        gaji_bersih, total_dibayar, sisa_pembayaran,
                        status_bayar, status_penetapan, tanggal_ditetapkan, ditetapkan_oleh
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, 0.00, ?, ?, ?,
                        ?, ?, ?,
                        ?, 'ditetapkan', NOW(), ?
                    )
                ");
                $stmtIn->execute([
                    $kId,
                    $targetPeriode,
                    $jenisUpah,
                    $totalJam,
                    $totalHari,
                    $tarifSnapshot,
                    $totalUpah,
                    $bonus,
                    $potongan,
                    $catatanPenyesuaian,
                    $gajiBersih,
                    $existingDibayar,
                    $sisaPembayaran,
                    $statusBayar,
                    $currentUserName
                ]);
            }

            $ditetapkanCount++;
        }

        jsonResponse(true, "Penetapan hasil perhitungan gaji periode $targetPeriode berhasil disimpan untuk $ditetapkanCount karyawan!", [
            'periode' => $targetPeriode,
            'ditetapkan' => $ditetapkanCount
        ]);
    }

    // ==============================================================
    // 2. PENYESUAIAN GAJI (BONUS / POTONGAN KASBON)
    // ==============================================================
    if ($action === 'penyesuaian') {
        $id_gaji = intval($body['id_gaji'] ?? 0);
        $bonus = max(0, floatval($body['bonus'] ?? 0));
        $potongan = max(0, floatval($body['potongan'] ?? 0));
        $catatan = trim($body['catatan_penyesuaian'] ?? '');

        if ($id_gaji <= 0) {
            jsonResponse(false, "ID Gaji tidak valid", null, 400);
        }

        $stmtGet = $pdo->prepare("SELECT total_upah, total_dibayar, status_bayar FROM gaji WHERE id_gaji = ?");
        $stmtGet->execute([$id_gaji]);
        $row = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            jsonResponse(false, "Data gaji tidak ditemukan", null, 404);
        }

        $totalUpah = floatval($row['total_upah']);
        $totalDibayar = floatval($row['total_dibayar']);
        $gajiBersihBaru = max(0, round($totalUpah + $bonus - $potongan, 2));
        $sisaBaru = max(0, round($gajiBersihBaru - $totalDibayar, 2));

        if ($totalDibayar <= 0) {
            $statusBaru = 'belum';
        } elseif ($sisaBaru <= 0) {
            $statusBaru = 'sudah';
        } else {
            $statusBaru = 'sebagian';
        }

        $stmtUp = $pdo->prepare("
            UPDATE gaji 
            SET bonus = ?,
                potongan = ?,
                catatan_penyesuaian = ?,
                gaji_bersih = ?,
                sisa_pembayaran = ?,
                status_bayar = ?
            WHERE id_gaji = ?
        ");
        $stmtUp->execute([$bonus, $potongan, $catatan, $gajiBersihBaru, $sisaBaru, $statusBaru, $id_gaji]);

        jsonResponse(true, "Penyesuaian gaji berhasil diperbarui!", [
            'id_gaji' => $id_gaji,
            'bonus' => $bonus,
            'potongan' => $potongan,
            'catatan_penyesuaian' => $catatan,
            'gaji_bersih' => $gajiBersihBaru,
            'sisa_pembayaran' => $sisaBaru,
            'status_bayar' => $statusBaru
        ]);
    }

    // ==============================================================
    // 3. PENCATATAN PEMBAYARAN GAJI (MENU 2: BAYAR GAJI)
    // Mendukung Bayar Parsial Maupun Penuh
    // ==============================================================
    if ($action === 'bayar') {
        $id_gaji = intval($body['id_gaji'] ?? 0);
        $nominalBayar = floatval($body['nominal_bayar'] ?? 0);
        $tanggalBayar = $body['tanggal_bayar'] ?? date('Y-m-d');
        $metode = in_array($body['metode_pembayaran'] ?? '', ['transfer', 'tunai']) ? $body['metode_pembayaran'] : 'transfer';
        $nomorReferensi = trim($body['nomor_referensi'] ?? '');
        $namaPenerimaInput = trim($body['nama_penerima'] ?? '');
        $catatanBayar = trim($body['catatan'] ?? $body['catatan_bayar'] ?? '');
        $fotoBuktiRaw = $body['bukti_pembayaran'] ?? $body['foto_bukti'] ?? '';

        if ($id_gaji <= 0) {
            jsonResponse(false, "ID Gaji tidak valid", null, 400);
        }

        if ($nominalBayar <= 0) {
            jsonResponse(false, "Nominal pembayaran harus lebih besar dari Rp 0", null, 400);
        }

        // Ambil data gaji saat ini
        $stmtG = $pdo->prepare("
            SELECT g.*, k.nama AS nama_karyawan 
            FROM gaji g 
            JOIN karyawan k ON g.id_karyawan = k.id_karyawan 
            WHERE g.id_gaji = ?
        ");
        $stmtG->execute([$id_gaji]);
        $g = $stmtG->fetch(PDO::FETCH_ASSOC);

        if (!$g) {
            jsonResponse(false, "Data gaji tidak ditemukan", null, 404);
        }

        if ($g['status_penetapan'] !== 'ditetapkan') {
            jsonResponse(false, "Gaji harus berstatus 'ditetapkan' terlebih dahulu sebelum dapat dibayar", null, 400);
        }

        $gajiBersih = floatval($g['gaji_bersih']);
        $totalDibayarLama = floatval($g['total_dibayar']);
        $sisaPembayaranLama = floatval($g['sisa_pembayaran']);

        if ($sisaPembayaranLama <= 0 && $g['status_bayar'] === 'sudah') {
            jsonResponse(false, "Gaji ini sudah lunas dibayar sebelumnya", null, 400);
        }

        if ($nominalBayar > ($sisaPembayaranLama + 0.01)) {
            jsonResponse(false, "Nominal pembayaran (Rp " . number_format($nominalBayar, 0, ',', '.') . ") melebihi sisa pembayaran (Rp " . number_format($sisaPembayaranLama, 0, ',', '.') . ")", null, 400);
        }

        $namaPenerima = !empty($namaPenerimaInput) ? $namaPenerimaInput : $g['nama_karyawan'];
        $namaPembayar = $currentUser['nama'] ?? 'Administrator';

        // Simpan foto bukti pembayaran jika ada (Base64 atau path)
        $buktiPath = null;
        if (!empty($fotoBuktiRaw)) {
            if (strpos($fotoBuktiRaw, 'data:image') === 0 && preg_match('/^data:image\/(\w+);base64,/', $fotoBuktiRaw, $matches)) {
                $imgData = substr($fotoBuktiRaw, strpos($fotoBuktiRaw, ',') + 1);
                $ext = strtolower($matches[1]);
                if ($ext === 'jpeg') $ext = 'jpg';
                if (in_array($ext, ['jpg', 'png', 'webp', 'pdf'])) {
                    $decoded = base64_decode($imgData);
                    if ($decoded !== false) {
                        $filename = 'bukti_gaji_' . $id_gaji . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        $uploadDir = __DIR__ . '/../uploads/bukti_gaji/';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0777, true);
                        }
                        if (file_put_contents($uploadDir . $filename, $decoded)) {
                            $buktiPath = 'uploads/bukti_gaji/' . $filename;
                        }
                    }
                }
            } elseif (strpos($fotoBuktiRaw, 'uploads/') === 0) {
                $buktiPath = $fotoBuktiRaw;
            }
        }

        // 1. Simpan transaksi ke tabel pembayaran_gaji
        $stmtPay = $pdo->prepare("
            INSERT INTO pembayaran_gaji (
                id_gaji, tanggal_bayar, nominal_bayar, metode_pembayaran,
                nomor_referensi, nama_penerima, nama_pembayar, bukti_pembayaran, catatan
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?
            )
        ");
        $stmtPay->execute([
            $id_gaji,
            $tanggalBayar,
            $nominalBayar,
            $metode,
            $nomorReferensi,
            $namaPenerima,
            $namaPembayar,
            $buktiPath,
            $catatanBayar
        ]);
        $idPembayaran = $pdo->lastInsertId();

        // 2. Hitung total seluruh pembayaran yang sudah masuk untuk gaji ini
        $stmtSum = $pdo->prepare("SELECT IFNULL(SUM(nominal_bayar), 0) AS total FROM pembayaran_gaji WHERE id_gaji = ?");
        $stmtSum->execute([$id_gaji]);
        $totalDibayarBaru = floatval($stmtSum->fetchColumn());

        $sisaPembayaranBaru = max(0, round($gajiBersih - $totalDibayarBaru, 2));

        // Tentukan status bayar: Belum, Dibayar Sebagian, atau Sudah Dibayar
        if ($totalDibayarBaru <= 0) {
            $statusBaru = 'belum';
        } elseif ($sisaPembayaranBaru <= 0.01) {
            $statusBaru = 'sudah';
            $sisaPembayaranBaru = 0.00;
        } else {
            $statusBaru = 'sebagian';
        }

        // 3. Update tabel gaji
        $stmtUpG = $pdo->prepare("
            UPDATE gaji
            SET total_dibayar = ?,
                sisa_pembayaran = ?,
                status_bayar = ?,
                tanggal_bayar = NOW()
            WHERE id_gaji = ?
        ");
        $stmtUpG->execute([$totalDibayarBaru, $sisaPembayaranBaru, $statusBaru, $id_gaji]);

        $statusText = $statusBaru === 'sudah' ? 'LUNAS (Sudah Dibayar)' : 'DIBAYAR SEBAGIAN';

        jsonResponse(true, "Pembayaran sebesar Rp " . number_format($nominalBayar, 0, ',', '.') . " berhasil dicatat! Status: $statusText", [
            'id_pembayaran' => $idPembayaran,
            'id_gaji' => $id_gaji,
            'nominal_bayar' => $nominalBayar,
            'total_dibayar' => $totalDibayarBaru,
            'sisa_pembayaran' => $sisaPembayaranBaru,
            'status_bayar' => $statusBaru,
            'bukti_pembayaran' => $buktiPath
        ]);
    }

    // ==============================================================
    // 4. UPLOAD BUKTI PEMBAYARAN GAJI (FILE UPLOAD)
    // ==============================================================
    if ($action === 'upload_bukti') {
        if (!isset($_FILES['bukti']) || $_FILES['bukti']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, "File bukti pembayaran tidak terunggah dengan benar", null, 400);
        }

        $file = $_FILES['bukti'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        if (!in_array($ext, $allowed)) {
            jsonResponse(false, "Format file tidak didukung. Harap unggah file JPG, PNG, WEBP, atau PDF", null, 400);
        }

        $filename = 'bukti_gaji_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $uploadDir = __DIR__ . '/../uploads/bukti_gaji/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            jsonResponse(true, "Bukti pembayaran berhasil diunggah", [
                'file_path' => 'uploads/bukti_gaji/' . $filename
            ]);
        } else {
            jsonResponse(false, "Gagal menyimpan file bukti", null, 500);
        }
    }

    jsonResponse(false, "Aksi tidak dikenal", null, 400);

} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}
