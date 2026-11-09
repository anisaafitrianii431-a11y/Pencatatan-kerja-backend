<?php

declare(strict_types=1);

final class Penugasan
{
    public function __construct(private readonly \PDO $connection)
    {
    }

    public function getAll(): array
    {
        $statement = $this->connection->query(
            'SELECT
                p.id_penugasan,
                p.id_karyawan,
                k.nama AS nama_karyawan,
                k.jabatan,
                p.id_pekerjaan,
                j.nama_pekerjaan,
                j.lokasi,
                j.tanggal_mulai,
                j.target_selesai,
                j.status AS status_pekerjaan,
                j.metode_penentuan,
                p.jenis_penugasan,
                p.tanggal_penugasan
             FROM penugasan p
             INNER JOIN karyawan k ON k.id_karyawan = p.id_karyawan
             INNER JOIN pekerjaan j ON j.id_pekerjaan = p.id_pekerjaan
             ORDER BY p.id_penugasan DESC',
        );

        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT
                p.id_penugasan,
                p.id_karyawan,
                k.nama AS nama_karyawan,
                k.jabatan,
                p.id_pekerjaan,
                j.nama_pekerjaan,
                j.lokasi,
                j.tanggal_mulai,
                j.target_selesai,
                j.status AS status_pekerjaan,
                j.metode_penentuan,
                p.jenis_penugasan,
                p.tanggal_penugasan
             FROM penugasan p
             INNER JOIN karyawan k ON k.id_karyawan = p.id_karyawan
             INNER JOIN pekerjaan j ON j.id_pekerjaan = p.id_pekerjaan
             WHERE p.id_penugasan = :id_penugasan
             LIMIT 1',
        );
        $statement->execute(['id_penugasan' => $id]);
        $assignment = $statement->fetch();

