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
            a.foto_masuk,
            a.masuk_latitude,
            a.masuk_longitude,
            a.masuk_alamat,
            a.foto_pulang,
            a.pulang_latitude,
            a.pulang_longitude,
            a.pulang_alamat,
            a.jarak_meter,
            l.radius_meter,
            l.latitude AS job_latitude,
            l.longitude AS job_longitude,
            TIMESTAMPDIFF(MINUTE, CONCAT(a.tanggal, " ", a.jam_masuk), CONCAT(a.tanggal, " ", COALESCE(a.jam_pulang, a.jam_masuk))) AS durasi_menit
        FROM absensi a
        LEFT JOIN karyawan k ON k.id_karyawan = a.id_karyawan
        LEFT JOIN pekerjaan j ON j.id_pekerjaan = a.id_pekerjaan
        LEFT JOIN lokasi_kerja l ON l.id_pekerjaan = a.id_pekerjaan
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
                a.foto_masuk,
                a.masuk_latitude,
                a.masuk_longitude,
                a.masuk_alamat,
                a.foto_pulang,
                a.pulang_latitude,
                a.pulang_longitude,
                a.pulang_alamat,
                a.jarak_meter,
                l.radius_meter,
                l.latitude AS job_latitude,
                l.longitude AS job_longitude,
                TIMESTAMPDIFF(MINUTE, CONCAT(a.tanggal, " ", a.jam_masuk), CONCAT(a.tanggal, " ", COALESCE(a.jam_pulang, a.jam_masuk))) AS durasi_menit
            FROM absensi a
            LEFT JOIN karyawan k ON k.id_karyawan = a.id_karyawan
            LEFT JOIN pekerjaan j ON j.id_pekerjaan = a.id_pekerjaan
            LEFT JOIN lokasi_kerja l ON l.id_pekerjaan = a.id_pekerjaan
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

    public function create(
        int $karyawanId,
        int $pekerjaanId,
        string $tanggal,
        string $jamMasuk,
        string $status = 'menunggu',
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $alamat = null,
        ?string $fotoMasuk = null,
        ?int $jarakMeter = null
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO absensi (
                id_karyawan,
                id_pekerjaan,
                tanggal,
                jam_masuk,
                status_verifikasi,
                masuk_latitude,
                masuk_longitude,
                masuk_alamat,
                foto_masuk,
                jarak_meter
             ) VALUES (
                :id_karyawan,
                :id_pekerjaan,
                :tanggal,
                :jam_masuk,
                :status,
                :latitude,
                :longitude,
                :alamat,
                :foto_masuk,
                :jarak_meter
             )'
        );

        $statement->execute([
            'id_karyawan' => $karyawanId,
            'id_pekerjaan' => $pekerjaanId,
            'tanggal' => $tanggal,
            'jam_masuk' => $jamMasuk,
            'status' => $status,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'alamat' => $alamat,
            'foto_masuk' => $fotoMasuk,
            'jarak_meter' => $jarakMeter,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function checkout(
        int $id,
        string $jamPulang,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $alamat = null,
        ?string $fotoPulang = null
    ): bool {
        $statement = $this->connection->prepare(
            'UPDATE absensi
             SET jam_pulang = :jam_pulang,
                 pulang_latitude = COALESCE(:latitude, pulang_latitude),
                 pulang_longitude = COALESCE(:longitude, pulang_longitude),
                 pulang_alamat = COALESCE(:alamat, pulang_alamat),
                 foto_pulang = COALESCE(:foto_pulang, foto_pulang)
             WHERE id_absensi = :id_absensi'
        );

        return $statement->execute([
            'jam_pulang' => $jamPulang,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'alamat' => $alamat,
            'foto_pulang' => $fotoPulang,
            'id_absensi' => $id,
        ]);
    }
}

