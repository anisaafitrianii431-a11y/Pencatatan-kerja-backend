<?php

declare(strict_types=1);

final class Laporan
{
    public function __construct(private readonly \PDO $connection)
    {
    }

    public function getByJob(int $jobId): array
    {
        $stmt = $this->connection->prepare('
            SELECT l.id_laporan, l.id_pekerjaan, l.id_karyawan, l.tipe_laporan,
                   l.judul, l.keterangan, l.foto_dokumentasi, l.status_persetujuan,
                   l.catatan_admin, l.tanggal_laporan, l.created_at, l.updated_at,
                   k.nama AS nama_karyawan, k.jabatan,
                   p.nama_pekerjaan, p.status AS status_pekerjaan
            FROM laporan_pekerjaan l
            JOIN karyawan k ON k.id_karyawan = l.id_karyawan
            JOIN pekerjaan p ON p.id_pekerjaan = l.id_pekerjaan
            WHERE l.id_pekerjaan = :id_pekerjaan
            ORDER BY l.tanggal_laporan DESC, l.id_laporan DESC
        ');
        $stmt->execute(['id_pekerjaan' => $jobId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->connection->prepare('
            SELECT l.id_laporan, l.id_pekerjaan, l.id_karyawan, l.tipe_laporan,
                   l.judul, l.keterangan, l.foto_dokumentasi, l.status_persetujuan,
                   l.catatan_admin, l.tanggal_laporan, l.created_at, l.updated_at,
                   k.nama AS nama_karyawan, k.jabatan,
                   p.nama_pekerjaan, p.status AS status_pekerjaan
            FROM laporan_pekerjaan l
            JOIN karyawan k ON k.id_karyawan = l.id_karyawan
            JOIN pekerjaan p ON p.id_pekerjaan = l.id_pekerjaan
            WHERE l.id_laporan = :id_laporan
            LIMIT 1
        ');
        $stmt->execute(['id_laporan' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(
        int $jobId,
        int $employeeId,
        string $tipe = 'akhir',
        string $judul = 'Laporan Penyelesaian Pekerjaan',
        string $keterangan = '',
        ?string $foto = null,
        ?string $tanggal = null
    ): array {
        $tgl = $tanggal ?: (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $tipeSafe = in_array($tipe, ['progres', 'akhir'], true) ? $tipe : 'akhir';

        $stmt = $this->connection->prepare('
            INSERT INTO laporan_pekerjaan (
                id_pekerjaan, id_karyawan, tipe_laporan, judul, keterangan,
                foto_dokumentasi, status_persetujuan, tanggal_laporan
            ) VALUES (
                :id_pekerjaan, :id_karyawan, :tipe, :judul, :keterangan,
                :foto, "menunggu", :tanggal
            )
        ');
        $stmt->execute([
            'id_pekerjaan' => $jobId,
            'id_karyawan' => $employeeId,
            'tipe' => $tipeSafe,
            'judul' => $judul,
            'keterangan' => $keterangan,
            'foto' => $foto,
            'tanggal' => $tgl,
        ]);

        $id = (int) $this->connection->lastInsertId();
        return $this->findById($id) ?? [];
    }

    public function approve(int $id, ?string $catatan = null): array
    {
        $this->connection->beginTransaction();
        try {
            $report = $this->findById($id);
            if (!$report) {
                $this->connection->rollBack();
                return ['success' => false, 'message' => 'Laporan tidak ditemukan'];
            }

            $stmt = $this->connection->prepare('
                UPDATE laporan_pekerjaan
                SET status_persetujuan = "disetujui",
                    catatan_admin = :catatan
                WHERE id_laporan = :id
            ');
            $stmt->execute([
                'catatan' => $catatan,
                'id' => $id,
            ]);

            // Catatan: Sesuai aturan arsitektur multi-worker, persetujuan laporan seorang karyawan
            // TIDAK otomatis menyelesaikan seluruh pekerjaan. Pekerjaan diselesaikan secara terpisah oleh Admin.

            $this->connection->commit();
            $updatedReport = $this->findById($id);
            return ['success' => true, 'report' => $updatedReport];
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    public function reject(int $id, ?string $catatan = null): array
    {
        $this->connection->beginTransaction();
        try {
            $report = $this->findById($id);
            if (!$report) {
                $this->connection->rollBack();
                return ['success' => false, 'message' => 'Laporan tidak ditemukan'];
            }

            $stmt = $this->connection->prepare('
                UPDATE laporan_pekerjaan
                SET status_persetujuan = "ditolak",
                    catatan_admin = :catatan
                WHERE id_laporan = :id
            ');
            $stmt->execute([
                'catatan' => $catatan,
                'id' => $id,
            ]);

            $this->connection->commit();
            $updatedReport = $this->findById($id);
            return ['success' => true, 'report' => $updatedReport];
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->prepare('DELETE FROM laporan_pekerjaan WHERE id_laporan = :id');
        return $stmt->execute(['id' => $id]);
    }
}