        return $assignment === false ? null : $assignment;
    }

    public function employeeExists(int $employeeId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM karyawan WHERE id_karyawan = :id_karyawan LIMIT 1',
        );
        $statement->execute(['id_karyawan' => $employeeId]);

        return $statement->fetchColumn() !== false;
    }

    public function jobExists(int $jobId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM pekerjaan WHERE id_pekerjaan = :id_pekerjaan LIMIT 1',
        );
        $statement->execute(['id_pekerjaan' => $jobId]);

        return $statement->fetchColumn() !== false;
    }

    public function isDuplicate(
        int $employeeId,
        int $jobId,
        ?int $excludeAssignmentId = null,
    ): bool {
        $sql = 'SELECT 1
                FROM penugasan
                WHERE id_karyawan = :id_karyawan
                  AND id_pekerjaan = :id_pekerjaan';
        $parameters = [
            'id_karyawan' => $employeeId,
            'id_pekerjaan' => $jobId,
        ];

        if ($excludeAssignmentId !== null) {
            $sql .= ' AND id_penugasan <> :id_penugasan';
            $parameters['id_penugasan'] = $excludeAssignmentId;
        }

        $sql .= ' LIMIT 1';
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public function create(int $employeeId, int $jobId): array
    {
        $this->connection->beginTransaction();

        try {
            if (!$this->lockReferences($employeeId, $jobId)) {
                $this->connection->rollBack();
                return ['status' => 'reference_not_found', 'data' => null];
            }

            if ($this->isDuplicate($employeeId, $jobId)) {
                $this->connection->rollBack();
                return ['status' => 'duplicate', 'data' => null];
            }

            $insert = $this->connection->prepare(
                'INSERT INTO penugasan (id_karyawan, id_pekerjaan)
                 VALUES (:id_karyawan, :id_pekerjaan)',
            );
            $insert->execute([
                'id_karyawan' => $employeeId,
                'id_pekerjaan' => $jobId,
            ]);

            $assignment = $this->findById((int) $this->connection->lastInsertId());
            if ($assignment === null) {
                throw new \RuntimeException('Penugasan tersimpan tetapi tidak ditemukan.');
            }

            $this->connection->commit();
            return ['status' => 'created', 'data' => $assignment];
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function update(int $id, int $employeeId, int $jobId): array
    {
        $this->connection->beginTransaction();

        try {
            $assignmentLock = $this->connection->prepare(
                'SELECT id_penugasan
                 FROM penugasan
                 WHERE id_penugasan = :id_penugasan
                 FOR UPDATE',
            );
            $assignmentLock->execute(['id_penugasan' => $id]);

            if ($assignmentLock->fetchColumn() === false) {
                $this->connection->commit();
                return ['status' => 'not_found', 'data' => null];
            }

            if (!$this->lockReferences($employeeId, $jobId)) {
                $this->connection->rollBack();
                return ['status' => 'reference_not_found', 'data' => null];
            }

            if ($this->isDuplicate($employeeId, $jobId, $id)) {
                $this->connection->rollBack();
                return ['status' => 'duplicate', 'data' => null];
            }

            $update = $this->connection->prepare(
                'UPDATE penugasan
                 SET id_karyawan = :id_karyawan,
                     id_pekerjaan = :id_pekerjaan
                 WHERE id_penugasan = :id_penugasan',
            );
            $update->execute([
                'id_karyawan' => $employeeId,
                'id_pekerjaan' => $jobId,
                'id_penugasan' => $id,
            ]);

            $assignment = $this->findById($id);
            if ($assignment === null) {
                throw new \RuntimeException('Penugasan diperbarui tetapi tidak ditemukan.');
            }

            $this->connection->commit();
            return ['status' => 'updated', 'data' => $assignment];
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function delete(int $id): array
    {
        $stmt = $this->connection->prepare(
            'SELECT id_pekerjaan, id_karyawan FROM penugasan WHERE id_penugasan = :id',
        );
        $stmt->execute(['id' => $id]);
        $assignment = $stmt->fetch();

        if ($assignment === false) {
            return ['deleted' => false, 'referenced' => false, 'not_found' => true];
        }

        $jobId = (int) $assignment['id_pekerjaan'];
        $employeeId = (int) $assignment['id_karyawan'];

        // Periksa relasi absensi
        $absensiStmt = $this->connection->prepare(
            'SELECT COUNT(*) FROM absensi WHERE id_pekerjaan = :jobId AND id_karyawan = :empId',
        );
        $absensiStmt->execute(['jobId' => $jobId, 'empId' => $employeeId]);
        $absensiCount = (int) $absensiStmt->fetchColumn();

        // Periksa relasi pengeluaran
        $pengeluaranStmt = $this->connection->prepare(
            'SELECT COUNT(*) FROM pengeluaran WHERE id_pekerjaan = :jobId AND id_karyawan = :empId',
        );
        $pengeluaranStmt->execute(['jobId' => $jobId, 'empId' => $employeeId]);
        $pengeluaranCount = (int) $pengeluaranStmt->fetchColumn();

        if ($absensiCount > 0 || $pengeluaranCount > 0) {
            return [
                'deleted' => false,
                'referenced' => true,
                'not_found' => false,
                'message' => "Penugasan tidak dapat dibatalkan karena pekerja sudah memiliki {$absensiCount} catatan absensi dan {$pengeluaranCount} catatan pengeluaran pada pekerjaan ini.",
            ];
        }

        $delStmt = $this->connection->prepare(
            'DELETE FROM penugasan WHERE id_penugasan = :id',
        );
        $delStmt->execute(['id' => $id]);

        return ['deleted' => true, 'referenced' => false, 'not_found' => false];
    }

    private function lockReferences(int $employeeId, int $jobId): bool
    {
        $employee = $this->connection->prepare(
            'SELECT id_karyawan
             FROM karyawan
             WHERE id_karyawan = :id_karyawan
             FOR UPDATE',
        );
        $employee->execute(['id_karyawan' => $employeeId]);
        $employeeExists = $employee->fetchColumn() !== false;

        $job = $this->connection->prepare(
            'SELECT id_pekerjaan
             FROM pekerjaan
             WHERE id_pekerjaan = :id_pekerjaan
             FOR UPDATE',
        );
        $job->execute(['id_pekerjaan' => $jobId]);
        $jobExists = $job->fetchColumn() !== false;

        return $employeeExists && $jobExists;
    }
}
