<?php

declare(strict_types=1);

final class Pekerjaan
{
    public function __construct(private readonly \PDO $connection)
    {
    }

    public function getAll(): array
    {
        $statement = $this->connection->query(
            'SELECT p.id_pekerjaan, p.nama_pekerjaan, p.deskripsi, p.lokasi,
                    p.tanggal_mulai, p.target_selesai, p.status, p.metode_penentuan,
                    GROUP_CONCAT(k.id_karyawan) AS id_karyawan_list,
                    GROUP_CONCAT(k.nama SEPARATOR ", ") AS nama_karyawan_list,
                    COUNT(t.id_penugasan) AS total_pekerja
             FROM pekerjaan p
             LEFT JOIN penugasan t ON t.id_pekerjaan = p.id_pekerjaan
             LEFT JOIN karyawan k ON k.id_karyawan = t.id_karyawan
             GROUP BY p.id_pekerjaan
             ORDER BY p.id_pekerjaan ASC',
        );

        return $statement->fetchAll();
    }

    public function getAvailableJobs(): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $statement = $this->connection->prepare(
            'SELECT p.id_pekerjaan, p.nama_pekerjaan, p.deskripsi, p.lokasi,
                    p.tanggal_mulai, p.target_selesai, p.status, p.metode_penentuan
             FROM pekerjaan p
             WHERE p.metode_penentuan = :metode
               AND p.status <> :selesai
               AND (p.target_selesai IS NULL OR p.target_selesai >= :today)
               AND NOT EXISTS (
                   SELECT 1 FROM penugasan t WHERE t.id_pekerjaan = p.id_pekerjaan
               )
             ORDER BY p.id_pekerjaan DESC',
        );
        $statement->execute([
            'metode' => 'terbuka',
            'selesai' => 'selesai',
            'today' => $today,
        ]);

        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT p.id_pekerjaan, p.nama_pekerjaan, p.deskripsi, p.lokasi,
                    p.tanggal_mulai, p.target_selesai, p.status, p.metode_penentuan
             FROM pekerjaan p
             WHERE p.id_pekerjaan = :id_pekerjaan
             LIMIT 1',
        );
        $statement->execute(['id_pekerjaan' => $id]);
        $job = $statement->fetch();

        if ($job === false) {
            return null;
        }

        $workersStmt = $this->connection->prepare(
            'SELECT t.id_penugasan, t.id_karyawan, k.nama, k.jabatan, t.jenis_penugasan, t.tanggal_penugasan
             FROM penugasan t
             INNER JOIN karyawan k ON k.id_karyawan = t.id_karyawan
             WHERE t.id_pekerjaan = :id_pekerjaan',
        );
        $workersStmt->execute(['id_pekerjaan' => $id]);
        $job['assigned_workers'] = $workersStmt->fetchAll();

        return $job;
    }

    public function create(
        string $name,
        ?string $description,
        ?string $location,
        ?string $startDate,
        ?string $targetDate,
        string $status,
        string $assignmentMethod = 'terbuka',
        array|int|null $employeeIds = null,
    ): array {
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
                    metode_penentuan
                 ) VALUES (
                    :nama_pekerjaan,
                    :deskripsi,
                    :lokasi,
                    :tanggal_mulai,
                    :target_selesai,
                    :status,
                    :metode_penentuan
                 )',
            );
            $statement->execute([
                'nama_pekerjaan' => $name,
                'deskripsi' => $description,
                'lokasi' => $location,
                'tanggal_mulai' => $startDate,
                'target_selesai' => $targetDate,
                'status' => $status,
                'metode_penentuan' => $assignmentMethod,
            ]);

            $jobId = (int) $this->connection->lastInsertId();

            if ($assignmentMethod === 'langsung' && !empty($employeeIds)) {
                $empList = is_array($employeeIds) ? $employeeIds : [$employeeIds];
                $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
                $assignStmt = $this->connection->prepare(
                    'INSERT INTO penugasan (id_pekerjaan, id_karyawan, jenis_penugasan, tanggal_penugasan)
                     VALUES (:id_pekerjaan, :id_karyawan, :jenis, :tanggal)',
                );
                foreach ($empList as $empId) {
                    $assignStmt->execute([
                        'id_pekerjaan' => $jobId,
                        'id_karyawan' => (int) $empId,
                        'jenis' => 'admin',
                        'tanggal' => $today,
                    ]);
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
        string $status,
        string $assignmentMethod = 'terbuka',
        array|int|null $employeeIds = null,
    ): ?array {
        $this->connection->beginTransaction();

        try {
            $statement = $this->connection->prepare(
                'UPDATE pekerjaan
                 SET nama_pekerjaan = :nama_pekerjaan,
                     deskripsi = :deskripsi,
                     lokasi = :lokasi,
                     tanggal_mulai = :tanggal_mulai,
                     target_selesai = :target_selesai,
                     status = :status,
                     metode_penentuan = :metode_penentuan
                 WHERE id_pekerjaan = :id_pekerjaan',
            );
            $statement->execute([
                'nama_pekerjaan' => $name,
                'deskripsi' => $description,
                'lokasi' => $location,
                'tanggal_mulai' => $startDate,
                'target_selesai' => $targetDate,
                'status' => $status,
                'metode_penentuan' => $assignmentMethod,
                'id_pekerjaan' => $id,
            ]);

            if ($assignmentMethod === 'langsung' && $employeeIds !== null) {
                $empList = is_array($employeeIds) ? $employeeIds : [$employeeIds];
                $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');

                // Dapatkan karyawan yang saat ini sudah ditugaskan
                $currentStmt = $this->connection->prepare(
                    'SELECT id_karyawan FROM penugasan WHERE id_pekerjaan = :id_pekerjaan',
                );
                $currentStmt->execute(['id_pekerjaan' => $id]);
                $currentEmpIds = array_map('intval', $currentStmt->fetchAll(\PDO::FETCH_COLUMN));

                // Tambahkan karyawan baru yang belum ada di penugasan
                $insertAssign = $this->connection->prepare(
                    'INSERT INTO penugasan (id_pekerjaan, id_karyawan, jenis_penugasan, tanggal_penugasan)
                     VALUES (:id_pekerjaan, :id_karyawan, :jenis, :tanggal)',
                );
                foreach ($empList as $empId) {
                    $empIdInt = (int) $empId;
                    if (!in_array($empIdInt, $currentEmpIds, true)) {
                        $insertAssign->execute([
                            'id_pekerjaan' => $id,
                            'id_karyawan' => $empIdInt,
                            'jenis' => 'admin',
                            'tanggal' => $today,
                        ]);
                    }
                }

                // Untuk karyawan yang tidak lagi dipilih, periksa apakah memiliki absensi/pengeluaran
                // Hanya hapus jika aman (tidak memiliki absensi dan tidak memiliki pengeluaran)
                $removeAssign = $this->connection->prepare(
                    'DELETE FROM penugasan WHERE id_pekerjaan = :id_pekerjaan AND id_karyawan = :id_karyawan',
                );
                foreach ($currentEmpIds as $currentIdInt) {
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
                        }
                    }
                }
            }

            $this->connection->commit();

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
            // 1. Lock baris pekerjaan untuk mencegah race condition
            $jobStmt = $this->connection->prepare(
                'SELECT id_pekerjaan, nama_pekerjaan, status, metode_penentuan, target_selesai
                 FROM pekerjaan
                 WHERE id_pekerjaan = :id_pekerjaan
                 FOR UPDATE',
            );
            $jobStmt->execute(['id_pekerjaan' => $jobId]);
            $job = $jobStmt->fetch();

            if ($job === false) {
                $this->connection->rollBack();
                return ['status' => 'not_found', 'message' => 'Data pekerjaan tidak ditemukan'];
            }

            if ($job['status'] === 'selesai') {
                $this->connection->rollBack();
                return ['status' => 'completed', 'message' => 'Pekerjaan ini sudah selesai dan tidak dapat diambil'];
            }

            if ($job['metode_penentuan'] !== 'terbuka') {
                $this->connection->rollBack();
                return ['status' => 'not_open', 'message' => 'Pekerjaan ini tidak dibuka untuk dipilih secara mandiri'];
            }

            // 2. Evaluasi deadline berdasarkan tanggal server di zona Asia/Jakarta
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            if ($job['target_selesai'] !== null && $job['target_selesai'] < $today) {
                $this->connection->rollBack();
                return [
                    'status' => 'expired',
                    'message' => 'Pekerjaan telah melewati batas waktu tenggat (deadline) dan tidak dapat diambil',
                ];
            }

            // 3. Periksa apakah sudah ada penugasan (dengan FOR UPDATE lock)
            $assignmentStmt = $this->connection->prepare(
                'SELECT id_penugasan, id_karyawan FROM penugasan WHERE id_pekerjaan = :id_pekerjaan FOR UPDATE',
            );
            $assignmentStmt->execute(['id_pekerjaan' => $jobId]);
            $existing = $assignmentStmt->fetch();

            if ($existing !== false) {
                $this->connection->rollBack();
                if ((int) $existing['id_karyawan'] === $employeeId) {
                    return [
                        'status' => 'already_claimed_by_you',
                        'message' => 'Anda sudah mengambil pekerjaan ini sebelumnya',
                    ];
                }
                return [
                    'status' => 'already_claimed',
                    'message' => 'Pekerjaan sudah tidak tersedia karena telah diambil pekerja lain',
                ];
            }

            // 4. Pastikan karyawan valid
            $empStmt = $this->connection->prepare(
                'SELECT id_karyawan, nama FROM karyawan WHERE id_karyawan = :id_karyawan FOR UPDATE',
            );
            $empStmt->execute(['id_karyawan' => $employeeId]);
            $employee = $empStmt->fetch();

            if ($employee === false) {
                $this->connection->rollBack();
                return ['status' => 'employee_not_found', 'message' => 'Data karyawan tidak ditemukan'];
            }

            // 5. Masukkan penugasan mandiri
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            $insert = $this->connection->prepare(
                'INSERT INTO penugasan (id_pekerjaan, id_karyawan, jenis_penugasan, tanggal_penugasan)
                 VALUES (:id_pekerjaan, :id_karyawan, :jenis, :tanggal)',
            );
            $insert->execute([
                'id_pekerjaan' => $jobId,
                'id_karyawan' => $employeeId,
                'jenis' => 'mandiri',
                'tanggal' => $today,
            ]);

            $assignmentId = (int) $this->connection->lastInsertId();

            $this->connection->commit();

            return [
                'status' => 'success',
                'message' => 'Pekerjaan berhasil diambil',
                'data' => [
                    'id_penugasan' => $assignmentId,
                    'id_pekerjaan' => $jobId,
                    'id_karyawan' => $employeeId,
                    'nama_karyawan' => $employee['nama'],
                    'nama_pekerjaan' => $job['nama_pekerjaan'],
                    'jenis_penugasan' => 'mandiri',
                ],
            ];
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
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
                ) AS is_referenced',
        );
        $referenceQuery->execute([
            'assignment_id' => $id,
            'attendance_id' => $id,
            'expense_id' => $id,
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
