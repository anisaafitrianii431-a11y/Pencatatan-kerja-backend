<?php
// =========================================================================
// BACKEND KHUSUS ADMIN: MODUL PENGELUARAN & KWITANSI TERPADU
// CV. PUTRA ANUGRAH MANDIRI
// 1. Data Pengeluaran (Uang Kantor & Uang Pribadi, Approval, Alasan Tolak)
// 2. Pengelolaan Dana:
//    A. Dana Uang Kantor (Pemasukan Kas Perusahaan & Penyaluran Internal PJ)
//    B. Pengembalian Uang Kantor & Reimbursement (Pembayaran Parsial & Lunas)
// 3. Rekap & Laporan Pertanggungjawaban Saldo Penanggung Jawab
// =========================================================================

require_once __DIR__ . '/../config/database.php';

// Otorisasi: Wajib Admin
$currentUser = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'];
$body = getRequestBody();

if (!empty($_POST)) {
    $body = array_merge($body, $_POST);
}

// Helper upload file nota / kwitansi jika ada
function handleFileUpload($fileKey, $prefix = 'nota_') {
    if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$fileKey];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        if (in_array($ext, ['jpg', 'png', 'webp', 'pdf'])) {
            $subFolder = ($prefix === 'bukti_reimburse_') ? 'uploads/bukti_reimburse/' : 'uploads/';
            $uploadDir = __DIR__ . '/../' . $subFolder;
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            $filename = $prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                return $subFolder . $filename;
            }
        }
    }
    return null;
}

$action = $_GET['action'] ?? $body['action'] ?? '';

