<?php

declare(strict_types=1);

final class PekerjaanController
{
    private const ALLOWED_STATUSES = [
        'belum_mulai',
        'berjalan',
        'selesai',
    ];

    private const ALLOWED_METHODS = [
        'langsung',
        'terbuka',
    ];

    public function __construct(private readonly Pekerjaan $model)
    {
    }

    public function index(): never
    {
        try {
            sendJsonResponse(
                true,
                'Data pekerjaan berhasil diambil',
                $this->model->getAll(),
            );
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function tersedia(): never
    {
        try {
            sendJsonResponse(
                true,
                'Daftar pekerjaan tersedia berhasil diambil',
                $this->model->getAvailableJobs(),
            );
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function show(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        try {
            $job = $this->model->findById($id);
            if ($job === null) {
                sendJsonResponse(false, 'Data pekerjaan tidak ditemukan', null, 404);
            }

            sendJsonResponse(true, 'Data pekerjaan berhasil diambil', $job);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function store(): never
    {
        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa objek JSON yang valid', null, 422);
        }

        $validated = $this->validateJob($input);
        if (isset($validated['error'])) {
            sendJsonResponse(false, $validated['error'], null, 422);
        }

        try {
            $job = $this->model->create(
                $validated['nama_pekerjaan'],
                $validated['deskripsi'],
                $validated['lokasi'],
                $validated['tanggal_mulai'],
                $validated['target_selesai'],
                $validated['status'],
                $validated['metode_penentuan'],
                $validated['id_karyawan_list'],
            );

            sendJsonResponse(true, 'Data pekerjaan berhasil ditambahkan', $job, 201);
        } catch (\PDOException $exception) {
            $this->respondWithDatabaseError($exception);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function update(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa objek JSON yang valid', null, 422);
        }

        $validated = $this->validateJob($input);
        if (isset($validated['error'])) {
            sendJsonResponse(false, $validated['error'], null, 422);
        }

        try {
            $job = $this->model->update(
                $id,
                $validated['nama_pekerjaan'],
                $validated['deskripsi'],
                $validated['lokasi'],
                $validated['tanggal_mulai'],
                $validated['target_selesai'],
                $validated['status'],
                $validated['metode_penentuan'],
                $validated['id_karyawan_list'],
            );

            if ($job === null) {
                sendJsonResponse(false, 'Data pekerjaan tidak ditemukan', null, 404);
            }

            sendJsonResponse(true, 'Data pekerjaan berhasil diperbarui', $job);
        } catch (\PDOException $exception) {
            $this->respondWithDatabaseError($exception);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function claim(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa objek JSON yang valid', null, 422);
        }

        $employeeId = $this->parseInputId($input['id_karyawan'] ?? null);
        if ($employeeId === null) {
            sendJsonResponse(false, 'id_karyawan wajib diisi dengan ID karyawan yang valid', null, 422);
        }

        try {
            $result = $this->model->claimJob($id, $employeeId);

            if ($result['status'] === 'success') {
                sendJsonResponse(true, $result['message'], $result['data'], 201);
            }

            if ($result['status'] === 'already_claimed' || $result['status'] === 'already_claimed_by_you') {
                sendJsonResponse(false, $result['message'], null, 409);
            }

            if ($result['status'] === 'expired' || $result['status'] === 'not_open' || $result['status'] === 'completed') {
                sendJsonResponse(false, $result['message'], null, 422);
            }

            if ($result['status'] === 'not_found' || $result['status'] === 'employee_not_found') {
                sendJsonResponse(false, $result['message'], null, 404);
            }

            sendJsonResponse(false, $result['message'] ?? 'Gagal mengambil pekerjaan', null, 400);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function destroy(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        try {
            $result = $this->model->delete($id);

            if (!$result['deleted'] && !$result['referenced']) {
                sendJsonResponse(false, 'Data pekerjaan tidak ditemukan', null, 404);
            }

            if ($result['referenced']) {
                sendJsonResponse(
                    false,
                    'Data pekerjaan tidak dapat dihapus karena masih digunakan oleh penugasan, absensi, atau pengeluaran',
                    null,
                    409,
                );
            }

            sendJsonResponse(true, 'Data pekerjaan berhasil dihapus', null);
        } catch (\PDOException $exception) {
            $this->respondWithDatabaseError($exception);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    private function parseId(string $rawId): ?int
    {
        if (!preg_match('/^[1-9]\d*$/D', $rawId)) {
            return null;
        }

        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        return is_int($id) ? $id : null;
    }

    private function parseInputId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $rawId = (string) $value;
        if (!preg_match('/^[1-9]\d*$/D', $rawId)) {
            return null;
        }

        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        return is_int($id) ? $id : null;
    }

    private function readJsonBody(): ?array
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            return null;
        }

        try {
            $input = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_object($input) ? get_object_vars($input) : null;
    }

    private function validateJob(array $input): array
    {
        $name = $input['nama_pekerjaan'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return ['error' => 'Nama pekerjaan wajib diisi'];
        }

        $name = trim($name);
        $nameLength = preg_match_all('/./us', $name);
        if ($nameLength === false || $nameLength > 150) {
            return ['error' => 'Nama pekerjaan maksimal 150 karakter'];
        }

        $description = $input['deskripsi'] ?? null;
        if ($description !== null && !is_string($description)) {
            return ['error' => 'Deskripsi harus berupa teks atau null'];
        }
        if (is_string($description)) {
            $description = trim($description);
            $description = $description === '' ? null : $description;
        }

        $location = $input['lokasi'] ?? null;
        if ($location !== null && !is_string($location)) {
            return ['error' => 'Lokasi harus berupa teks atau null'];
        }
        if (is_string($location)) {
            $location = trim($location);
            $locationLength = preg_match_all('/./us', $location);
            if ($locationLength === false || $locationLength > 150) {
                return ['error' => 'Lokasi maksimal 150 karakter'];
            }
            $location = $location === '' ? null : $location;
        }

        $startDate = $this->validateDate($input['tanggal_mulai'] ?? null);
        if (isset($startDate['error'])) {
            return ['error' => 'Tanggal mulai harus berupa tanggal YYYY-MM-DD yang valid'];
        }

        $targetDate = $this->validateDate($input['target_selesai'] ?? null);
        if (isset($targetDate['error'])) {
            return ['error' => 'Target selesai harus berupa tanggal YYYY-MM-DD yang valid'];
        }

        if (
            $startDate['value'] !== null &&
            $targetDate['value'] !== null &&
            $targetDate['value'] < $startDate['value']
        ) {
            return ['error' => 'Target selesai tidak boleh lebih awal dari tanggal mulai'];
        }

        $status = $input['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::ALLOWED_STATUSES, true)) {
            return ['error' => 'Status harus bernilai belum_mulai, berjalan, atau selesai'];
        }

        $method = $input['metode_penentuan'] ?? 'terbuka';
        if (!is_string($method) || !in_array($method, self::ALLOWED_METHODS, true)) {
            return ['error' => 'Metode penentuan harus bernilai langsung atau terbuka'];
        }

        $employeeIds = [];
        if ($method === 'langsung') {
            if (isset($input['id_karyawan_list']) && is_array($input['id_karyawan_list'])) {
                foreach ($input['id_karyawan_list'] as $raw) {
                    $pid = $this->parseInputId($raw);
                    if ($pid !== null) {
                        $employeeIds[] = $pid;
                    }
                }
            } elseif (isset($input['id_karyawan'])) {
                $pid = $this->parseInputId($input['id_karyawan']);
                if ($pid !== null) {
                    $employeeIds[] = $pid;
                }
            }

            $employeeIds = array_values(array_unique($employeeIds));
            if (empty($employeeIds)) {
                return ['error' => 'Silakan pilih minimal satu pekerja untuk metode penetapan langsung'];
            }
        }

        return [
            'nama_pekerjaan' => $name,
            'deskripsi' => $description,
            'lokasi' => $location,
            'tanggal_mulai' => $startDate['value'],
            'target_selesai' => $targetDate['value'],
            'status' => $status,
            'metode_penentuan' => $method,
            'id_karyawan_list' => $employeeIds,
        ];
    }

    private function validateDate(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['value' => null];
        }

        if (!is_string($value)) {
            return ['error' => true];
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $dateErrors = \DateTimeImmutable::getLastErrors();

        if (
            $date === false ||
            $date->format('Y-m-d') !== $value ||
            ($dateErrors !== false &&
                ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            return ['error' => true];
        }

        return ['value' => $value];
    }

    private function respondWithDatabaseError(\PDOException $exception): never
    {
        if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
            sendJsonResponse(
                false,
                'Data pekerjaan berkonflik dengan data lain',
                null,
                409,
            );
        }

        $this->respondWithServerError($exception);
    }

    private function respondWithServerError(\Throwable $exception): never
    {
        error_log('Pekerjaan API request failed: ' . get_class($exception));
        sendJsonResponse(false, 'Terjadi kesalahan pada server', null, 500);
    }
}
