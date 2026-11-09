<?php

declare(strict_types=1);

final class Karyawan
{
    public function __construct(private readonly \PDO $connection)
    {
    }

    public function getAll(): array
    {
        $statement = $this->connection->query(
            'SELECT id_karyawan, nama, jabatan, tarif_per_jam
             FROM karyawan
             ORDER BY id_karyawan ASC',
        );

        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id_karyawan, nama, jabatan, tarif_per_jam
             FROM karyawan
             WHERE id_karyawan = :id_karyawan
             LIMIT 1',
        );
        $statement->execute(['id_karyawan' => $id]);
        $employee = $statement->fetch();

        return $employee === false ? null : $employee;
    }

    public function create(
        string $name,
        ?string $position,
        string $hourlyRate,
    ): array {
        $statement = $this->connection->prepare(
            'INSERT INTO karyawan (nama, jabatan, tarif_per_jam)
             VALUES (:nama, :jabatan, :tarif_per_jam)',
        );
        $statement->execute([
            'nama' => $name,
            'jabatan' => $position,
            'tarif_per_jam' => $hourlyRate,
        ]);

        $employee = $this->findById((int) $this->connection->lastInsertId());
        if ($employee === null) {
            throw new \RuntimeException('Karyawan tersimpan tetapi tidak ditemukan.');
        }

        return $employee;
    }

    public function update(
        int $id,
        string $name,
        ?string $position,
        string $hourlyRate,
    ): ?array {
        $statement = $this->connection->prepare(
            'UPDATE karyawan
             SET nama = :nama,
                 jabatan = :jabatan,
                 tarif_per_jam = :tarif_per_jam
             WHERE id_karyawan = :id_karyawan',
        );
        $statement->execute([
            'nama' => $name,
            'jabatan' => $position,
            'tarif_per_jam' => $hourlyRate,
            'id_karyawan' => $id,
        ]);

        return $this->findById($id);
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