// =========================================================================
// METHOD GET
// =========================================================================
if ($method === 'GET') {
    
    // ---------------------------------------------------------------------
    // 1. SUMMARY KEUANGAN TERPADU (DASHBOARD ADMIN & TAB REKAP & LAPORAN)
    // ---------------------------------------------------------------------
    if ($action === 'summary') {
        // A. Pemasukan Kas Perusahaan vs Penyaluran Dana Internal
        $qPemasukan = $pdo->query("
            SELECT IFNULL(SUM(nominal), 0) AS total_pemasukan 
            FROM dana_kantor 
            WHERE jenis_transaksi = 'pemasukan_kas'
        ")->fetch();
        $totalPemasukanKas = floatval($qPemasukan['total_pemasukan']);

        $qPenyaluran = $pdo->query("
            SELECT IFNULL(SUM(nominal), 0) AS total_disalurkan 
            FROM dana_kantor 
            WHERE jenis_transaksi = 'penyaluran_dana' OR jenis_transaksi IS NULL
        ")->fetch();
        $totalDanaDisalurkan = floatval($qPenyaluran['total_disalurkan']);

        // B. Pengeluaran Uang Kantor yang Disetujui
        $qPengeluaranKantor = $pdo->query("
            SELECT IFNULL(SUM(nominal), 0) AS total_kantor_disetujui
            FROM pengeluaran
            WHERE jenis_dana = 'kantor' AND status_verifikasi = 'disetujui'
        ")->fetch();
        $totalPengeluaranKantorDisetujui = floatval($qPengeluaranKantor['total_kantor_disetujui']);

        // C. Pengembalian Uang Kantor yang Diverifikasi
        $qKembali = $pdo->query("
            SELECT IFNULL(SUM(nominal), 0) AS total_kembali 
            FROM pengembalian_dana 
            WHERE status_verifikasi = 'disetujui'
        ")->fetch();
        $totalPengembalianDiverifikasi = floatval($qKembali['total_kembali']);

        // D. Saldo Dana yang Masih Dipegang Seluruh Penanggung Jawab
        // Saldo = Dana Disalurkan - Pengeluaran Kantor Disetujui - Pengembalian Diverifikasi
        $saldoDiTanganPj = max(0, $totalDanaDisalurkan - $totalPengeluaranKantorDisetujui - $totalPengembalianDiverifikasi);

        // E. Reimbursement Uang Pribadi
        $qReimburse = $pdo->query("
            SELECT 
                IFNULL(SUM(nominal), 0) AS total_diajukan,
                IFNULL(SUM(CASE WHEN status_verifikasi = 'disetujui' THEN IFNULL(nominal_disetujui, nominal) ELSE 0 END), 0) AS total_disetujui,
                IFNULL(SUM(total_dibayar), 0) AS total_sudah_dibayar,
                IFNULL(SUM(CASE WHEN status_verifikasi = 'disetujui' THEN sisa_pembayaran ELSE 0 END), 0) AS total_kewajiban_sisa
            FROM pengeluaran
            WHERE jenis_dana = 'pribadi'
        ")->fetch();

        // Total seluruh pengeluaran disetujui (uang kantor + uang pribadi disetujui) untuk kartu Dashboard Admin
        $qAllApproved = $pdo->query("
            SELECT IFNULL(SUM(nominal), 0) AS total_semua_disetujui
            FROM pengeluaran
            WHERE status_verifikasi = 'disetujui'
        ")->fetch();

        // Menunggu verifikasi
        $qPending = $pdo->query("
            SELECT COUNT(*) AS pending_count, IFNULL(SUM(nominal), 0) AS pending_nominal
            FROM pengeluaran
            WHERE status_verifikasi = 'menunggu'
        ")->fetch();

        jsonResponse(true, "Summary pengeluaran berhasil dimuat", [
            // Data untuk Dashboard Admin Utama (kartu Total Pengeluaran)
            'total_pengeluaran_disetujui'        => floatval($qAllApproved['total_semua_disetujui']),
            'uang_kantor_disetujui'              => $totalPengeluaranKantorDisetujui,
            'uang_pribadi_disetujui'             => floatval($qReimburse['total_disetujui']),
            'pending_count'                      => intval($qPending['pending_count']),
            'pending_nominal'                    => floatval($qPending['pending_nominal']),

            // 7 Metrik Ringkasan untuk Tab 3: Rekap & Laporan
            'total_pemasukan_perusahaan'         => $totalPemasukanKas,
            'total_dana_disalurkan'              => $totalDanaDisalurkan,
            'total_pengeluaran_kantor_disetujui' => $totalPengeluaranKantorDisetujui,
            'total_pengembalian_diverifikasi'    => $totalPengembalianDiverifikasi,
            'total_reimbursement_sudah_dibayar'  => floatval($qReimburse['total_sudah_dibayar']),
            'total_reimbursement_belum_dibayar'  => floatval($qReimburse['total_kewajiban_sisa']),
            'total_saldo_penanggung_jawab'       => $saldoDiTanganPj
        ]);
    }

    // ---------------------------------------------------------------------
    // 2. DANA UANG KANTOR (PEMASUKAN PERUSAHAAN & PENYALURAN PENANGGUNG JAWAB)
    // ---------------------------------------------------------------------
    if ($action === 'dana_kantor') {
        $filterJenis = $_GET['jenis_transaksi'] ?? ''; // 'pemasukan_kas' atau 'penyaluran_dana'

        $where = [];
        $params = [];
        if (!empty($filterJenis)) {
            $where[] = "d.jenis_transaksi = ?";
            $params[] = $filterJenis;
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $sql = "
            SELECT d.*, 
                   k.nama AS nama_penanggung_jawab,
                   k.kode_karyawan AS kode_pj,
                   k.jabatan AS jabatan_pj,
                   p.nama_pekerjaan
            FROM dana_kantor d
            LEFT JOIN karyawan k ON d.id_penanggung_jawab = k.id_karyawan
            LEFT JOIN pekerjaan p ON d.id_pekerjaan = p.id_pekerjaan
            $whereClause
            ORDER BY d.tanggal DESC, d.id_dana DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $danaList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hitung penggunaan dana dan sisa saldo per transaksi penyaluran
        $result = [];
        foreach ($danaList as $d) {
            $idDana = (int)$d['id_dana'];
            $idPj = (int)$d['id_penanggung_jawab'];
            $nominal = floatval($d['nominal']);

            if ($d['jenis_transaksi'] === 'pemasukan_kas') {
                $result[] = array_merge($d, [
                    'terpakai'    => 0,
                    'dikembalikan'=> 0,
                    'sisa_dana'   => $nominal
                ]);
            } else {
                // Pengeluaran disetujui yang menggunakan dana ini
                $qUsed = $pdo->prepare("
                    SELECT IFNULL(SUM(nominal), 0) FROM pengeluaran 
                    WHERE jenis_dana = 'kantor' AND status_verifikasi = 'disetujui' AND (id_dana = ? OR (id_dana IS NULL AND id_penanggung_jawab = ?))
                ");
                $qUsed->execute([$idDana, $idPj]);
                $used = floatval($qUsed->fetchColumn());

                // Pengembalian disetujui untuk dana ini
                $qReturned = $pdo->prepare("
                    SELECT IFNULL(SUM(nominal), 0) FROM pengembalian_dana 
                    WHERE status_verifikasi = 'disetujui' AND id_dana = ?
                ");
                $qReturned->execute([$idDana]);
                $returned = floatval($qReturned->fetchColumn());

                $sisa = max(0, $nominal - $used - $returned);

                $result[] = array_merge($d, [
                    'terpakai'    => $used,
                    'dikembalikan'=> $returned,
                    'sisa_dana'   => $sisa
                ]);
            }
        }

        jsonResponse(true, "Data dana uang kantor berhasil dimuat", $result);
    }

    // ---------------------------------------------------------------------
    // 3. PENGEMBALIAN DANA UANG KANTOR
    // ---------------------------------------------------------------------
    if ($action === 'pengembalian_dana') {
        $sql = "
            SELECT pd.*,
                   k1.nama AS nama_karyawan,
                   k1.kode_karyawan AS kode_karyawan,
                   k1.jabatan AS jabatan_karyawan,
                   k2.nama AS nama_penanggung_jawab,
                   k2.kode_karyawan AS kode_pj,
                   d.sumber_dana,
                   d.nominal AS nominal_dana_awal
            FROM pengembalian_dana pd
            JOIN karyawan k1 ON pd.id_karyawan = k1.id_karyawan
            JOIN karyawan k2 ON pd.id_penanggung_jawab = k2.id_karyawan
            LEFT JOIN dana_kantor d ON pd.id_dana = d.id_dana
            ORDER BY pd.tanggal DESC, pd.id_pengembalian DESC
        ";
        $stmt = $pdo->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, "Data pengembalian dana berhasil dimuat", $data);
    }

    // ---------------------------------------------------------------------
    // 4. REIMBURSEMENT (PENGGANTIAN UANG PRIBADI KARYAWAN)
    // ---------------------------------------------------------------------
    if ($action === 'reimbursement_list') {
        $statusReimburse = $_GET['status_reimbursement'] ?? 'semua';
        $where = ["pg.jenis_dana = 'pribadi'"];
        $params = [];

        if ($statusReimburse !== 'semua' && !empty($statusReimburse)) {
            $where[] = "pg.status_reimbursement = ?";
            $params[] = $statusReimburse;
        }

        $whereClause = "WHERE " . implode(" AND ", $where);

        $sql = "
            SELECT pg.*,
                   k.nama AS nama_karyawan,
                   k.kode_karyawan,
                   k.jabatan,
                   p.nama_pekerjaan,
                   (
                       SELECT COUNT(*) 
                       FROM pembayaran_reimbursement pr 
                       WHERE pr.id_pengeluaran = pg.id_pengeluaran
                   ) AS total_transaksi_bayar,
                   (
                       SELECT MAX(pr.tanggal_bayar) 
                       FROM pembayaran_reimbursement pr 
                       WHERE pr.id_pengeluaran = pg.id_pengeluaran
                   ) AS tanggal_bayar_terakhir
            FROM pengeluaran pg
            JOIN karyawan k ON pg.id_karyawan = k.id_karyawan
            JOIN pekerjaan p ON pg.id_pekerjaan = p.id_pekerjaan
            $whereClause
            ORDER BY 
                CASE pg.status_reimbursement
                    WHEN 'menunggu' THEN 1
                    WHEN 'disetujui' THEN 2
                    WHEN 'sebagian' THEN 3
                    ELSE 4
                END,
                pg.tanggal DESC,
                pg.id_pengeluaran DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, "Daftar pengajuan reimbursement berhasil dimuat", $data);
    }

    // ---------------------------------------------------------------------
    // 5. RIWAYAT TRANSAKSI BAYAR REIMBURSEMENT
    // ---------------------------------------------------------------------
    if ($action === 'riwayat_bayar_reimbursement') {
        $id = intval($_GET['id_pengeluaran'] ?? 0);
        if ($id <= 0) {
            jsonResponse(false, "ID Pengeluaran tidak valid", null, 400);
        }

        $stmt = $pdo->prepare("
            SELECT pr.*
            FROM pembayaran_reimbursement pr
            WHERE pr.id_pengeluaran = ?
            ORDER BY pr.id_pembayaran_reimburse DESC
        ");
        $stmt->execute([$id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, "Riwayat pembayaran reimbursement berhasil dimuat", $payments);
    }

    // ---------------------------------------------------------------------
    // 6. REKAP UANG KANTOR (PER PENANGGUNG JAWAB & MUTASI LENGKAP)
    // ---------------------------------------------------------------------
    if ($action === 'rekap_uang_kantor') {
        $filterPj = intval($_GET['id_penanggung_jawab'] ?? 0);
        $filterPekerjaan = intval($_GET['id_pekerjaan'] ?? 0);
        $tglMulai = $_GET['tanggal_mulai'] ?? '';
        $tglSelesai = $_GET['tanggal_selesai'] ?? '';
        $filterBulan = $_GET['bulan'] ?? ''; // Format YYYY-MM
        $filterTahun = $_GET['tahun'] ?? ''; // Format YYYY

        // Ambil penanggung jawab yang relevan
        $wherePj = "";
        $paramsPj = [];
        if ($filterPj > 0) {
            $wherePj = "AND k.id_karyawan = ?";
            $paramsPj[] = $filterPj;
        }

        $sqlPj = "
            SELECT DISTINCT k.id_karyawan, k.kode_karyawan, k.nama, k.jabatan
            FROM karyawan k
            WHERE k.id_karyawan IN (
                SELECT DISTINCT id_penanggung_jawab FROM dana_kantor
                UNION
                SELECT DISTINCT id_penanggung_jawab FROM pengeluaran WHERE jenis_dana = 'kantor' AND id_penanggung_jawab IS NOT NULL
                UNION
                SELECT DISTINCT id_penanggung_jawab FROM pengembalian_dana
            ) $wherePj
            ORDER BY k.nama ASC
        ";
        $stmtPj = $pdo->prepare($sqlPj);
        $stmtPj->execute($paramsPj);
        $pjs = $stmtPj->fetchAll(PDO::FETCH_ASSOC);

        $rekapPerPj = [];
        $totalSemuaDiterima = 0;
        $totalSemuaPengeluaran = 0;
        $totalSemuaPengembalian = 0;
        $totalSemuaSaldo = 0;

        foreach ($pjs as $pj) {
            $idPj = (int)$pj['id_karyawan'];

            // 1. Total Dana Disalurkan ke PJ
            $whereDana = ["id_penanggung_jawab = ?", "(jenis_transaksi = 'penyaluran_dana' OR jenis_transaksi IS NULL)"];
            $paramsDana = [$idPj];
            if ($filterPekerjaan > 0) {
                $whereDana[] = "id_pekerjaan = ?";
                $paramsDana[] = $filterPekerjaan;
            }
            if (!empty($tglMulai)) {
                $whereDana[] = "tanggal >= ?";
                $paramsDana[] = $tglMulai;
            }
            if (!empty($tglSelesai)) {
                $whereDana[] = "tanggal <= ?";
                $paramsDana[] = $tglSelesai;
            }
            if (!empty($filterBulan)) {
                $whereDana[] = "DATE_FORMAT(tanggal, '%Y-%m') = ?";
                $paramsDana[] = $filterBulan;
            }
            if (!empty($filterTahun)) {
                $whereDana[] = "DATE_FORMAT(tanggal, '%Y') = ?";
                $paramsDana[] = $filterTahun;
            }

            $stmtDana = $pdo->prepare("SELECT IFNULL(SUM(nominal), 0) FROM dana_kantor WHERE " . implode(" AND ", $whereDana));
            $stmtDana->execute($paramsDana);
            $danaDiterima = floatval($stmtDana->fetchColumn());

            // 2. Total Pengeluaran Disetujui
            $wherePengeluaran = ["jenis_dana = 'kantor'", "id_penanggung_jawab = ?", "status_verifikasi = 'disetujui'"];
            $paramsPengeluaran = [$idPj];
            if ($filterPekerjaan > 0) {
                $wherePengeluaran[] = "id_pekerjaan = ?";
                $paramsPengeluaran[] = $filterPekerjaan;
            }
            if (!empty($tglMulai)) {
                $wherePengeluaran[] = "tanggal >= ?";
                $paramsPengeluaran[] = $tglMulai;
            }
            if (!empty($tglSelesai)) {
                $wherePengeluaran[] = "tanggal <= ?";
                $paramsPengeluaran[] = $tglSelesai;
            }
            if (!empty($filterBulan)) {
                $wherePengeluaran[] = "DATE_FORMAT(tanggal, '%Y-%m') = ?";
                $paramsPengeluaran[] = $filterBulan;
            }
            if (!empty($filterTahun)) {
                $wherePengeluaran[] = "DATE_FORMAT(tanggal, '%Y') = ?";
                $paramsPengeluaran[] = $filterTahun;
            }

            $stmtPeng = $pdo->prepare("SELECT IFNULL(SUM(nominal), 0) FROM pengeluaran WHERE " . implode(" AND ", $wherePengeluaran));
            $stmtPeng->execute($paramsPengeluaran);
            $pengeluaranDisetujui = floatval($stmtPeng->fetchColumn());

            // 3. Total Pengembalian Disetujui
            $whereKembali = ["id_penanggung_jawab = ?", "status_verifikasi = 'disetujui'"];
            $paramsKembali = [$idPj];
            if (!empty($tglMulai)) {
                $whereKembali[] = "tanggal >= ?";
                $paramsKembali[] = $tglMulai;
            }
            if (!empty($tglSelesai)) {
                $whereKembali[] = "tanggal <= ?";
                $paramsKembali[] = $tglSelesai;
            }
            if (!empty($filterBulan)) {
                $whereKembali[] = "DATE_FORMAT(tanggal, '%Y-%m') = ?";
                $paramsKembali[] = $filterBulan;
            }
            if (!empty($filterTahun)) {
                $whereKembali[] = "DATE_FORMAT(tanggal, '%Y') = ?";
                $paramsKembali[] = $filterTahun;
            }

            $stmtKembali = $pdo->prepare("SELECT IFNULL(SUM(nominal), 0) FROM pengembalian_dana WHERE " . implode(" AND ", $whereKembali));
            $stmtKembali->execute($paramsKembali);
            $pengembalianDisetujui = floatval($stmtKembali->fetchColumn());

            // 4. Saldo Sisa yang Masih Dipegang
            $saldoSisa = $danaDiterima - $pengeluaranDisetujui - $pengembalianDisetujui;

            // 5. Riwayat transaksi mutasi penanggung jawab
            $riwayatPenerimaan = $pdo->prepare("
                SELECT id_dana AS id_ref, nomor_transaksi, 'penyaluran' AS tipe_mutasi, tanggal, nominal, sumber_dana AS keterangan, pemberi_dana, penerima_dana, bukti_transaksi AS bukti
                FROM dana_kantor 
                WHERE id_penanggung_jawab = ?
                ORDER BY tanggal DESC
            ");
            $riwayatPenerimaan->execute([$idPj]);

            $riwayatPengeluaran = $pdo->prepare("
                SELECT pe.id_pengeluaran AS id_ref, pe.nomor_transaksi, 'pengeluaran' AS tipe_mutasi, pe.tanggal, pe.nominal, pe.keterangan, pe.kategori, pe.foto_kwitansi AS bukti,
                       pe.status_verifikasi, p.nama_pekerjaan, k.nama AS nama_pelaksana
                FROM pengeluaran pe
                JOIN pekerjaan p ON pe.id_pekerjaan = p.id_pekerjaan
                JOIN karyawan k ON pe.id_karyawan = k.id_karyawan
                WHERE pe.jenis_dana = 'kantor' AND pe.id_penanggung_jawab = ?
                ORDER BY pe.tanggal DESC
            ");
            $riwayatPengeluaran->execute([$idPj]);

            $riwayatPengembalian = $pdo->prepare("
                SELECT id_pengembalian AS id_ref, nomor_transaksi, 'pengembalian' AS tipe_mutasi, tanggal, nominal, keterangan, penerima_pengembalian, bukti_pengembalian AS bukti, status_verifikasi
                FROM pengembalian_dana
                WHERE id_penanggung_jawab = ?
                ORDER BY tanggal DESC
            ");
            $riwayatPengembalian->execute([$idPj]);

            $totalSemuaDiterima += $danaDiterima;
            $totalSemuaPengeluaran += $pengeluaranDisetujui;
            $totalSemuaPengembalian += $pengembalianDisetujui;
            $totalSemuaSaldo += $saldoSisa;

            $statusPertanggungjawaban = 'Lunas / Seimbang';
            if ($saldoSisa > 0) {
                $statusPertanggungjawaban = 'Memegang Saldo Rp ' . number_format($saldoSisa, 0, ',', '.');
            } elseif ($saldoSisa < 0) {
                $statusPertanggungjawaban = 'Lebih Pakai Rp ' . number_format(abs($saldoSisa), 0, ',', '.');
            }

            $rekapPerPj[] = [
                'penanggung_jawab'          => $pj,
                'total_dana_diterima'       => $danaDiterima,
                'total_pengeluaran'         => $pengeluaranDisetujui,
                'total_pengembalian'        => $pengembalianDisetujui,
                'saldo_dipegang'            => $saldoSisa,
                'status_pertanggungjawaban' => $statusPertanggungjawaban,
                'daftar_penerimaan'         => $riwayatPenerimaan->fetchAll(PDO::FETCH_ASSOC),
                'daftar_pengeluaran'        => $riwayatPengeluaran->fetchAll(PDO::FETCH_ASSOC),
                'daftar_pengembalian'       => $riwayatPengembalian->fetchAll(PDO::FETCH_ASSOC),
            ];
        }

        jsonResponse(true, "Rekapitulasi dana uang kantor berhasil dimuat", [
            'ringkasan_global' => [
                'total_dana_diterima'    => $totalSemuaDiterima,
                'total_pengeluaran'      => $totalSemuaPengeluaran,
                'total_pengembalian'     => $totalSemuaPengembalian,
                'total_saldo_dipegang'   => $totalSemuaSaldo,
            ],
            'rekap_per_pj' => $rekapPerPj
        ]);
    }

    // ---------------------------------------------------------------------
    // 7. DATA PENGELUARAN UTAMA (TAB 1: FILTER & PENCARIAN LENGKAP)
    // ---------------------------------------------------------------------
    $id_pekerjaan = isset($_GET['id_pekerjaan']) && $_GET['id_pekerjaan'] !== '' ? intval($_GET['id_pekerjaan']) : 0;
    $id_karyawan = isset($_GET['id_karyawan']) && $_GET['id_karyawan'] !== '' ? intval($_GET['id_karyawan']) : 0;
    $jenis_dana = $_GET['jenis_dana'] ?? '';
    $status_verifikasi = $_GET['status_verifikasi'] ?? '';
    $kategori = $_GET['kategori'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $tglMulai = $_GET['tanggal_mulai'] ?? '';
    $tglSelesai = $_GET['tanggal_selesai'] ?? '';
    $bulan = $_GET['bulan'] ?? '';

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
    if (!empty($status_verifikasi) && in_array($status_verifikasi, ['menunggu', 'disetujui', 'ditolak'])) {
        $where[] = "pg.status_verifikasi = ?";
        $params[] = $status_verifikasi;
    }
    if (!empty($kategori)) {
        $where[] = "pg.kategori = ?";
        $params[] = $kategori;
    }
    if (!empty($tglMulai)) {
        $where[] = "pg.tanggal >= ?";
        $params[] = $tglMulai;
    }
    if (!empty($tglSelesai)) {
        $where[] = "pg.tanggal <= ?";
        $params[] = $tglSelesai;
    }
    if (!empty($bulan)) {
        $where[] = "DATE_FORMAT(pg.tanggal, '%Y-%m') = ?";
        $params[] = $bulan;
    }
    if (!empty($search)) {
        $where[] = "(k.nama LIKE ? OR k.kode_karyawan LIKE ? OR p.nama_pekerjaan LIKE ? OR pg.keterangan LIKE ? OR pg.nomor_transaksi LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "
        SELECT pg.*,
               k.nama AS nama_karyawan,
               k.kode_karyawan,
               k.jabatan,
               p.nama_pekerjaan,
               p.lokasi AS lokasi_pekerjaan,
               pj.nama AS nama_penanggung_jawab,
               pj.kode_karyawan AS kode_pj,
               d.sumber_dana
        FROM pengeluaran pg
        JOIN karyawan k ON pg.id_karyawan = k.id_karyawan
        JOIN pekerjaan p ON pg.id_pekerjaan = p.id_pekerjaan
        LEFT JOIN karyawan pj ON pg.id_penanggung_jawab = pj.id_karyawan
        LEFT JOIN dana_kantor d ON pg.id_dana = d.id_dana
        $whereClause
        ORDER BY pg.tanggal DESC, pg.id_pengeluaran DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, "Daftar pengeluaran berhasil dimuat", $data);

// =========================================================================
// METHOD POST
// =========================================================================
} elseif ($method === 'POST') {

    // ---------------------------------------------------------------------
    // A. VERIFIKASI PENGELUARAN (SETUJUI / TOLAK & CATATAN ALASAN)
    // ---------------------------------------------------------------------
    if ($action === 'verifikasi') {
        $id = intval($body['id_pengeluaran'] ?? 0);
        $status = $body['status_verifikasi'] ?? '';
        $catatan = trim($body['catatan_penolakan'] ?? $body['catatan'] ?? '');
        $nominalDisetujui = isset($body['nominal_disetujui']) ? floatval($body['nominal_disetujui']) : null;

        if (!in_array($status, ['disetujui', 'ditolak', 'menunggu'])) {
            jsonResponse(false, "Status verifikasi tidak valid", null, 400);
        }

        $stmtCurr = $pdo->prepare("SELECT * FROM pengeluaran WHERE id_pengeluaran = ?");
        $stmtCurr->execute([$id]);
        $curr = $stmtCurr->fetch(PDO::FETCH_ASSOC);
        if (!$curr) {
            jsonResponse(false, "Pengeluaran tidak ditemukan", null, 404);
        }

        $nominalAwal = floatval($curr['nominal']);
        if ($status === 'disetujui') {
            if ($nominalDisetujui === null || $nominalDisetujui <= 0) {
                $nominalDisetujui = $nominalAwal;
            }
            $statusReimburse = ($curr['jenis_dana'] === 'pribadi') ? 'disetujui' : null;
            $sisaBayar = ($curr['jenis_dana'] === 'pribadi') ? max(0, $nominalDisetujui - floatval($curr['total_dibayar'])) : 0;
            $statusGanti = ($sisaBayar <= 0 && floatval($curr['total_dibayar']) > 0) ? 'sudah' : 'belum';
        } elseif ($status === 'ditolak') {
            $statusReimburse = 'ditolak';
            $statusGanti = 'ditolak';
            $sisaBayar = 0.00;
        } else {
            $statusReimburse = 'menunggu';
            $statusGanti = 'belum';
            $sisaBayar = $nominalAwal;
        }

        $stmt = $pdo->prepare("
            UPDATE pengeluaran 
            SET status_verifikasi = ?, 
                nominal_disetujui = ?,
                sisa_pembayaran = ?,
                status_reimbursement = COALESCE(?, status_reimbursement),
                status_ganti = ?,
                catatan_penolakan = ? 
            WHERE id_pengeluaran = ?
        ");
        $stmt->execute([$status, $nominalDisetujui, $sisaBayar, $statusReimburse, $statusGanti, $catatan ?: null, $id]);

        jsonResponse(true, "Pengeluaran berhasil diverifikasi ({$status})");

    // ---------------------------------------------------------------------
    // B. PEMBAYARAN REIMBURSEMENT (UANG PRIBADI) - PARSIAL ATAU PENUH
    // ---------------------------------------------------------------------
    } elseif ($action === 'bayar_reimbursement') {
        $id = intval($body['id_pengeluaran'] ?? 0);
        $nominalBayar = floatval($body['nominal_bayar'] ?? 0);
        $tanggalBayar = $body['tanggal_bayar'] ?? date('Y-m-d');
        $metode = in_array($body['metode_pembayaran'] ?? '', ['transfer', 'tunai']) ? $body['metode_pembayaran'] : 'transfer';
        $nomorRef = trim($body['nomor_referensi'] ?? '');
        $namaPenerima = trim($body['nama_penerima'] ?? '');
        $catatan = trim($body['catatan'] ?? '');
        $bukti = trim($body['bukti_pembayaran'] ?? '');

        // Upload bukti bayar
        $uploaded = handleFileUpload('bukti_pembayaran', 'bukti_reimburse_');
        if ($uploaded) {
            $bukti = $uploaded;
        }

        if ($id <= 0 || $nominalBayar <= 0) {
            jsonResponse(false, "ID pengeluaran dan nominal pembayaran wajib valid", null, 400);
        }

        $stmtGet = $pdo->prepare("SELECT * FROM pengeluaran WHERE id_pengeluaran = ?");
        $stmtGet->execute([$id]);
        $row = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            jsonResponse(false, "Data pengeluaran tidak ditemukan", null, 404);
        }

        if ($row['jenis_dana'] !== 'pribadi') {
            jsonResponse(false, "Hanya pengeluaran uang pribadi (reimbursement) yang dapat dibayarkan lewat modul ini", null, 400);
        }

        if ($row['status_verifikasi'] !== 'disetujui') {
            jsonResponse(false, "Pengajuan reimbursement harus berstatus Disetujui terlebih dahulu sebelum dibayarkan", null, 400);
        }

        $nominalDisetujui = floatval($row['nominal_disetujui'] ?: $row['nominal']);
        $totalDibayarLama = floatval($row['total_dibayar']);
        $sisaLama = floatval($row['sisa_pembayaran']);

        if ($sisaLama <= 0.01 && $row['status_reimbursement'] === 'sudah') {
            jsonResponse(false, "Reimbursement ini sudah lunas terbayar", null, 400);
        }

        if ($nominalBayar > ($sisaLama + 0.01)) {
            jsonResponse(false, "Nominal pembayaran (Rp " . number_format($nominalBayar, 0, ',', '.') . ") melebihi sisa tagihan (Rp " . number_format($sisaLama, 0, ',', '.') . ")", null, 400);
        }

        // Catat ke tabel pembayaran_reimbursement
        $stmtPay = $pdo->prepare("
            INSERT INTO pembayaran_reimbursement (
                id_pengeluaran, tanggal_bayar, nominal_bayar, metode_pembayaran,
                nomor_referensi, nama_penerima, nama_pembayar, bukti_pembayaran, catatan
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?
            )
        ");
        $namaPembayar = $currentUser['nama'] ?? 'Administrator';
        $stmtPay->execute([
            $id, $tanggalBayar, $nominalBayar, $metode,
            $nomorRef, $namaPenerima, $namaPembayar, $bukti, $catatan
        ]);

        // Hitung ulang akumulasi pembayaran
        $stmtSum = $pdo->prepare("SELECT IFNULL(SUM(nominal_bayar), 0) FROM pembayaran_reimbursement WHERE id_pengeluaran = ?");
        $stmtSum->execute([$id]);
        $totalDibayarBaru = floatval($stmtSum->fetchColumn());
        $sisaBaru = max(0, round($nominalDisetujui - $totalDibayarBaru, 2));

        if ($sisaBaru <= 0.01) {
            $statusReimburseBaru = 'sudah';
            $statusGantiBaru = 'sudah';
            $sisaBaru = 0.00;
        } else {
            $statusReimburseBaru = 'sebagian';
            $statusGantiBaru = 'belum';
        }

        $stmtUp = $pdo->prepare("
            UPDATE pengeluaran 
            SET total_dibayar = ?,
                sisa_pembayaran = ?,
                status_reimbursement = ?,
                status_ganti = ?
            WHERE id_pengeluaran = ?
        ");
        $stmtUp->execute([$totalDibayarBaru, $sisaBaru, $statusReimburseBaru, $statusGantiBaru, $id]);

        jsonResponse(true, "Pembayaran reimbursement sebesar Rp " . number_format($nominalBayar, 0, ',', '.') . " berhasil dicatat! Status: " . ($statusReimburseBaru === 'sudah' ? 'LUNAS (Sudah Dibayar)' : 'DIBAYAR SEBAGIAN'), [
            'id_pengeluaran'       => $id,
            'nominal_bayar'        => $nominalBayar,
            'total_dibayar'        => $totalDibayarBaru,
            'sisa_pembayaran'      => $sisaBaru,
            'status_reimbursement' => $statusReimburseBaru
        ]);

    // ---------------------------------------------------------------------
    // C. PENCATATAN DANA UANG KANTOR (PEMASUKAN KAS ATAU PENYALURAN KE PJ)
    // ---------------------------------------------------------------------
    } elseif ($action === 'tambah_dana') {
        $jenis_transaksi = $body['jenis_transaksi'] ?? 'penyaluran_dana'; // 'pemasukan_kas' atau 'penyaluran_dana'
        $tanggal = $body['tanggal'] ?? date('Y-m-d');
        $sumber_dana = trim($body['sumber_dana'] ?? 'Kas Perusahaan');
        $pemberi_dana = trim($body['pemberi_dana'] ?? 'Administrator');
        $penerima_dana = trim($body['penerima_dana'] ?? '');
        $id_penanggung_jawab = !empty($body['id_penanggung_jawab']) ? intval($body['id_penanggung_jawab']) : null;
        $id_pekerjaan = !empty($body['id_pekerjaan']) ? intval($body['id_pekerjaan']) : null;
        $nominal = floatval($body['nominal'] ?? 0);
        $tujuan_penggunaan = trim($body['tujuan_penggunaan'] ?? '');
        $keterangan = trim($body['keterangan'] ?? '');
        $bukti = trim($body['bukti_transaksi'] ?? '');

        // Upload bukti jika ada
        $uploaded = handleFileUpload('bukti_transaksi', 'dana_');
        if ($uploaded) {
            $bukti = $uploaded;
        }

        if ($nominal <= 0 || empty($penerima_dana)) {
            jsonResponse(false, "Nama penerima dana dan nominal wajib diisi secara valid", null, 400);
        }

        if ($jenis_transaksi === 'penyaluran_dana' && empty($id_penanggung_jawab)) {
            jsonResponse(false, "Penyaluran dana internal wajib menentukan penanggung jawab dana", null, 400);
        }

        // Generate No. Transaksi
        $prefix = ($jenis_transaksi === 'pemasukan_kas') ? 'KAS-IN-' : 'DYN-OUT-';
        $nomorTransaksi = $prefix . date('Ymd') . '-' . bin2hex(random_bytes(2));

        $stmt = $pdo->prepare("
            INSERT INTO dana_kantor (
                nomor_transaksi, jenis_transaksi, tanggal, sumber_dana, pemberi_dana,
                penerima_dana, id_penanggung_jawab, id_pekerjaan, nominal, tujuan_penggunaan,
                keterangan, bukti_transaksi
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?
            )
        ");
        $stmt->execute([
            $nomorTransaksi, $jenis_transaksi, $tanggal, $sumber_dana, $pemberi_dana,
            $penerima_dana, $id_penanggung_jawab, $id_pekerjaan, $nominal, $tujuan_penggunaan,
            $keterangan, $bukti
        ]);

        $pesan = ($jenis_transaksi === 'pemasukan_kas') 
            ? "Penerimaan dana kas perusahaan berhasil dicatat" 
            : "Penyaluran dana kepada penanggung jawab berhasil dicatat";

        jsonResponse(true, $pesan, [
            'id_dana' => $pdo->lastInsertId(),
            'nomor_transaksi' => $nomorTransaksi
        ], 201);

    // ---------------------------------------------------------------------
    // D. PENCATATAN PENGEMBALIAN DANA UANG KANTOR
    // ---------------------------------------------------------------------
    } elseif ($action === 'tambah_pengembalian') {
        $id_dana = !empty($body['id_dana']) ? intval($body['id_dana']) : null;
        $id_karyawan = intval($body['id_karyawan'] ?? 0);
        $id_penanggung_jawab = intval($body['id_penanggung_jawab'] ?? 0);
        $tanggal = $body['tanggal'] ?? date('Y-m-d');
        $nominal = floatval($body['nominal'] ?? 0);
        $penerima = trim($body['penerima_pengembalian'] ?? 'Administrator');
        $keterangan = trim($body['keterangan'] ?? '');
        $status_verifikasi = $body['status_verifikasi'] ?? 'disetujui'; // Jika dicatat Admin langsung disetujui
        $bukti = trim($body['bukti_pengembalian'] ?? '');

        $uploaded = handleFileUpload('bukti_pengembalian', 'kembali_');
        if ($uploaded) {
            $bukti = $uploaded;
        }

        if ($id_karyawan <= 0 || $id_penanggung_jawab <= 0 || $nominal <= 0) {
            jsonResponse(false, "Karyawan, Penanggung Jawab, dan Nominal pengembalian wajib valid", null, 400);
        }

        $nomorTransaksi = 'RTN-' . date('Ymd') . '-' . bin2hex(random_bytes(2));

        $stmt = $pdo->prepare("
            INSERT INTO pengembalian_dana (
                nomor_transaksi, id_dana, id_karyawan, id_penanggung_jawab, tanggal,
                nominal, penerima_pengembalian, keterangan, bukti_pengembalian, status_verifikasi
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?
            )
        ");
        $stmt->execute([
            $nomorTransaksi, $id_dana, $id_karyawan, $id_penanggung_jawab, $tanggal,
            $nominal, $penerima, $keterangan, $bukti, $status_verifikasi
        ]);

        jsonResponse(true, "Pengembalian dana berhasil dicatat", [
            'id_pengembalian' => $pdo->lastInsertId(),
            'nomor_transaksi' => $nomorTransaksi
        ], 201);

    // ---------------------------------------------------------------------
    // E. VERIFIKASI PENGEMBALIAN DANA (SETUJUI / TOLAK)
    // ---------------------------------------------------------------------
    } elseif ($action === 'verifikasi_pengembalian') {
        $id = intval($body['id_pengembalian'] ?? 0);
        $status = $body['status_verifikasi'] ?? '';
        $catatan = trim($body['catatan'] ?? '');

        if (!in_array($status, ['disetujui', 'ditolak', 'menunggu'])) {
            jsonResponse(false, "Status verifikasi pengembalian tidak valid", null, 400);
        }

        $stmt = $pdo->prepare("UPDATE pengembalian_dana SET status_verifikasi = ?, catatan = ? WHERE id_pengembalian = ?");
        $stmt->execute([$status, $catatan ?: null, $id]);

        jsonResponse(true, "Status pengembalian dana berhasil diverifikasi ({$status})");

    // ---------------------------------------------------------------------
    // F. TAMBAH CATATAN PENGELUARAN BARU OLEH ADMIN
    // ---------------------------------------------------------------------
    } elseif ($action === 'create' || $action === 'tambah_pengeluaran') {
        $id_pekerjaan = intval($body['id_pekerjaan'] ?? 0);
        $id_karyawan = intval($body['id_karyawan'] ?? 0);
        $tanggal = !empty($body['tanggal']) ? $body['tanggal'] : date('Y-m-d');
        $nominal = floatval($body['nominal'] ?? 0);
        $kategori = trim($body['kategori'] ?? 'operasional');
        $jenis_dana = $body['jenis_dana'] ?? 'kantor';
        $id_dana = !empty($body['id_dana']) ? intval($body['id_dana']) : null;
        $id_penanggung_jawab = !empty($body['id_penanggung_jawab']) ? intval($body['id_penanggung_jawab']) : null;
        $keterangan = trim($body['keterangan'] ?? '');
        $status_verifikasi = $body['status_verifikasi'] ?? 'disetujui'; // Admin input langsung disetujui
        $foto_kwitansi = trim($body['foto_kwitansi'] ?? '');

        $uploaded = handleFileUpload('foto_kwitansi', 'nota_');
        if ($uploaded) {
            $foto_kwitansi = $uploaded;
        }

        if ($id_pekerjaan <= 0 || $id_karyawan <= 0 || $nominal <= 0) {
            jsonResponse(false, "Pekerjaan, Karyawan, dan Nominal pengeluaran wajib valid", null, 400);
        }

        if ($jenis_dana === 'kantor' && $id_dana && !$id_penanggung_jawab) {
            $stmtDana = $pdo->prepare("SELECT id_penanggung_jawab FROM dana_kantor WHERE id_dana = ?");
            $stmtDana->execute([$id_dana]);
            $id_penanggung_jawab = $stmtDana->fetchColumn() ?: null;
        }

        $nomorTransaksi = 'EXP-' . date('Ymd') . '-' . bin2hex(random_bytes(2));
        $nominalDisetujui = ($status_verifikasi === 'disetujui') ? $nominal : null;
        $statusReimburse = ($jenis_dana === 'pribadi') ? (($status_verifikasi === 'disetujui') ? 'disetujui' : 'menunggu') : null;
        $sisaBayar = ($jenis_dana === 'pribadi') ? $nominal : 0.00;

        $stmt = $pdo->prepare("
            INSERT INTO pengeluaran (
                nomor_transaksi, id_pekerjaan, id_karyawan, tanggal, nominal,
                nominal_disetujui, total_dibayar, sisa_pembayaran, kategori, jenis_dana,
                id_dana, id_penanggung_jawab, keterangan, foto_kwitansi, status_verifikasi,
                status_reimbursement, status_ganti
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, 0.00, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, 'belum'
            )
        ");
        $stmt->execute([
            $nomorTransaksi, $id_pekerjaan, $id_karyawan, $tanggal, $nominal,
            $nominalDisetujui, $sisaBayar, $kategori, $jenis_dana,
            $id_dana, $id_penanggung_jawab, $keterangan, $foto_kwitansi, $status_verifikasi,
            $statusReimburse
        ]);

        jsonResponse(true, "Pengeluaran berhasil dicatat", [
            'id_pengeluaran'  => $pdo->lastInsertId(),
            'nomor_transaksi' => $nomorTransaksi
        ], 201);

    // ---------------------------------------------------------------------
    // G. HAPUS DATA (PENGELUARAN, DANA KANTOR, PENGEMBALIAN)
    // ---------------------------------------------------------------------
    } elseif ($action === 'delete' || $action === 'hapus_pengeluaran') {
        $id = intval($_GET['id_pengeluaran'] ?? $body['id_pengeluaran'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM pengeluaran WHERE id_pengeluaran = ?");
        $stmt->execute([$id]);
        jsonResponse(true, "Pengeluaran berhasil dihapus");

    } elseif ($action === 'hapus_dana') {
        $id = intval($_GET['id_dana'] ?? $body['id_dana'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM dana_kantor WHERE id_dana = ?");
        $stmt->execute([$id]);
        jsonResponse(true, "Catatan dana kantor berhasil dihapus");

    } elseif ($action === 'hapus_pengembalian') {
        $id = intval($_GET['id_pengembalian'] ?? $body['id_pengembalian'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM pengembalian_dana WHERE id_pengembalian = ?");
        $stmt->execute([$id]);
        jsonResponse(true, "Catatan pengembalian dana berhasil dihapus");

    } else {
        jsonResponse(false, "Aksi pengeluaran admin tidak didukung", null, 400);
    }

// =========================================================================
// METHOD DELETE
// =========================================================================
} elseif ($method === 'DELETE') {
    $id = intval($_GET['id_pengeluaran'] ?? $body['id_pengeluaran'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM pengeluaran WHERE id_pengeluaran = ?");
    $stmt->execute([$id]);
    jsonResponse(true, "Catatan pengeluaran berhasil dihapus oleh Admin");
} else {
    jsonResponse(false, "Metode HTTP tidak didukung", null, 405);
}
