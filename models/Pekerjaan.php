<?php

declare(strict_types=1);

final class Pekerjaan
{
    public function __construct(private readonly \PDO $connection)
    {
        $this->ensureLokasiTable();
        $this->ensureLaporanAndPenugasanColumns();
        $this->ensureMultiWorkerColumns();
    }

    private function ensureLokasiTable(): void
    {
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS `lokasi_kerja` (
                    `id_lokasi` INT AUTO_INCREMENT PRIMARY KEY,
                    `id_pekerjaan` INT NOT NULL UNIQUE,
                    `nama_lokasi` VARCHAR(150) NULL,
                    `alamat` TEXT NULL,
                    `latitude` DECIMAL(10,8) NOT NULL,
                    `longitude` DECIMAL(11,8) NOT NULL,
                    `radius_meter` INT DEFAULT 100,
                    `foto_lokasi` LONGTEXT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT `fk_lokasi_pekerjaan`
                        FOREIGN KEY (`id_pekerjaan`) REFERENCES `pekerjaan` (`id_pekerjaan`)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {
            error_log('ensureLokasiTable warning: ' . $e->getMessage());
        }
    }

    private function ensureLaporanAndPenugasanColumns(): void
    {
        try {
            $cols = $this->connection->query("SHOW COLUMNS FROM penugasan LIKE 'status_karyawan'")->fetchAll();
            if (empty($cols)) {
                $this->connection->exec("
                    ALTER TABLE `penugasan`
                    ADD COLUMN `status_karyawan` ENUM('belum_hadir', 'sedang_bekerja', 'selesai_bertugas', 'tidak_hadir', 'meninggalkan_pekerjaan') NOT NULL DEFAULT 'belum_hadir' AFTER `jenis_penugasan`,
                    ADD COLUMN `catatan_admin` VARCHAR(255) NULL AFTER `status_karyawan`,
                    ADD COLUMN `status_konfirmasi_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `catatan_admin`;
                ");
            }

            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS `laporan_pekerjaan` (
                    `id_laporan` INT AUTO_INCREMENT PRIMARY KEY,
                    `id_pekerjaan` INT NOT NULL,
                    `id_karyawan` INT NOT NULL,
                    `tipe_laporan` ENUM('progres', 'akhir') NOT NULL DEFAULT 'akhir',
                    `judul` VARCHAR(200) NOT NULL,
                    `keterangan` TEXT NOT NULL,
                    `foto_dokumentasi` LONGTEXT NULL,
                    `status_persetujuan` ENUM('menunggu', 'disetujui', 'ditolak') NOT NULL DEFAULT 'menunggu',
                    `catatan_admin` TEXT NULL,
                    `tanggal_laporan` DATE NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT `fk_laporan_pekerjaan`
                        FOREIGN KEY (`id_pekerjaan`) REFERENCES `pekerjaan` (`id_pekerjaan`)
                        ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `fk_laporan_karyawan`
                        FOREIGN KEY (`id_karyawan`) REFERENCES `karyawan` (`id_karyawan`)
                        ON DELETE RESTRICT ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {
            error_log('ensureLaporanAndPenugasanColumns warning: ' . $e->getMessage());
        }
    }

    private function ensureMultiWorkerColumns(): void
    {
        try {
            // Pastikan metode_penentuan mendukung ENUM('langsung', 'terbuka', 'gabungan')
            $this->connection->exec("
                ALTER TABLE `pekerjaan` 
                MODIFY COLUMN `metode_penentuan` ENUM('langsung', 'terbuka', 'gabungan') NOT NULL DEFAULT 'terbuka'
            ");

            // Tambah kuota_pekerja & status_pengambilan pada pekerjaan jika belum ada
            $colsP = $this->connection->query("SHOW COLUMNS FROM `pekerjaan`")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('kuota_pekerja', $colsP, true)) {
                $this->connection->exec("ALTER TABLE `pekerjaan` ADD COLUMN `kuota_pekerja` INT NULL DEFAULT NULL AFTER `metode_penentuan`");
            }
            if (!in_array('status_pengambilan', $colsP, true)) {
                $this->connection->exec("ALTER TABLE `pekerjaan` ADD COLUMN `status_pengambilan` ENUM('terbuka', 'ditutup') NOT NULL DEFAULT 'terbuka' AFTER `kuota_pekerja`");
            }

            // Tambah status_penugasan & kolom pembatalan pada penugasan jika belum ada
            $colsT = $this->connection->query("SHOW COLUMNS FROM `penugasan`")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('status_penugasan', $colsT, true)) {
                $this->connection->exec("ALTER TABLE `penugasan` ADD COLUMN `status_penugasan` ENUM('aktif', 'dibatalkan') NOT NULL DEFAULT 'aktif' AFTER `jenis_penugasan`");
            }
            if (!in_array('alasan_batal', $colsT, true)) {
                $this->connection->exec("ALTER TABLE `penugasan` ADD COLUMN `alasan_batal` VARCHAR(255) NULL AFTER `catatan_admin`");
            }
            if (!in_array('dibatalkan_pada', $colsT, true)) {
                $this->connection->exec("ALTER TABLE `penugasan` ADD COLUMN `dibatalkan_pada` DATETIME NULL AFTER `alasan_batal`");
            }
            if (!in_array('dibatalkan_oleh', $colsT, true)) {
                $this->connection->exec("ALTER TABLE `penugasan` ADD COLUMN `dibatalkan_oleh` VARCHAR(100) NULL AFTER `dibatalkan_pada`");
            }
        } catch (\Throwable $e) {
            error_log('ensureMultiWorkerColumns warning: ' . $e->getMessage());
        }
    }

    public function getAll(): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');

        try {
            $statement = $this->connection->query(
                'SELECT p.id_pekerjaan, p.nama_pekerjaan, p.deskripsi, p.lokasi,
                        p.tanggal_mulai, p.target_selesai, p.status, p.metode_penentuan,
                        p.kuota_pekerja, p.status_pengambilan,
                        l.id_lokasi, l.latitude, l.longitude, l.radius_meter, l.foto_lokasi, l.alamat,
                        COALESCE(l.nama_lokasi, p.lokasi) AS nama_lokasi,
                        GROUP_CONCAT(DISTINCT CASE WHEN COALESCE(t.status_penugasan, "aktif") = "aktif" THEN k.id_karyawan END) AS id_karyawan_list,
                        GROUP_CONCAT(DISTINCT CASE WHEN COALESCE(t.status_penugasan, "aktif") = "aktif" THEN k.nama END SEPARATOR ", ") AS nama_karyawan_list,
                        COUNT(DISTINCT CASE WHEN COALESCE(t.status_penugasan, "aktif") = "aktif" THEN t.id_penugasan END) AS total_pekerja,
                        COUNT(DISTINCT CASE WHEN COALESCE(t.status_penugasan, "aktif") = "aktif" AND t.jenis_penugasan = "admin" THEN t.id_penugasan END) AS total_pekerja_admin,
                        COUNT(DISTINCT CASE WHEN COALESCE(t.status_penugasan, "aktif") = "aktif" AND t.jenis_penugasan = "mandiri" THEN t.id_penugasan END) AS total_pekerja_mandiri,
                        COUNT(DISTINCT CASE WHEN t.status_penugasan = "dibatalkan" THEN t.id_penugasan END) AS total_pekerja_dibatalkan,
                        (SELECT COUNT(*) FROM laporan_pekerjaan lp WHERE lp.id_pekerjaan = p.id_pekerjaan AND lp.status_persetujuan = "menunggu") AS total_laporan_menunggu,
                        (SELECT COUNT(*) FROM absensi ab WHERE ab.id_pekerjaan = p.id_pekerjaan) AS total_absensi
                 FROM pekerjaan p
                 LEFT JOIN penugasan t ON t.id_pekerjaan = p.id_pekerjaan
                 LEFT JOIN karyawan k ON k.id_karyawan = t.id_karyawan
                 LEFT JOIN lokasi_kerja l ON l.id_pekerjaan = p.id_pekerjaan
                 GROUP BY p.id_pekerjaan, l.id_lokasi, l.latitude, l.longitude, l.radius_meter, l.foto_lokasi, l.alamat, l.nama_lokasi
                 ORDER BY p.id_pekerjaan ASC',
            );

            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $statement = $this->connection->query(
                'SELECT p.id_pekerjaan, p.nama_pekerjaan, p.deskripsi, p.lokasi,
                        p.tanggal_mulai, p.target_selesai, p.status, p.metode_penentuan,
                        p.kuota_pekerja, p.status_pengambilan,
                        GROUP_CONCAT(DISTINCT CASE WHEN COALESCE(t.status_penugasan, "aktif") = "aktif" THEN k.id_karyawan END) AS id_karyawan_list,
                        GROUP_CONCAT(DISTINCT CASE WHEN COALESCE(t.status_penugasan, "aktif") = "aktif" THEN k.nama END SEPARATOR ", ") AS nama_karyawan_list,
                        COUNT(DISTINCT CASE WHEN COALESCE(t.status_penugasan, "aktif") = "aktif" THEN t.id_penugasan END) AS total_pekerja
                 FROM pekerjaan p
                 LEFT JOIN penugasan t ON t.id_pekerjaan = p.id_pekerjaan
                 LEFT JOIN karyawan k ON k.id_karyawan = t.id_karyawan
                 GROUP BY p.id_pekerjaan
                 ORDER BY p.id_pekerjaan ASC',
            );

            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        }

        foreach ($rows as &$row) {
            $target = $row['target_selesai'] ?? null;
            $start = $row['tanggal_mulai'] ?? null;
            $status = $row['status'] ?? 'belum_mulai';

            $kuota = ($row['kuota_pekerja'] !== null && $row['kuota_pekerja'] !== '' && (int) $row['kuota_pekerja'] > 0)
                ? (int) $row['kuota_pekerja']
                : null;
            $totalPekerja = (int) ($row['total_pekerja'] ?? 0);

            $row['kuota_pekerja'] = $kuota;
            $row['total_pekerja'] = $totalPekerja;
            $row['total_pekerja_aktif'] = $totalPekerja;
            $row['status_pengambilan'] = $row['status_pengambilan'] ?? 'terbuka';
            $row['is_unlimited_kuota'] = ($kuota === null);
            $row['sisa_slot'] = ($kuota === null) ? null : max(0, $kuota - $totalPekerja);
            $row['is_kuota_penuh'] = ($kuota !== null && $totalPekerja >= $kuota);

            // Evaluasi keterlambatan jika belum selesai dan target terlewati
            $isOverdue = ($target !== null && $target !== '' && $target < $today && $status !== 'selesai');
            $row['is_overdue'] = $isOverdue;

            // Evaluasi otomatis status jika tanggal mulai belum tiba
            if ($start !== null && $start !== '' && $start > $today && $status !== 'selesai') {
                $row['belum_tiba'] = true;
            } else {
                $row['belum_tiba'] = false;
            }
        }

        return $rows;
    }

    public function getAvailableJobs(?int $forEmployeeId = null): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        
        $query = '
            SELECT p.id_pekerjaan, p.nama_pekerjaan, p.deskripsi, p.lokasi,
                   p.tanggal_mulai, p.target_selesai, p.status, p.metode_penentuan,
                   p.kuota_pekerja, p.status_pengambilan,
                   l.latitude, l.longitude, l.radius_meter,
                   (
                       SELECT COUNT(*) 
                       FROM penugasan t 
                       WHERE t.id_pekerjaan = p.id_pekerjaan AND COALESCE(t.status_penugasan, "aktif") = "aktif"
                   ) AS total_pekerja,
                   (
                       SELECT COUNT(*) 
                       FROM penugasan t 
                       WHERE t.id_pekerjaan = p.id_pekerjaan 
                         AND t.id_karyawan = :empId 
                         AND COALESCE(t.status_penugasan, "aktif") = "aktif"
                   ) AS already_joined
            FROM pekerjaan p
            LEFT JOIN lokasi_kerja l ON l.id_pekerjaan = p.id_pekerjaan
            WHERE p.metode_penentuan IN ("terbuka", "gabungan")
              AND p.status <> :selesai
              AND COALESCE(p.status_pengambilan, "terbuka") = "terbuka"
              AND (p.target_selesai IS NULL OR p.target_selesai >= :today)
            ORDER BY p.id_pekerjaan DESC
        ';

        $statement = $this->connection->prepare($query);
        $statement->execute([
            'empId' => $forEmployeeId ?? 0,
            'selesai' => 'selesai',
            'today' => $today,
        ]);

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $kuota = ($row['kuota_pekerja'] !== null && $row['kuota_pekerja'] !== '' && (int) $row['kuota_pekerja'] > 0)
                ? (int) $row['kuota_pekerja']
                : null;
            $totalPekerja = (int) ($row['total_pekerja'] ?? 0);
            $alreadyJoined = ((int) ($row['already_joined'] ?? 0)) > 0;

            $row['kuota_pekerja'] = $kuota;
            $row['total_pekerja'] = $totalPekerja;
            $row['total_pekerja_aktif'] = $totalPekerja;
            $row['is_unlimited_kuota'] = ($kuota === null);
            $row['sisa_slot'] = ($kuota === null) ? null : max(0, $kuota - $totalPekerja);
            $row['is_kuota_penuh'] = ($kuota !== null && $totalPekerja >= $kuota);
            $row['already_joined'] = $alreadyJoined;

            $results[] = $row;
        }

        return $results;
    }

    public function findById(int $id): ?array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');

        try {
            $statement = $this->connection->prepare(
                'SELECT p.id_pekerjaan, p.nama_pekerjaan, p.deskripsi, p.lokasi,
                        p.tanggal_mulai, p.target_selesai, p.status, p.metode_penentuan,
                        p.kuota_pekerja, p.status_pengambilan,
                        l.id_lokasi, l.latitude, l.longitude, l.radius_meter, l.foto_lokasi, l.alamat,
                        COALESCE(l.nama_lokasi, p.lokasi) AS nama_lokasi
                 FROM pekerjaan p
                 LEFT JOIN lokasi_kerja l ON l.id_pekerjaan = p.id_pekerjaan
                 WHERE p.id_pekerjaan = :id_pekerjaan
                 LIMIT 1',
            );
            $statement->execute(['id_pekerjaan' => $id]);
            $job = $statement->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $statement = $this->connection->prepare(
                'SELECT p.id_pekerjaan, p.nama_pekerjaan, p.deskripsi, p.lokasi,
                        p.tanggal_mulai, p.target_selesai, p.status, p.metode_penentuan,
                        p.kuota_pekerja, p.status_pengambilan
                 FROM pekerjaan p
                 WHERE p.id_pekerjaan = :id_pekerjaan
                 LIMIT 1',
            );
            $statement->execute(['id_pekerjaan' => $id]);
            $job = $statement->fetch(\PDO::FETCH_ASSOC);
        }

        if ($job === false || !$job) {
            return null;
        }

        $target = $job['target_selesai'] ?? null;
        $job['is_overdue'] = ($target !== null && $target !== '' && $target < $today && $job['status'] !== 'selesai');

        // Ambil daftar seluruh pekerja yang ditugaskan beserta status kehadiran & penugasan individual
        $workers = $this->getWorkersWithStatus($id);
        $job['assigned_workers'] = $workers;
        $job['workers'] = $workers;

        // Hitung kuota dan pekerja aktif
        $activeWorkers = array_filter($workers, static fn($w) => ($w['status_penugasan'] ?? 'aktif') === 'aktif');
        $totalActive = count($activeWorkers);
        $kuota = ($job['kuota_pekerja'] !== null && $job['kuota_pekerja'] !== '' && (int) $job['kuota_pekerja'] > 0)
            ? (int) $job['kuota_pekerja']
            : null;

        $job['kuota_pekerja'] = $kuota;
        $job['total_pekerja'] = $totalActive;
        $job['total_pekerja_aktif'] = $totalActive;
        $job['total_pekerja_dibatalkan'] = count($workers) - $totalActive;
        $job['status_pengambilan'] = $job['status_pengambilan'] ?? 'terbuka';
        $job['is_unlimited_kuota'] = ($kuota === null);
        $job['sisa_slot'] = ($kuota === null) ? null : max(0, $kuota - $totalActive);
        $job['is_kuota_penuh'] = ($kuota !== null && $totalActive >= $kuota);

        // Ambil riwayat laporan pekerjaan
        $laporanStmt = $this->connection->prepare(
            'SELECT l.id_laporan, l.id_pekerjaan, l.id_karyawan, l.tipe_laporan,
                    l.judul, l.keterangan, l.foto_dokumentasi, l.status_persetujuan,
                    l.catatan_admin, l.tanggal_laporan, l.created_at,
                    k.nama AS nama_karyawan, k.jabatan
             FROM laporan_pekerjaan l
             JOIN karyawan k ON k.id_karyawan = l.id_karyawan
             WHERE l.id_pekerjaan = :id_pekerjaan
             ORDER BY l.tanggal_laporan DESC, l.id_laporan DESC',
        );
        $laporanStmt->execute(['id_pekerjaan' => $id]);
        $job['laporan_list'] = $laporanStmt->fetchAll(\PDO::FETCH_ASSOC);

        return $job;
    }

    public function getWorkersWithStatus(int $jobId): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');

        $query = '
            SELECT t.id_penugasan, t.id_pekerjaan, t.id_karyawan, t.jenis_penugasan, t.tanggal_penugasan,
                   COALESCE(t.status_penugasan, "aktif") AS status_penugasan,
                   t.alasan_batal, t.dibatalkan_pada, t.dibatalkan_oleh,
                   t.status_karyawan, t.catatan_admin, t.status_konfirmasi_admin,
                   k.nama, k.nama AS nama_karyawan, k.jabatan, k.nomor_hp,
                   a.id_absensi, a.tanggal AS tanggal_absensi, a.jam_masuk, a.jam_pulang, a.status_verifikasi,
                   TIMESTAMPDIFF(MINUTE, CONCAT(a.tanggal, " ", a.jam_masuk), CONCAT(a.tanggal, " ", COALESCE(a.jam_pulang, a.jam_masuk))) AS durasi_menit,
                   (
                       SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, CONCAT(ab.tanggal, " ", ab.jam_masuk), CONCAT(ab.tanggal, " ", COALESCE(ab.jam_pulang, ab.jam_masuk)))), 0)
                       FROM absensi ab
                       WHERE ab.id_pekerjaan = t.id_pekerjaan AND ab.id_karyawan = t.id_karyawan AND ab.status_verifikasi = "disetujui"
                   ) AS total_durasi_menit_disetujui
            FROM penugasan t
            JOIN karyawan k ON k.id_karyawan = t.id_karyawan
            LEFT JOIN (
                SELECT a1.*
                FROM absensi a1
                INNER JOIN (
                    SELECT id_karyawan, id_pekerjaan, MAX(id_absensi) AS max_id
                    FROM absensi
                    WHERE id_pekerjaan = :jobIdSub AND tanggal = :today
                    GROUP BY id_karyawan, id_pekerjaan
                ) a2 ON a1.id_absensi = a2.max_id
            ) a ON a.id_karyawan = t.id_karyawan AND a.id_pekerjaan = t.id_pekerjaan
            WHERE t.id_pekerjaan = :jobId
            ORDER BY (COALESCE(t.status_penugasan, "aktif") = "aktif") DESC, t.id_penugasan ASC
        ';

        $stmt = $this->connection->prepare($query);
        $stmt->execute([
            'jobIdSub' => $jobId,
            'jobId' => $jobId,
            'today' => $today,
        ]);

        $workers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($workers as &$w) {
            $isCanceled = ($w['status_penugasan'] ?? 'aktif') === 'dibatalkan';

            if ($isCanceled) {
                $w['computed_status'] = 'dibatalkan';
            } else {
                $hasConfirmed = (int) ($w['status_konfirmasi_admin'] ?? 0) === 1;

                if ($hasConfirmed) {
                    $w['computed_status'] = $w['status_karyawan'] ?? 'tidak_hadir';
                } else {
                    if (!empty($w['jam_masuk'])) {
                        if (!empty($w['jam_pulang'])) {
                            $w['computed_status'] = 'selesai_bertugas';
                        } else {
                            $w['computed_status'] = 'sedang_bekerja';
                        }
                    } else {
                        $w['computed_status'] = 'belum_hadir';
                    }
                }
            }

            // Format durasi
            $menitHariIni = (int) ($w['durasi_menit'] ?? 0);
            if ($menitHariIni < 0) $menitHariIni = 0;
            $w['durasi_format'] = intdiv($menitHariIni, 60) . 'j ' . ($menitHariIni % 60) . 'm';

            $totalMenit = (int) ($w['total_durasi_menit_disetujui'] ?? 0);
            $w['total_durasi_format'] = intdiv($totalMenit, 60) . 'j ' . ($totalMenit % 60) . 'm';
        }

        return $workers;
    }

    public function updateWorkerStatus(
        int $jobId,
        int $employeeId,
        string $status,
        ?string $catatan = null
    ): bool {
        $allowed = ['belum_hadir', 'sedang_bekerja', 'selesai_bertugas', 'tidak_hadir', 'meninggalkan_pekerjaan', 'otomatis'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        if ($status === 'otomatis') {
            $stmt = $this->connection->prepare('
                UPDATE penugasan
                SET status_konfirmasi_admin = 0,
                    catatan_admin = NULL
                WHERE id_pekerjaan = :jobId AND id_karyawan = :empId
            ');
            return $stmt->execute([
                'jobId' => $jobId,
                'empId' => $employeeId,
            ]);
        }

        $stmt = $this->connection->prepare('
            UPDATE penugasan
            SET status_karyawan = :status,
                status_konfirmasi_admin = 1,
                catatan_admin = :catatan
            WHERE id_pekerjaan = :jobId AND id_karyawan = :empId
        ');

        return $stmt->execute([
            'status' => $status,
            'catatan' => $catatan,
            'jobId' => $jobId,
            'empId' => $employeeId,
        ]);
    }

    public function autoUpdateJobStatusFromAttendance(int $jobId): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');

        try {
            $jobStmt = $this->connection->prepare('
                SELECT status, tanggal_mulai 
                FROM pekerjaan 
                WHERE id_pekerjaan = :jobId
            ');
            $jobStmt->execute(['jobId' => $jobId]);
            $job = $jobStmt->fetch(\PDO::FETCH_ASSOC);

            if ($job && $job['status'] === 'belum_mulai') {
                $absStmt = $this->connection->prepare('
                    SELECT COUNT(*) 
                    FROM absensi 
                    WHERE id_pekerjaan = :jobId AND jam_masuk IS NOT NULL
                ');
                $absStmt->execute(['jobId' => $jobId]);
                $hasCheckin = (int) $absStmt->fetchColumn() > 0;

                if ($hasCheckin) {
                    $start = $job['tanggal_mulai'];
                    if ($start === null || $start === '' || $start <= $today) {
                        $upd = $this->connection->prepare('
                            UPDATE pekerjaan 
                            SET status = "berjalan" 
                            WHERE id_pekerjaan = :jobId AND status = "belum_mulai"
                        ');
                        $upd->execute(['jobId' => $jobId]);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('autoUpdateJobStatusFromAttendance error: ' . $e->getMessage());
        }
    }

    public function create(
        string $name,
        ?string $description,
        ?string $location,
        ?string $startDate,
        ?string $targetDate,
        string $status = 'belum_mulai',
        string $assignmentMethod = 'terbuka',
        array|int|null $employeeIds = null,
        ?float $latitude = null,
        ?float $longitude = null,
        int $radiusMeter = 100,
        ?int $kuotaPekerja = null,
        string $statusPengambilan = 'terbuka',
    ): array {
        $initialStatus = 'belum_mulai';

        // Jika metode langsung, otomatis tutup pendaftaran umum
        if ($assignmentMethod === 'langsung') {
            $statusPengambilan = 'ditutup';
        }

        $this->connection->beginTransaction();

        try {
            $statement = $this->connection->prepare(
                'INSERT INTO pekerjaan (
                    nama_pekerjaan,
                    deskripsi,
                    lokasi,
                    tanggal_mulai,
                    target_selesai,
                    status,
                    metode_penentuan,
                    kuota_pekerja,
                    status_pengambilan
                 ) VALUES (
                    :nama_pekerjaan,
                    :deskripsi,
                    :lokasi,
                    :tanggal_mulai,
                    :target_selesai,
                    :status,
                    :metode_penentuan,
                    :kuota_pekerja,
                    :status_pengambilan
                 )',
            );
            $statement->execute([
                'nama_pekerjaan' => $name,
                'deskripsi' => $description,
                'lokasi' => $location,
                'tanggal_mulai' => $startDate,
                'target_selesai' => $targetDate,
                'status' => $initialStatus,
                'metode_penentuan' => $assignmentMethod,
                'kuota_pekerja' => $kuotaPekerja,
                'status_pengambilan' => $statusPengambilan,
            ]);

            $jobId = (int) $this->connection->lastInsertId();

            // Tugaskan karyawan yang dipilih oleh Admin (untuk metode 'langsung' atau 'gabungan')
            if (in_array($assignmentMethod, ['langsung', 'gabungan'], true) && !empty($employeeIds)) {
                $empList = is_array($employeeIds) ? $employeeIds : [$employeeIds];
                $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
                $assignStmt = $this->connection->prepare(
                    'INSERT INTO penugasan (id_pekerjaan, id_karyawan, jenis_penugasan, tanggal_penugasan, status_karyawan, status_penugasan)
                     VALUES (:id_pekerjaan, :id_karyawan, "admin", :tanggal, "belum_hadir", "aktif")',
                );
                foreach ($empList as $empId) {
                    $assignStmt->execute([
                        'id_pekerjaan' => $jobId,
                        'id_karyawan' => (int) $empId,
                        'tanggal' => $today,
                    ]);
                }
            }

            // Simpan koordinat lokasi jika ada
            if ($latitude !== null && $longitude !== null) {
                try {
                    $insertLokasi = $this->connection->prepare('
                        INSERT INTO lokasi_kerja (id_pekerjaan, nama_lokasi, latitude, longitude, radius_meter)
                        VALUES (:id_pekerjaan, :nama_lokasi, :latitude, :longitude, :radius_meter)
                        ON DUPLICATE KEY UPDATE
                            nama_lokasi = VALUES(nama_lokasi),
                            latitude = VALUES(latitude),
                            longitude = VALUES(longitude),
                            radius_meter = VALUES(radius_meter)
                    ');
                    $insertLokasi->execute([
                        'id_pekerjaan' => $jobId,
                        'nama_lokasi' => $location ?: $name,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'radius_meter' => max(10, min(5000, $radiusMeter)),
                    ]);
                } catch (\Throwable $e) {
                    error_log('Insert lokasi_kerja on create warning: ' . $e->getMessage());
                }
            }

            $this->connection->commit();

            $job = $this->findById($jobId);
            if ($job === null) {
                throw new \RuntimeException('Pekerjaan tersimpan tetapi tidak ditemukan.');
            }

            return $job;
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function update(
        int $id,
        string $name,
        ?string $description,
        ?string $location,
        ?string $startDate,
        ?string $targetDate,
        ?string $status = null,
        string $assignmentMethod = 'terbuka',
        array|int|null $employeeIds = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusMeter = null,
        ?int $kuotaPekerja = null,
        ?string $statusPengambilan = null,
    ): ?array {
        $this->connection->beginTransaction();

        try {
            $currentJobStmt = $this->connection->prepare('SELECT status, status_pengambilan FROM pekerjaan WHERE id_pekerjaan = :id FOR UPDATE');
            $currentJobStmt->execute(['id' => $id]);
            $currentJob = $currentJobStmt->fetch(\PDO::FETCH_ASSOC);

            if ($currentJob === false) {
                $this->connection->rollBack();
                return null;
            }

            $finalStatus = ($status !== null && in_array($status, ['belum_mulai', 'berjalan', 'selesai'], true))
                ? $status
                : (string) $currentJob['status'];

            $finalStatusPengambilan = $statusPengambilan ?? ($currentJob['status_pengambilan'] ?? 'terbuka');
            if ($assignmentMethod === 'langsung') {
                $finalStatusPengambilan = 'ditutup';
            }

            $statement = $this->connection->prepare(
                'UPDATE pekerjaan
                 SET nama_pekerjaan = :nama_pekerjaan,
                     deskripsi = :deskripsi,
                     lokasi = :lokasi,
                     tanggal_mulai = :tanggal_mulai,
                     target_selesai = :target_selesai,
                     status = :status,
                     metode_penentuan = :metode_penentuan,
                     kuota_pekerja = :kuota_pekerja,
                     status_pengambilan = :status_pengambilan
                 WHERE id_pekerjaan = :id_pekerjaan',
            );
            $statement->execute([
                'nama_pekerjaan' => $name,
                'deskripsi' => $description,
                'lokasi' => $location,
                'tanggal_mulai' => $startDate,
                'target_selesai' => $targetDate,
                'status' => $finalStatus,
                'metode_penentuan' => $assignmentMethod,
                'kuota_pekerja' => $kuotaPekerja,
                'status_pengambilan' => $finalStatusPengambilan,
                'id_pekerjaan' => $id,
            ]);

            // Simpan / update lokasi_kerja
            if ($latitude !== null && $longitude !== null) {
                try {
                    $updLokasi = $this->connection->prepare('
                        INSERT INTO lokasi_kerja (id_pekerjaan, nama_lokasi, latitude, longitude, radius_meter)
                        VALUES (:id_pekerjaan, :nama_lokasi, :latitude, :longitude, :radius_meter)
                        ON DUPLICATE KEY UPDATE
                            nama_lokasi = VALUES(nama_lokasi),
                            latitude = VALUES(latitude),
                            longitude = VALUES(longitude),
                            radius_meter = VALUES(radius_meter)
                    ');
                    $updLokasi->execute([
                        'id_pekerjaan' => $id,
                        'nama_lokasi' => $location ?: $name,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'radius_meter' => max(10, min(5000, $radiusMeter ?? 100)),
                    ]);
                } catch (\Throwable $e) {
                    error_log('Update lokasi_kerja warning: ' . $e->getMessage());
                }
            }

            // Sinkronisasi penugasan oleh Admin (untuk metode langsung & gabungan)
            if (in_array($assignmentMethod, ['langsung', 'gabungan'], true) && $employeeIds !== null) {
                $empList = is_array($employeeIds) ? array_map('intval', $employeeIds) : [(int) $employeeIds];
                $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');

                // Ambil penugasan admin saat ini
                $currentStmt = $this->connection->prepare(
                    'SELECT id_karyawan, status_penugasan, id_penugasan 
                     FROM penugasan 
                     WHERE id_pekerjaan = :id_pekerjaan AND jenis_penugasan = "admin"',
                );
                $currentStmt->execute(['id_pekerjaan' => $id]);
                $currentAdminAssigns = $currentStmt->fetchAll(\PDO::FETCH_ASSOC);
                $currentAdminEmpIds = array_map(static fn($r) => (int) $r['id_karyawan'], $currentAdminAssigns);

                // Tambah atau aktifkan kembali karyawan yang dipilih
                $insertAssign = $this->connection->prepare(
                    'INSERT INTO penugasan (id_pekerjaan, id_karyawan, jenis_penugasan, tanggal_penugasan, status_karyawan, status_penugasan)
                     VALUES (:id_pekerjaan, :id_karyawan, "admin", :tanggal, "belum_hadir", "aktif")',
                );
                $reactivateAssign = $this->connection->prepare(
                    'UPDATE penugasan 
                     SET status_penugasan = "aktif", 
                         jenis_penugasan = "admin",
                         alasan_batal = NULL,
                         dibatalkan_pada = NULL,
                         dibatalkan_oleh = NULL
                     WHERE id_penugasan = :id_penugasan'
                );

                foreach ($empList as $empIdInt) {
                    $foundAssign = null;
                    foreach ($currentAdminAssigns as $caa) {
                        if ((int) $caa['id_karyawan'] === $empIdInt) {
                            $foundAssign = $caa;
                            break;
                        }
                    }

                    if ($foundAssign) {
                        if (($foundAssign['status_penugasan'] ?? 'aktif') === 'dibatalkan') {
                            $reactivateAssign->execute(['id_penugasan' => $foundAssign['id_penugasan']]);
                        }
                    } else {
                        // Periksa apakah sebelumnya mengambil mandiri
                        $chkMandiri = $this->connection->prepare(
                            'SELECT id_penugasan FROM penugasan WHERE id_pekerjaan = :jId AND id_karyawan = :eId'
                        );
                        $chkMandiri->execute(['jId' => $id, 'eId' => $empIdInt]);
                        $mRow = $chkMandiri->fetch(\PDO::FETCH_ASSOC);

                        if ($mRow) {
                            $reactivateAssign->execute(['id_penugasan' => $mRow['id_penugasan']]);
                        } else {
                            $insertAssign->execute([
                                'id_pekerjaan' => $id,
                                'id_karyawan' => $empIdInt,
                                'tanggal' => $today,
                            ]);
                        }
                    }
                }

                // Untuk karyawan admin yang tidak lagi dicentang
                $cancelAssign = $this->connection->prepare(
                    'UPDATE penugasan 
                     SET status_penugasan = "dibatalkan", 
                         alasan_batal = "Dikeluarkan dari daftar penugasan admin",
                         dibatalkan_pada = NOW(),
                         dibatalkan_oleh = "admin"
                     WHERE id_pekerjaan = :id_pekerjaan AND id_karyawan = :id_karyawan AND jenis_penugasan = "admin"',
                );
                $removeAssign = $this->connection->prepare(
                    'DELETE FROM penugasan WHERE id_pekerjaan = :id_pekerjaan AND id_karyawan = :id_karyawan AND jenis_penugasan = "admin"',
                );

                foreach ($currentAdminAssigns as $caa) {
                    $currentIdInt = (int) $caa['id_karyawan'];
                    if (!in_array($currentIdInt, $empList, true)) {
                        $chkAbs = $this->connection->prepare(
                            'SELECT COUNT(*) FROM absensi WHERE id_pekerjaan = :jobId AND id_karyawan = :empId',
                        );
                        $chkAbs->execute(['jobId' => $id, 'empId' => $currentIdInt]);
                        $hasAbs = (int) $chkAbs->fetchColumn() > 0;

                        $chkExp = $this->connection->prepare(
                            'SELECT COUNT(*) FROM pengeluaran WHERE id_pekerjaan = :jobId AND id_karyawan = :empId',
                        );
                        $chkExp->execute(['jobId' => $id, 'empId' => $currentIdInt]);
                        $hasExp = (int) $chkExp->fetchColumn() > 0;

                        if (!$hasAbs && !$hasExp) {
                            $removeAssign->execute([
                                'id_pekerjaan' => $id,
                                'id_karyawan' => $currentIdInt,
                            ]);
                        } else {
                            $cancelAssign->execute([
                                'id_pekerjaan' => $id,
                                'id_karyawan' => $currentIdInt,
                            ]);
                        }
                    }
                }
            }

            $this->connection->commit();

            // Pemicu evaluasi otomatis status jika ada absensi
            $this->autoUpdateJobStatusFromAttendance($id);

            return $this->findById($id);
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function claimJob(int $jobId, int $employeeId): array
    {
        $this->connection->beginTransaction();

        try {
            // Lock baris pekerjaan untuk mencegah race conditions saat mengambil slot kuota
            $jobStmt = $this->connection->prepare(
                'SELECT id_pekerjaan, nama_pekerjaan, status, metode_penentuan, target_selesai, kuota_pekerja, status_pengambilan
                 FROM pekerjaan
                 WHERE id_pekerjaan = :id_pekerjaan
                 FOR UPDATE',
            );
            $jobStmt->execute(['id_pekerjaan' => $jobId]);
            $job = $jobStmt->fetch(\PDO::FETCH_ASSOC);

            if ($job === false || !$job) {
                $this->connection->rollBack();
                return ['status' => 'not_found', 'message' => 'Data pekerjaan tidak ditemukan'];
            }

            if ($job['status'] === 'selesai') {
                $this->connection->rollBack();
                return ['status' => 'completed', 'message' => 'Pekerjaan ini sudah selesai dan tidak dapat diambil'];
            }

            if (!in_array($job['metode_penentuan'], ['terbuka', 'gabungan'], true)) {
                $this->connection->rollBack();
                return ['status' => 'not_open', 'message' => 'Pekerjaan ini ditentukan langsung oleh Admin dan tidak dibuka untuk diambil mandiri'];
            }

            if (($job['status_pengambilan'] ?? 'terbuka') === 'ditutup') {
                $this->connection->rollBack();
                return ['status' => 'closed', 'message' => 'Pengambilan pekerjaan ini telah ditutup oleh Admin'];
            }

            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            if ($job['target_selesai'] !== null && $job['target_selesai'] !== '' && $job['target_selesai'] < $today) {
                $this->connection->rollBack();
                return [
                    'status' => 'expired',
                    'message' => 'Pekerjaan telah melewati batas tenggat (deadline) dan tidak dapat diambil',
                ];
            }

            // Validasi keberadaan & status aktif karyawan
            $empStmt = $this->connection->prepare(
                'SELECT id_karyawan, nama, status FROM karyawan WHERE id_karyawan = :id_karyawan FOR UPDATE',
            );
            $empStmt->execute(['id_karyawan' => $employeeId]);
            $employee = $empStmt->fetch(\PDO::FETCH_ASSOC);

            if ($employee === false || !$employee) {
                $this->connection->rollBack();
                return ['status' => 'employee_not_found', 'message' => 'Data karyawan tidak ditemukan'];
            }

            if (($employee['status'] ?? 'aktif') === 'nonaktif') {
                $this->connection->rollBack();
                return ['status' => 'employee_inactive', 'message' => 'Akun karyawan berstatus nonaktif'];
            }

            // Periksa apakah karyawan sudah terdaftar dalam penugasan aktif
            $assignStmt = $this->connection->prepare(
                'SELECT id_penugasan, status_penugasan, jenis_penugasan 
                 FROM penugasan 
                 WHERE id_pekerjaan = :id_pekerjaan AND id_karyawan = :id_karyawan 
                 FOR UPDATE',
            );
            $assignStmt->execute([
                'id_pekerjaan' => $jobId,
                'id_karyawan' => $employeeId,
            ]);
            $existing = $assignStmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing !== false && ($existing['status_penugasan'] ?? 'aktif') === 'aktif') {
                $this->connection->rollBack();
                $sumber = ($existing['jenis_penugasan'] === 'admin') ? 'penugasan oleh Admin' : 'pengambilan mandiri';
                return [
                    'status' => 'already_claimed_by_you',
                    'message' => "Anda sudah terdaftar aktif pada pekerjaan ini melalui {$sumber}.",
                ];
            }

            // Hitung seluruh penugasan aktif saat ini
            $countStmt = $this->connection->prepare(
                'SELECT COUNT(*) FROM penugasan 
                 WHERE id_pekerjaan = :id_pekerjaan AND COALESCE(status_penugasan, "aktif") = "aktif" 
                 FOR UPDATE'
            );
            $countStmt->execute(['id_pekerjaan' => $jobId]);
            $currentActiveCount = (int) $countStmt->fetchColumn();

            $kuota = ($job['kuota_pekerja'] !== null && $job['kuota_pekerja'] !== '' && (int) $job['kuota_pekerja'] > 0)
                ? (int) $job['kuota_pekerja']
                : null;

            if ($kuota !== null && $currentActiveCount >= $kuota) {
                $this->connection->rollBack();
                return [
                    'status' => 'quota_full',
                    'message' => "Kuota pekerja untuk pekerjaan ini sudah penuh ({$currentActiveCount}/{$kuota} orang).",
                ];
            }

            // Tambahkan atau aktifkan kembali
            if ($existing !== false) {
                $upd = $this->connection->prepare('
                    UPDATE penugasan
                    SET status_penugasan = "aktif",
                        jenis_penugasan = "mandiri",
                        tanggal_penugasan = :tanggal,
                        status_karyawan = "belum_hadir",
                        alasan_batal = NULL,
                        dibatalkan_pada = NULL,
                        dibatalkan_oleh = NULL
                    WHERE id_penugasan = :id_penugasan
                ');
                $upd->execute([
                    'tanggal' => $today,
                    'id_penugasan' => $existing['id_penugasan'],
                ]);
                $assignmentId = (int) $existing['id_penugasan'];
            } else {
                $insert = $this->connection->prepare(
                    'INSERT INTO penugasan (id_pekerjaan, id_karyawan, jenis_penugasan, tanggal_penugasan, status_karyawan, status_penugasan)
                     VALUES (:id_pekerjaan, :id_karyawan, "mandiri", :tanggal, "belum_hadir", "aktif")',
                );
                $insert->execute([
                    'id_pekerjaan' => $jobId,
                    'id_karyawan' => $employeeId,
                    'tanggal' => $today,
                ]);
                $assignmentId = (int) $this->connection->lastInsertId();
            }

            $this->connection->commit();

            return [
                'status' => 'success',
                'message' => 'Pekerjaan berhasil diambil dan telah masuk ke Pekerjaan Saya.',
                'data' => [
                    'id_penugasan' => $assignmentId,
                    'id_pekerjaan' => $jobId,
                    'id_karyawan' => $employeeId,
                    'nama_karyawan' => $employee['nama'],
                    'nama_pekerjaan' => $job['nama_pekerjaan'],
                    'jenis_penugasan' => 'mandiri',
                    'total_pekerja_sekarang' => $currentActiveCount + 1,
                    'kuota_pekerja' => $kuota,
                ],
            ];
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function cancelAssignment(int $jobId, int $assignmentId, ?string $reason = null, string $by = 'admin'): array
    {
        $this->connection->beginTransaction();
        try {
            $stmt = $this->connection->prepare(
                'SELECT t.id_penugasan, t.id_pekerjaan, t.id_karyawan, t.status_penugasan, k.nama AS nama_karyawan
                 FROM penugasan t
                 JOIN karyawan k ON k.id_karyawan = t.id_karyawan
                 WHERE t.id_penugasan = :id_penugasan AND t.id_pekerjaan = :id_pekerjaan
                 FOR UPDATE'
            );
            $stmt->execute([
                'id_penugasan' => $assignmentId,
                'id_pekerjaan' => $jobId,
            ]);
            $assign = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$assign) {
                $this->connection->rollBack();
                return ['success' => false, 'message' => 'Data penugasan tidak ditemukan pada pekerjaan ini'];
            }

            if (($assign['status_penugasan'] ?? 'aktif') === 'dibatalkan') {
                $this->connection->rollBack();
                return ['success' => false, 'message' => 'Penugasan karyawan ini sudah dalam status dibatalkan sebelumnya'];
            }

            $alasan = $reason ?: 'Dibatalkan oleh Administrator';

            $upd = $this->connection->prepare('
                UPDATE penugasan
                SET status_penugasan = "dibatalkan",
                    alasan_batal = :alasan,
                    dibatalkan_pada = NOW(),
                    dibatalkan_oleh = :by
                WHERE id_penugasan = :id_penugasan
            ');
            $upd->execute([
                'alasan' => $alasan,
                'by' => $by,
                'id_penugasan' => $assignmentId,
            ]);

            $this->connection->commit();

            return [
                'success' => true,
                'message' => "Penugasan {$assign['nama_karyawan']} berhasil dibatalkan. Slot kuota kini tersedia kembali.",
                'assignment' => $assign,
            ];
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    public function addWorkerToJob(int $jobId, int $employeeId): array
    {
        $this->connection->beginTransaction();
        try {
            $jobStmt = $this->connection->prepare(
                'SELECT id_pekerjaan, kuota_pekerja, status FROM pekerjaan WHERE id_pekerjaan = :id FOR UPDATE'
            );
            $jobStmt->execute(['id' => $jobId]);
            $job = $jobStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$job) {
                $this->connection->rollBack();
                return ['success' => false, 'message' => 'Pekerjaan tidak ditemukan'];
            }

            // Periksa keberadaan karyawan
            $empStmt = $this->connection->prepare('SELECT id_karyawan, nama FROM karyawan WHERE id_karyawan = :id');
            $empStmt->execute(['id' => $employeeId]);
            $emp = $empStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$emp) {
                $this->connection->rollBack();
                return ['success' => false, 'message' => 'Data karyawan tidak ditemukan'];
            }

            // Periksa penugasan aktif
            $chk = $this->connection->prepare(
                'SELECT id_penugasan, status_penugasan FROM penugasan WHERE id_pekerjaan = :jobId AND id_karyawan = :empId FOR UPDATE'
            );
            $chk->execute(['jobId' => $jobId, 'empId' => $employeeId]);
            $existing = $chk->fetch(\PDO::FETCH_ASSOC);

            if ($existing && ($existing['status_penugasan'] ?? 'aktif') === 'aktif') {
                $this->connection->rollBack();
                return ['success' => false, 'message' => "Karyawan {$emp['nama']} sudah terdaftar aktif pada pekerjaan ini"];
            }

            // Periksa kuota
            $countStmt = $this->connection->prepare(
                'SELECT COUNT(*) FROM penugasan WHERE id_pekerjaan = :id AND COALESCE(status_penugasan, "aktif") = "aktif" FOR UPDATE'
            );
            $countStmt->execute(['id' => $jobId]);
            $activeCount = (int) $countStmt->fetchColumn();

            $kuota = ($job['kuota_pekerja'] !== null && (int) $job['kuota_pekerja'] > 0) ? (int) $job['kuota_pekerja'] : null;
            if ($kuota !== null && $activeCount >= $kuota) {
                $this->connection->rollBack();
                return ['success' => false, 'message' => "Kuota pekerja sudah penuh ({$activeCount}/{$kuota} orang)."];
            }

            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            if ($existing) {
                $upd = $this->connection->prepare('
                    UPDATE penugasan
                    SET status_penugasan = "aktif",
                        jenis_penugasan = "admin",
                        tanggal_penugasan = :today,
                        status_karyawan = "belum_hadir",
                        alasan_batal = NULL,
                        dibatalkan_pada = NULL,
                        dibatalkan_oleh = NULL
                    WHERE id_penugasan = :id
                ');
                $upd->execute(['today' => $today, 'id' => $existing['id_penugasan']]);
                $assignId = (int) $existing['id_penugasan'];
            } else {
                $ins = $this->connection->prepare('
                    INSERT INTO penugasan (id_pekerjaan, id_karyawan, jenis_penugasan, tanggal_penugasan, status_karyawan, status_penugasan)
                    VALUES (:jobId, :empId, "admin", :today, "belum_hadir", "aktif")
                ');
                $ins->execute([
                    'jobId' => $jobId,
                    'empId' => $employeeId,
                    'today' => $today,
                ]);
                $assignId = (int) $this->connection->lastInsertId();
            }

            $this->connection->commit();
            return ['success' => true, 'message' => "Karyawan {$emp['nama']} berhasil ditugaskan ke pekerjaan", 'id_penugasan' => $assignId];
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    public function toggleStatusPengambilan(int $jobId, ?string $status = null): array
    {
        $job = $this->findById($jobId);
        if (!$job) {
            return ['success' => false, 'message' => 'Pekerjaan tidak ditemukan'];
        }

        $newStatus = $status;
        if ($newStatus === null) {
            $newStatus = ($job['status_pengambilan'] ?? 'terbuka') === 'terbuka' ? 'ditutup' : 'terbuka';
        }

        $stmt = $this->connection->prepare('
            UPDATE pekerjaan
            SET status_pengambilan = :status
            WHERE id_pekerjaan = :id
        ');
        $stmt->execute(['status' => $newStatus, 'id' => $jobId]);

        return [
            'success' => true,
            'message' => 'Status pendaftaran pekerja diubah menjadi: ' . ($newStatus === 'terbuka' ? 'Terbuka' : 'Ditutup'),
            'status_pengambilan' => $newStatus,
        ];
    }

    public function hasReferences(int $id): bool
    {
        $referenceQuery = $this->connection->prepare(
            'SELECT
                EXISTS(
                    SELECT 1 FROM penugasan
                    WHERE id_pekerjaan = :assignment_id
                ) OR
                EXISTS(
                    SELECT 1 FROM absensi
                    WHERE id_pekerjaan = :attendance_id
                ) OR
                EXISTS(
                    SELECT 1 FROM pengeluaran
                    WHERE id_pekerjaan = :expense_id
                ) OR
                EXISTS(
                    SELECT 1 FROM laporan_pekerjaan
                    WHERE id_pekerjaan = :report_id
                ) AS is_referenced',
        );
        $referenceQuery->execute([
            'assignment_id' => $id,
            'attendance_id' => $id,
            'expense_id' => $id,
            'report_id' => $id,
        ]);

        return (bool) $referenceQuery->fetchColumn();
    }

    public function delete(int $id): array
    {
        $this->connection->beginTransaction();

        try {
            $jobQuery = $this->connection->prepare(
                'SELECT id_pekerjaan
                 FROM pekerjaan
                 WHERE id_pekerjaan = :id_pekerjaan
                 FOR UPDATE',
            );
            $jobQuery->execute(['id_pekerjaan' => $id]);

            if ($jobQuery->fetchColumn() === false) {
                $this->connection->commit();
                return ['deleted' => false, 'referenced' => false];
            }

            if ($this->hasReferences($id)) {
                $this->connection->rollBack();
                return ['deleted' => false, 'referenced' => true];
            }

            $deleteQuery = $this->connection->prepare(
                'DELETE FROM pekerjaan WHERE id_pekerjaan = :id_pekerjaan',
            );
            $deleteQuery->execute(['id_pekerjaan' => $id]);
            $deleted = $deleteQuery->rowCount() === 1;
            $this->connection->commit();

            return ['deleted' => $deleted, 'referenced' => false];
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
