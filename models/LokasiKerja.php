<?php

declare(strict_types=1);

final class LokasiKerja
{
    public function __construct(private readonly \PDO $connection)
    {
        $this->ensureTable();
    }

    /**
     * Pastikan tabel lokasi_kerja ada dan memiliki foreign key ke tabel pekerjaan
     */
    private function ensureTable(): void
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
            error_log('ensureTable lokasi_kerja warning: ' . $e->getMessage());
        }
    }

    /**
     * Ambil semua lokasi kerja digabung dengan data pekerjaan yang terkait
     */
    public function getAll(): array
    {
        $statement = $this->connection->query(
            'SELECT
                p.id_pekerjaan,
                p.nama_pekerjaan,
                p.lokasi AS lokasi_pekerjaan,
                p.status,
                p.tanggal_mulai,
                p.target_selesai,
                l.id_lokasi,
                COALESCE(l.nama_lokasi, p.lokasi, p.nama_pekerjaan) AS nama_lokasi,
                l.alamat,
                l.latitude,
                l.longitude,
                COALESCE(l.radius_meter, 100) AS radius_meter,
                l.foto_lokasi,
                l.updated_at
             FROM pekerjaan p
             LEFT JOIN lokasi_kerja l ON l.id_pekerjaan = p.id_pekerjaan
             ORDER BY p.id_pekerjaan ASC',
        );

        return $statement->fetchAll();
    }

    /**
     * Ambil data lokasi untuk satu pekerjaan tertentu
     */
    public function findByPekerjaanId(int $pekerjaanId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT
                p.id_pekerjaan,
                p.nama_pekerjaan,
                p.lokasi AS lokasi_pekerjaan,
                p.status,
                l.id_lokasi,
                COALESCE(l.nama_lokasi, p.lokasi, p.nama_pekerjaan) AS nama_lokasi,
                l.alamat,
                l.latitude,
                l.longitude,
                COALESCE(l.radius_meter, 100) AS radius_meter,
                l.foto_lokasi,
                l.updated_at
             FROM pekerjaan p
             LEFT JOIN lokasi_kerja l ON l.id_pekerjaan = p.id_pekerjaan
             WHERE p.id_pekerjaan = :id_pekerjaan
             LIMIT 1',
        );
        $statement->execute(['id_pekerjaan' => $pekerjaanId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Simpan atau perbarui titik lokasi kerja yang terhubung dengan foreign key id_pekerjaan
     */
    public function save(
        int $pekerjaanId,
        ?string $namaLokasi,
        ?string $alamat,
        float $latitude,
        float $longitude,
        int $radiusMeter = 100,
        ?string $fotoLokasi = null,
    ): array {
        // Pastikan pekerjaan memang ada di tabel pekerjaan
        $check = $this->connection->prepare('SELECT id_pekerjaan, lokasi FROM pekerjaan WHERE id_pekerjaan = :id');
        $check->execute(['id' => $pekerjaanId]);
        $job = $check->fetch();
        if ($job === false) {
            throw new \InvalidArgumentException('Pekerjaan dengan ID ' . $pekerjaanId . ' tidak ditemukan.');
        }

        $nama = $namaLokasi !== null && trim($namaLokasi) !== '' ? trim($namaLokasi) : ($job['lokasi'] ?: 'Lokasi Proyek');
        $radius = max(10, min(5000, $radiusMeter));

        $statement = $this->connection->prepare('
            INSERT INTO lokasi_kerja (
                id_pekerjaan,
                nama_lokasi,
                alamat,
                latitude,
                longitude,
                radius_meter,
                foto_lokasi
            ) VALUES (
                :id_pekerjaan,
                :nama_lokasi,
                :alamat,
                :latitude,
                :longitude,
                :radius_meter,
                :foto_lokasi
            ) ON DUPLICATE KEY UPDATE
                nama_lokasi = VALUES(nama_lokasi),
                alamat = VALUES(alamat),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                radius_meter = VALUES(radius_meter),
                foto_lokasi = COALESCE(VALUES(foto_lokasi), foto_lokasi),
                updated_at = CURRENT_TIMESTAMP
        ');

        $statement->execute([
            'id_pekerjaan' => $pekerjaanId,
            'nama_lokasi' => $nama,
            'alamat' => $alamat,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_meter' => $radius,
            'foto_lokasi' => $fotoLokasi,
        ]);

        // Juga perbarui kolom lokasi di tabel pekerjaan agar tetap sinkron jika nama_lokasi berubah
        if ($nama !== '') {
            try {
                $upd = $this->connection->prepare('UPDATE pekerjaan SET lokasi = :lokasi WHERE id_pekerjaan = :id');
                $upd->execute(['lokasi' => $nama, 'id' => $pekerjaanId]);
            } catch (\Throwable) {
                // Abaikan jika tidak perlu
            }
        }

        $result = $this->findByPekerjaanId($pekerjaanId);
        if ($result === null) {
            throw new \RuntimeException('Lokasi berhasil disimpan tetapi gagal dimuat ulang.');
        }

        return $result;
    }

    /**
     * Hapus titik lokasi kerja berdasarkan id_pekerjaan
     */
    public function delete(int $pekerjaanId): bool
    {
        $statement = $this->connection->prepare('DELETE FROM lokasi_kerja WHERE id_pekerjaan = :id_pekerjaan');
        return $statement->execute(['id_pekerjaan' => $pekerjaanId]);
    }
}

