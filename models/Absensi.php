<?php

declare(strict_types=1);

final class Absensi
{
    public function __construct(private readonly \PDO $connection)
    {
    }

    public function getAll(?int $employeeId = null, ?int $jobId = null, ?string $date = null, ?string $status = null): array
    {
        $sql = 'SELECT
            a.id_absensi,
            a.id_karyawan,
            k.nama AS nama_karyawan,
            k.jabatan,
            a.id_pekerjaan,
            j.nama_pekerjaan,
            j.lokasi,
            a.tanggal,
            a.jam_masuk,
            a.jam_pulang,
            a.status_verifikasi,
            TIMESTAMPDIFF(MINUTE, CONCAT(a.tanggal, " ", a.jam_masuk), CONCAT(a.tanggal, " ", COALESCE(a.jam_pulang, a.jam_masuk))) AS durasi_menit
        FROM absensi a
        LEFT JOIN karyawan k ON k.id_karyawan = a.id_karyawan
        LEFT JOIN pekerjaan j ON j.id_pekerjaan = a.id_pekerjaan
        WHERE 1=1';

        $params = [];

        if ($employeeId !== null && $employeeId > 0) {
            $sql .= ' AND a.id_karyawan = :id_karyawan';
            $params['id_karyawan'] = $employeeId;
        }

        if ($jobId !== null && $jobId > 0) {
            $sql .= ' AND a.id_pekerjaan = :id_pekerjaan';
            $params['id_pekerjaan'] = $jobId;
        }

        if ($date !== null && $date !== '') {
            $sql .= ' AND a.tanggal = :tanggal';
            $params['tanggal'] = $date;
        }

        if ($status !== null && in_array($status, ['menunggu', 'disetujui', 'ditolak'], true)) {
            $sql .= ' AND a.status_verifikasi = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY a.tanggal DESC, a.jam_masuk DESC, a.id_absensi DESC';

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT
                a.id_absensi,
                a.id_karyawan,
                k.nama AS nama_karyawan,
                k.jabatan,
                a.id_pekerjaan,
                j.nama_pekerjaan,
                j.lokasi,
                a.tanggal,
                a.jam_masuk,
                a.jam_pulang,
                a.status_verifikasi,
                TIMESTAMPDIFF(MINUTE, CONCAT(a.tanggal, " ", a.jam_masuk), CONCAT(a.tanggal, " ", COALESCE(a.jam_pulang, a.jam_masuk))) AS durasi_menit
            FROM absensi a
            LEFT JOIN karyawan k ON k.id_karyawan = a.id_karyawan
            LEFT JOIN pekerjaan j ON j.id_pekerjaan = a.id_pekerjaan
            WHERE a.id_absensi = :id_absensi
            LIMIT 1',
        );
        $statement->execute(['id_absensi' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function updateVerificationStatus(int $id, string $status): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE absensi SET status_verifikasi = :status WHERE id_absensi = :id_absensi',
        );

        return $statement->execute([
            'status' => $status,
            'id_absensi' => $id,
        ]);
    }
}

