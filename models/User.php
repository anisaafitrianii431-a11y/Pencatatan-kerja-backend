<?php

declare(strict_types=1);

final class User
{
    private static bool $schemaChecked = false;

    public function __construct(private readonly \PDO $connection)
    {
        $this->ensureSchema();
    }

    /**
     * Memastikan skema tabel user lengkap dengan kode_user dan username berurutan.
     */
    public function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }

        try {
            $userCols = [];
            $stmt = $this->connection->query('SHOW COLUMNS FROM `user`');
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $userCols[strtolower((string) $row['Field'])] = true;
            }

            if (!isset($userCols['kode_user'])) {
                $this->connection->exec(
                    'ALTER TABLE `user` ADD COLUMN `kode_user` VARCHAR(30) NULL UNIQUE AFTER `id_user`'
                );
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

            // ID Pengguna untuk akun karyawan disamakan dengan ID Karyawan (misal ANDI001)
            $this->connection->exec(
                'UPDATE `user` u
                 JOIN `karyawan` k ON u.id_karyawan = k.id_karyawan
                 SET u.kode_user = k.kode_karyawan,
                     u.username = k.kode_karyawan
                 WHERE k.kode_karyawan IS NOT NULL AND k.kode_karyawan != ""'
            );

            // Set kode_user untuk Admin
            $this->connection->exec(
                "UPDATE `user` SET kode_user = 'ADM001' WHERE peran = 'admin' AND (kode_user IS NULL OR kode_user = '' OR kode_user LIKE 'USER%')"
            );

            // Backfill kode_user jika masih ada yang kosong
            $emptyCodes = $this->connection->query(
                'SELECT id_user FROM `user` WHERE kode_user IS NULL OR kode_user = "" ORDER BY id_user ASC'
            )->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($emptyCodes)) {
                foreach ($emptyCodes as $userId) {
                    $nextCode = self::generateNextKodeUser($this->connection);
                    $upd = $this->connection->prepare(
                        'UPDATE `user` SET kode_user = :code WHERE id_user = :id'
                    );
                    $upd->execute(['code' => $nextCode, 'id' => (int)$userId]);
                }
            }

            self::$schemaChecked = true;
        } catch (\Throwable $e) {
            error_log('User ensureSchema warning: ' . $e->getMessage());
        }
    }

    /**
     * Menghasilkan ID Pengguna otomatis dan berurutan:
     * USER001, USER002, USER003, USER004, USER005, ...
     * Mengikuti urutan tertinggi di seluruh database (termasuk yang dinonaktifkan).
     * Mencegah duplikasi meskipun diakses bersamaan.
     */
    public static function generateNextKodeUser(\PDO $connection): string
    {
        // 1. Ambil seluruh kode_user USER% yang pernah ada
        $stmt = $connection->query(
            "SELECT kode_user FROM `user` WHERE kode_user LIKE 'USER%' ORDER BY id_user DESC"
        );
        $codes = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $maxSeq = 0;
        foreach ($codes as $code) {
            if (preg_match('/^USER(\d+)$/i', (string)$code, $matches)) {
                $seq = (int)$matches[1];
                if ($seq > $maxSeq) {
                    $maxSeq = $seq;
                }
            }
        }

        // 2. Bandingkan dengan max id_user
        $maxId = (int)$connection->query("SELECT COALESCE(MAX(id_user), 0) FROM `user`")->fetchColumn();
        $startSeq = max($maxSeq, $maxId);
        $nextSeq = $startSeq + 1;

        $candidate = sprintf('USER%03d', $nextSeq);

        // 3. Verifikasi anti-tabrakan
        while (true) {
            $chk = $connection->prepare('SELECT 1 FROM `user` WHERE kode_user = :c LIMIT 1');
            $chk->execute(['c' => $candidate]);
            if ($chk->fetchColumn() === false) {
                break;
            }
            $nextSeq++;
            $candidate = sprintf('USER%03d', $nextSeq);
        }

        return $candidate;
    }

    public function getAll(): array
    {
        $this->ensureSchema();

        $statement = $this->connection->query(
            "SELECT 
                u.id_user,
                COALESCE(k.kode_karyawan, u.kode_user, CONCAT('USER', LPAD(u.id_user, 3, '0'))) AS kode_user,
                u.id_karyawan,
                COALESCE(k.kode_karyawan, u.username, u.email, CONCAT('USER', LPAD(u.id_user, 3, '0'))) AS username,
                COALESCE(NULLIF(u.email, ''), k.email, '') AS email,
                u.peran,
                u.aktif,
                COALESCE(k.nama, CASE WHEN u.peran = 'admin' THEN 'Administrator' ELSE 'Pengguna' END) AS nama,
                COALESCE(k.jabatan, CASE WHEN u.peran = 'admin' THEN 'Administrator Sistem' ELSE '-' END) AS jabatan,
                COALESCE(k.kode_karyawan, '') AS kode_karyawan,
                COALESCE(k.nomor_hp, '') AS nomor_hp,
                COALESCE(k.status, CASE WHEN u.aktif = 1 THEN 'aktif' ELSE 'nonaktif' END) AS status_karyawan
             FROM `user` u
             LEFT JOIN karyawan k ON u.id_karyawan = k.id_karyawan
             ORDER BY u.id_user ASC"
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $this->ensureSchema();

        $statement = $this->connection->prepare(
            "SELECT 
                u.id_user,
                COALESCE(k.kode_karyawan, u.kode_user, CONCAT('USER', LPAD(u.id_user, 3, '0'))) AS kode_user,
                u.id_karyawan,
                COALESCE(k.kode_karyawan, u.username, u.email, CONCAT('USER', LPAD(u.id_user, 3, '0'))) AS username,
                COALESCE(NULLIF(u.email, ''), k.email, '') AS email,
                u.peran,
                u.aktif,
                COALESCE(k.nama, CASE WHEN u.peran = 'admin' THEN 'Administrator' ELSE 'Pengguna' END) AS nama,
                COALESCE(k.jabatan, CASE WHEN u.peran = 'admin' THEN 'Administrator Sistem' ELSE '-' END) AS jabatan,
                COALESCE(k.kode_karyawan, '') AS kode_karyawan,
                COALESCE(k.nomor_hp, '') AS nomor_hp,
                COALESCE(k.status, CASE WHEN u.aktif = 1 THEN 'aktif' ELSE 'nonaktif' END) AS status_karyawan
             FROM `user` u
             LEFT JOIN karyawan k ON u.id_karyawan = k.id_karyawan
             WHERE u.id_user = :id_user
             LIMIT 1"
        );
        $statement->execute(['id_user' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function resetPassword(int $idUser, string $newPassword): array
    {
        $this->ensureSchema();

        $user = $this->findById($idUser);
        if ($user === null) {
            throw new \InvalidArgumentException('Pengguna tidak ditemukan.');
        }

        $cleanPass = trim($newPassword);
        if (strlen($cleanPass) < 6) {
            throw new \InvalidArgumentException('Kata sandi baru minimal 6 karakter.');
        }

        $hash = password_hash($cleanPass, PASSWORD_BCRYPT);

        $stmt = $this->connection->prepare(
            'UPDATE `user` SET kata_sandi = :hash WHERE id_user = :id_user'
        );
        $stmt->execute([
            'hash' => $hash,
            'id_user' => $idUser,
        ]);

        return $this->findById($idUser);
    }

    public function updateStatus(int $idUser, int $aktif): array
    {
        $this->ensureSchema();

        $user = $this->findById($idUser);
        if ($user === null) {
            throw new \InvalidArgumentException('Pengguna tidak ditemukan.');
        }

        $aktif = $aktif === 1 ? 1 : 0;

        $stmt = $this->connection->prepare(
            'UPDATE `user` SET aktif = :aktif WHERE id_user = :id_user'
        );
        $stmt->execute([
            'aktif' => $aktif,
            'id_user' => $idUser,
        ]);

        // Jika user terhubung dengan karyawan, sinkronkan status_karyawan juga
        if (!empty($user['id_karyawan'])) {
            $statusKaryawan = $aktif === 1 ? 'aktif' : 'nonaktif';
            $kStmt = $this->connection->prepare(
                'UPDATE `karyawan` SET status = :status WHERE id_karyawan = :id_karyawan'
            );
            $kStmt->execute([
                'status' => $statusKaryawan,
                'id_karyawan' => (int)$user['id_karyawan'],
            ]);
        }

        return $this->findById($idUser);
    }
}

