<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'User.php';

final class Karyawan
{
    private static bool $schemaChecked = false;

    public function __construct(private readonly \PDO $connection)
    {
        $this->ensureSchema();
    }

    /**
     * Memastikan kolom-kolom baru tersedia di tabel karyawan dan user tanpa merusak data lama.
     */
    public function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }

        try {
            $existingColumns = [];
            $colsStmt = $this->connection->query('SHOW COLUMNS FROM `karyawan`');
            while ($row = $colsStmt->fetch(\PDO::FETCH_ASSOC)) {
                $existingColumns[strtolower((string) $row['Field'])] = true;
            }

            if (!isset($existingColumns['kode_karyawan'])) {
                $this->connection->exec(
                    'ALTER TABLE `karyawan` ADD COLUMN `kode_karyawan` VARCHAR(30) NULL UNIQUE AFTER `id_karyawan`'
                );
            }

            if (!isset($existingColumns['nomor_hp'])) {
                $this->connection->exec(
                    'ALTER TABLE `karyawan` ADD COLUMN `nomor_hp` VARCHAR(20) NULL AFTER `jabatan`'
                );
            }

            if (!isset($existingColumns['email'])) {
                $this->connection->exec(
                    'ALTER TABLE `karyawan` ADD COLUMN `email` VARCHAR(100) NULL AFTER `nomor_hp`'
                );
            }

            if (!isset($existingColumns['jenis_upah'])) {
                $this->connection->exec(
                    "ALTER TABLE `karyawan` ADD COLUMN `jenis_upah` ENUM('per_jam', 'per_hari', 'per_bulan') NOT NULL DEFAULT 'per_jam' AFTER `email`"
                );
            }

            if (!isset($existingColumns['nominal_upah'])) {
                $this->connection->exec(
                    'ALTER TABLE `karyawan` ADD COLUMN `nominal_upah` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `jenis_upah`'
                );
                // Backfill nominal_upah dari tarif_per_jam yang sudah ada
                $this->connection->exec(
                    'UPDATE `karyawan` SET `nominal_upah` = `tarif_per_jam` WHERE (`nominal_upah` IS NULL OR `nominal_upah` = 0) AND `tarif_per_jam` > 0'
                );
            }

            if (!isset($existingColumns['lembur_aktif'])) {
                $this->connection->exec(
                    'ALTER TABLE `karyawan` ADD COLUMN `lembur_aktif` TINYINT(1) NOT NULL DEFAULT 0 AFTER `nominal_upah`'
                );
            }

            if (!isset($existingColumns['tarif_lembur'])) {
                $this->connection->exec(
                    'ALTER TABLE `karyawan` ADD COLUMN `tarif_lembur` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `lembur_aktif`'
                );
            }

            if (!isset($existingColumns['catatan_admin'])) {
                $this->connection->exec(
                    'ALTER TABLE `karyawan` ADD COLUMN `catatan_admin` TEXT NULL AFTER `tarif_lembur`'
                );
            }

            if (!isset($existingColumns['status'])) {
                $this->connection->exec(
                    "ALTER TABLE `karyawan` ADD COLUMN `status` ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif' AFTER `catatan_admin`"
                );
            }

            if (!isset($existingColumns['created_at'])) {
                $this->connection->exec(
                    'ALTER TABLE `karyawan` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `status`'
                );
            }

            if (!isset($existingColumns['updated_at'])) {
                $this->connection->exec(
                    'ALTER TABLE `karyawan` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'
                );
            }

            // Pastikan tabel user memiliki kolom username dan email nullable
            $userCols = [];
            $uStmt = $this->connection->query('SHOW COLUMNS FROM `user`');
            while ($uRow = $uStmt->fetch(\PDO::FETCH_ASSOC)) {
                $userCols[strtolower((string) $uRow['Field'])] = true;
            }
            if (!isset($userCols['username'])) {
                $this->connection->exec(
                    'ALTER TABLE `user` ADD COLUMN `username` VARCHAR(50) NULL UNIQUE AFTER `id_karyawan`'
                );
            }
            try {
                $this->connection->exec('ALTER TABLE `user` MODIFY COLUMN `email` VARCHAR(100) NULL');
            } catch (\Throwable) {
                // ignore
            }

            // Backfill & re-sequence data kode_karyawan agar selalu berurutan (tidak semuanya 001)
            $count001 = (int)$this->connection->query(
                "SELECT COUNT(*) FROM `karyawan` WHERE kode_karyawan LIKE '%001'"
            )->fetchColumn();

            if ($count001 > 1) {
                $all = $this->connection->query(
                    'SELECT id_karyawan, nama FROM `karyawan` ORDER BY id_karyawan ASC'
                )->fetchAll(\PDO::FETCH_ASSOC);

                $seq = 1;
                foreach ($all as $emp) {
                    $fn = trim((string)$emp['nama']);
                    if ($fn === '') $fn = 'KARYAWAN';
                    $parts = preg_split('/\s+/', $fn);
                    $clean = preg_replace('/[^a-zA-Z]/', '', $parts[0] ?? 'KARYAWAN');
                    $pfx = strtoupper($clean);
                    $pfx = strlen($pfx) < 4 ? str_pad($pfx, 4, 'X') : substr($pfx, 0, 4);
                    $code = sprintf('%s%03d', $pfx, $seq);

                    $this->connection->prepare(
                        'UPDATE `karyawan` SET kode_karyawan = :c WHERE id_karyawan = :id'
                    )->execute(['c' => $code, 'id' => (int)$emp['id_karyawan']]);

                    $this->connection->prepare(
                        'UPDATE `user` SET username = :c WHERE id_karyawan = :id'
                    )->execute(['c' => $code, 'id' => (int)$emp['id_karyawan']]);

                    $seq++;
                }
            } else {
                $emptyCodes = $this->connection->query(
                    'SELECT id_karyawan, nama FROM `karyawan` WHERE kode_karyawan IS NULL OR kode_karyawan = ""'
                )->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($emptyCodes as $k) {
                    $newCode = $this->generateKodeKaryawan((string)$k['nama']);
                    $upd = $this->connection->prepare(
                        'UPDATE `karyawan` SET kode_karyawan = :code WHERE id_karyawan = :id'
                    );
                    $upd->execute(['code' => $newCode, 'id' => (int)$k['id_karyawan']]);
                }
            }

            // Sinkronkan username dari kode_karyawan
            $this->connection->exec(
                'UPDATE `user` u
                 JOIN `karyawan` k ON u.id_karyawan = k.id_karyawan
                 SET u.username = k.kode_karyawan
                 WHERE (u.username IS NULL OR u.username = "") AND k.kode_karyawan IS NOT NULL'
            );

            self::$schemaChecked = true;
        } catch (\Throwable $e) {
            error_log('ensureSchema warning: ' . $e->getMessage());
        }
    }

    /**
     * Menghasilkan ID Karyawan otomatis:
     * - 4 huruf awal nama depan (uppercase, stripped, pad with X if < 4).
     * - Diikuti nomor urut 3 digit (001, 002, 003, ...).
     * - Memeriksa seluruh data di database (termasuk yang dinonaktifkan).
     * - Dijamin unik secara global.
     */
    public function generateKodeKaryawan(string $fullName): string
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            $fullName = 'KARYAWAN';
        }

        $parts = preg_split('/\s+/', $fullName);
        $firstName = $parts[0] ?? 'KARYAWAN';
        $clean = preg_replace('/[^a-zA-Z]/', '', $firstName);
        $prefix = strtoupper($clean);

        if (strlen($prefix) < 4) {
            $prefix = str_pad($prefix, 4, 'X');
        } else {
            $prefix = substr($prefix, 0, 4);
        }

        // Cari nomor urut tertinggi dari SELURUH kode karyawan yang pernah ada di database
        // Termasuk seluruh data karyawan aktif maupun yang dinonaktifkan
        $stmt = $this->connection->query(
            'SELECT kode_karyawan FROM `karyawan` WHERE kode_karyawan IS NOT NULL AND kode_karyawan != ""'
        );
        $existing = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $maxSeq = 0;
        foreach ($existing as $c) {
            if (preg_match('/(\d{3,})$/', (string)$c, $m)) {
                $num = (int)$m[1];
                if ($num > $maxSeq) {
                    $maxSeq = $num;
                }
            }
        }

        $nextSeq = $maxSeq + 1;
        $candidate = sprintf('%s%03d', $prefix, $nextSeq);

        // Pastikan tidak tabrakan di tabel
        while (true) {
            $check = $this->connection->prepare('SELECT 1 FROM `karyawan` WHERE kode_karyawan = :c LIMIT 1');
            $check->execute(['c' => $candidate]);
            if ($check->fetchColumn() === false) {
                break;
            }
            $nextSeq++;
            $candidate = sprintf('%s%03d', $prefix, $nextSeq);
        }

        return $candidate;
    }

    public function getAll(): array
    {
        $this->ensureSchema();

        $statement = $this->connection->query(
            "SELECT 
                k.id_karyawan,
                COALESCE(k.kode_karyawan, CONCAT('KARY', LPAD(k.id_karyawan, 3, '0'))) AS kode_karyawan,
                k.nama,
                k.jabatan,
                COALESCE(k.nomor_hp, '') AS nomor_hp,
                COALESCE(k.jenis_upah, 'per_jam') AS jenis_upah,
                COALESCE(k.nominal_upah, k.tarif_per_jam, 0) AS nominal_upah,
                COALESCE(k.lembur_aktif, 0) AS lembur_aktif,
                COALESCE(k.tarif_lembur, 0) AS tarif_lembur,
                COALESCE(k.catatan_admin, '') AS catatan_admin,
                COALESCE(k.status, 'aktif') AS status,
                k.tarif_per_jam,
                k.created_at,
                k.updated_at,
                u.id_user,
                COALESCE(u.kode_user, '') AS kode_user,
                COALESCE(u.username, k.kode_karyawan) AS username,
                u.aktif AS user_aktif
             FROM karyawan k
             LEFT JOIN `user` u ON u.id_karyawan = k.id_karyawan
             ORDER BY k.id_karyawan ASC"
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $this->ensureSchema();

        $statement = $this->connection->prepare(
            "SELECT 
                k.id_karyawan,
                COALESCE(k.kode_karyawan, CONCAT('KARY', LPAD(k.id_karyawan, 3, '0'))) AS kode_karyawan,
                k.nama,
                k.jabatan,
                COALESCE(k.nomor_hp, '') AS nomor_hp,
                COALESCE(k.jenis_upah, 'per_jam') AS jenis_upah,
                COALESCE(k.nominal_upah, k.tarif_per_jam, 0) AS nominal_upah,
                COALESCE(k.lembur_aktif, 0) AS lembur_aktif,
                COALESCE(k.tarif_lembur, 0) AS tarif_lembur,
                COALESCE(k.catatan_admin, '') AS catatan_admin,
                COALESCE(k.status, 'aktif') AS status,
                k.tarif_per_jam,
                k.created_at,
                k.updated_at,
                u.id_user,
                COALESCE(u.kode_user, '') AS kode_user,
                COALESCE(u.username, k.kode_karyawan) AS username,
                u.aktif AS user_aktif
             FROM karyawan k
             LEFT JOIN `user` u ON u.id_karyawan = k.id_karyawan
             WHERE k.id_karyawan = :id_karyawan
             LIMIT 1"
        );
        $statement->execute(['id_karyawan' => $id]);
        $employee = $statement->fetch(\PDO::FETCH_ASSOC);

        return $employee === false ? null : $employee;
    }

    public function create(
        string $name,
        ?string $position,
        ?string $phone,
        string $wageType,
        string $wageAmount,
        bool $overtimeActive,
        string $overtimeRate,
        ?string $adminNotes,
        string $status = 'aktif',
        ?string $password = null,
    ): array {
        $this->ensureSchema();

        // 1. Generate ID Karyawan Unik (e.g. ANDI001)
        $kodeKaryawan = $this->generateKodeKaryawan($name);

        // Jaga kompatibilitas dengan modul Gaji: tarif_per_jam disimpan agar perhitungan gaji lama tidak rusak
        $hourlyRate = $wageType === 'per_jam'
            ? $wageAmount
            : ($wageType === 'per_hari'
                ? number_format((float) $wageAmount / 8, 2, '.', '')
                : number_format((float) $wageAmount / 173, 2, '.', ''));

        $this->connection->beginTransaction();

        try {
            $statement = $this->connection->prepare(
                'INSERT INTO karyawan (
                    kode_karyawan,
                    nama,
                    jabatan,
                    nomor_hp,
                    jenis_upah,
                    nominal_upah,
                    lembur_aktif,
                    tarif_lembur,
                    catatan_admin,
                    status,
                    tarif_per_jam
                 ) VALUES (
                    :kode_karyawan,
                    :nama,
                    :jabatan,
                    :nomor_hp,
                    :jenis_upah,
                    :nominal_upah,
                    :lembur_aktif,
                    :tarif_lembur,
                    :catatan_admin,
                    :status,
                    :tarif_per_jam
                 )'
            );

            $statement->execute([
                'kode_karyawan' => $kodeKaryawan,
                'nama' => $name,
                'jabatan' => $position,
                'nomor_hp' => $phone,
                'jenis_upah' => $wageType,
                'nominal_upah' => $wageAmount,
                'lembur_aktif' => $overtimeActive ? 1 : 0,
                'tarif_lembur' => $overtimeRate,
                'catatan_admin' => $adminNotes,
                'status' => $status,
                'tarif_per_jam' => $hourlyRate,
            ]);

            $newId = (int) $this->connection->lastInsertId();

            // 2. Buat akun login pengguna otomatis untuk karyawan baru (mencegah akun ganda)
            $checkUser = $this->connection->prepare('SELECT id_user FROM `user` WHERE id_karyawan = :id_karyawan LIMIT 1');
            $checkUser->execute(['id_karyawan' => $newId]);
            $existingUserId = $checkUser->fetchColumn();

            if ($existingUserId === false) {
                // ID Pengguna sama dengan ID Karyawan (misal ANDI001)
                $kodeUser = $kodeKaryawan;
                $plainPass = ($password !== null && trim($password) !== '') ? trim($password) : 'karyawan123';
                $passwordHash = password_hash($plainPass, PASSWORD_BCRYPT);
                $isActive = $status === 'aktif' ? 1 : 0;

                $userStmt = $this->connection->prepare(
                    'INSERT INTO `user` (
                        kode_user,
                        id_karyawan,
                        username,
                        email,
                        kata_sandi,
                        peran,
                        aktif
                    ) VALUES (
                        :kode_user,
                        :id_karyawan,
                        :username,
                        NULL,
                        :kata_sandi,
                        "karyawan",
                        :aktif
                    )'
                );

                $userStmt->execute([
                    'kode_user' => $kodeUser,
                    'id_karyawan' => $newId,
                    'username' => $kodeKaryawan,
                    'kata_sandi' => $passwordHash,
                    'aktif' => $isActive,
                ]);
            }

            $this->connection->commit();

            $employee = $this->findById($newId);
            if ($employee === null) {
                throw new \RuntimeException('Karyawan tersimpan tetapi tidak ditemukan.');
            }

            return $employee;
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    public function update(
        int $id,
        string $name,
        ?string $position,
        ?string $phone,
        string $wageType,
        string $wageAmount,
        bool $overtimeActive,
        string $overtimeRate,
        ?string $adminNotes,
        string $status = 'aktif',
    ): ?array {
        $this->ensureSchema();

        $hourlyRate = $wageType === 'per_jam'
            ? $wageAmount
            : ($wageType === 'per_hari'
                ? number_format((float) $wageAmount / 8, 2, '.', '')
                : number_format((float) $wageAmount / 173, 2, '.', ''));

        // PENTING: kode_karyawan TIDAK diupdate agar ID tetap permanen dan tidak berubah meski nama diedit
        $statement = $this->connection->prepare(
            'UPDATE karyawan
             SET nama = :nama,
                 jabatan = :jabatan,
                 nomor_hp = :nomor_hp,
                 jenis_upah = :jenis_upah,
                 nominal_upah = :nominal_upah,
                 lembur_aktif = :lembur_aktif,
                 tarif_lembur = :tarif_lembur,
                 catatan_admin = :catatan_admin,
                 status = :status,
                 tarif_per_jam = :tarif_per_jam
             WHERE id_karyawan = :id_karyawan'
        );

        $statement->execute([
            'nama' => $name,
            'jabatan' => $position,
            'nomor_hp' => $phone,
            'jenis_upah' => $wageType,
            'nominal_upah' => $wageAmount,
            'lembur_aktif' => $overtimeActive ? 1 : 0,
            'tarif_lembur' => $overtimeRate,
            'catatan_admin' => $adminNotes,
            'status' => $status,
            'tarif_per_jam' => $hourlyRate,
            'id_karyawan' => $id,
        ]);

        // Sinkronkan status aktif pada tabel user
        $syncUserActiveStmt = $this->connection->prepare(
            'UPDATE `user` SET aktif = :aktif WHERE id_karyawan = :id_karyawan'
        );
        $syncUserActiveStmt->execute([
            'aktif' => $status === 'aktif' ? 1 : 0,
            'id_karyawan' => $id,
        ]);

        return $this->findById($id);
    }

    public function updateStatus(int $id, string $status): ?array
    {
        $this->ensureSchema();

        $stmt = $this->connection->prepare(
            'UPDATE karyawan SET status = :status WHERE id_karyawan = :id_karyawan'
        );
        $stmt->execute([
            'status' => $status,
            'id_karyawan' => $id,
        ]);

        // Sinkronkan user aktif
        $userAktif = $status === 'aktif' ? 1 : 0;
        $userStmt = $this->connection->prepare(
            'UPDATE `user` SET aktif = :aktif WHERE id_karyawan = :id_karyawan'
        );
        $userStmt->execute([
            'aktif' => $userAktif,
            'id_karyawan' => $id,
        ]);

        return $this->findById($id);
    }

    public function resetPassword(int $idKaryawan, string $newPassword): array
    {
        $this->ensureSchema();

        $employee = $this->findById($idKaryawan);
        if ($employee === null) {
            throw new \InvalidArgumentException('Data karyawan tidak ditemukan.');
        }

        $cleanPass = trim($newPassword);
        if (strlen($cleanPass) < 6) {
            throw new \InvalidArgumentException('Kata sandi baru minimal 6 karakter.');
        }

        $hash = password_hash($cleanPass, PASSWORD_BCRYPT);
        $isActive = ($employee['status'] ?? 'aktif') === 'aktif' ? 1 : 0;

        // Cek apakah user sudah ada
        $checkStmt = $this->connection->prepare(
            'SELECT id_user FROM `user` WHERE id_karyawan = :id_karyawan LIMIT 1'
        );
        $checkStmt->execute(['id_karyawan' => $idKaryawan]);
        $userId = $checkStmt->fetchColumn();

        if ($userId !== false) {
            $upd = $this->connection->prepare(
                'UPDATE `user` SET kata_sandi = :hash, aktif = :aktif WHERE id_user = :id_user'
            );
            $upd->execute([
                'hash' => $hash,
                'aktif' => $isActive,
                'id_user' => (int)$userId,
            ]);
        } else {
            $ins = $this->connection->prepare(
                'INSERT INTO `user` (id_karyawan, username, kata_sandi, peran, aktif)
                 VALUES (:id_karyawan, :username, :hash, "karyawan", :aktif)'
            );
            $ins->execute([
                'id_karyawan' => $idKaryawan,
                'username' => $employee['kode_karyawan'],
                'hash' => $hash,
                'aktif' => $isActive,
            ]);
        }

        return $this->findById($idKaryawan);
    }

    public function delete(int $id): array
    {
        $this->connection->beginTransaction();

        try {
            $employeeQuery = $this->connection->prepare(
                'SELECT id_karyawan
                 FROM karyawan
                 WHERE id_karyawan = :id_karyawan
                 FOR UPDATE',
            );
            $employeeQuery->execute(['id_karyawan' => $id]);

            if ($employeeQuery->fetchColumn() === false) {
                $this->connection->commit();
                return ['deleted' => false, 'referenced' => false];
            }

            $referenceQuery = $this->connection->prepare(
                'SELECT
                    EXISTS(SELECT 1 FROM `user` WHERE id_karyawan = :user_id) OR
                    EXISTS(SELECT 1 FROM penugasan WHERE id_karyawan = :assignment_id) OR
                    EXISTS(SELECT 1 FROM absensi WHERE id_karyawan = :attendance_id) OR
                    EXISTS(SELECT 1 FROM pengeluaran WHERE id_karyawan = :expense_id) OR
                    EXISTS(SELECT 1 FROM gaji WHERE id_karyawan = :payroll_id)
                    AS is_referenced',
            );
            $referenceQuery->execute([
                'user_id' => $id,
                'assignment_id' => $id,
                'attendance_id' => $id,
                'expense_id' => $id,
                'payroll_id' => $id,
            ]);

            if ((bool) $referenceQuery->fetchColumn()) {
                $this->connection->rollBack();
                return ['deleted' => false, 'referenced' => true];
            }

            $deleteQuery = $this->connection->prepare(
                'DELETE FROM karyawan WHERE id_karyawan = :id_karyawan',
            );
            $deleteQuery->execute(['id_karyawan' => $id]);
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
