<?php

declare(strict_types=1);

final class PenugasanController
{
    public function __construct(private readonly Penugasan $model)
    {
    }

    public function index(): never
    {
        try {
            sendJsonResponse(
                true,
                'Data penugasan berhasil diambil',
                $this->model->getAll(),
            );
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function show(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID penugasan tidak valid', null, 422);
        }

        try {
            $assignment = $this->model->findById($id);
            if ($assignment === null) {
                sendJsonResponse(false, 'Data penugasan tidak ditemukan', null, 404);
            }

            sendJsonResponse(
                true,
                'Data penugasan berhasil diambil',
                $assignment,
            );
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

        $validated = $this->validateIds($input);
        if (isset($validated['error'])) {
            sendJsonResponse(false, $validated['error'], null, 422);
        }

        try {
            $this->validateReferences(
                $validated['id_karyawan'],
                $validated['id_pekerjaan'],
            );

            if (
                $this->model->isDuplicate(
                    $validated['id_karyawan'],
                    $validated['id_pekerjaan'],
                )
            ) {
                sendJsonResponse(
                    false,
                    'Karyawan sudah ditugaskan pada pekerjaan ini',
                    null,
                    409,
                );
            }

            $result = $this->model->create(
                $validated['id_karyawan'],
                $validated['id_pekerjaan'],
            );

            if ($result['status'] === 'duplicate') {
                sendJsonResponse(
                    false,
                    'Karyawan sudah ditugaskan pada pekerjaan ini',
                    null,
                    409,
                );
            }

            if ($result['status'] === 'reference_not_found') {
                sendJsonResponse(
                    false,
                    'Data karyawan atau pekerjaan tidak ditemukan',
                    null,
                    422,
                );
            }

            sendJsonResponse(
                true,
                'Data penugasan berhasil ditambahkan',
                $result['data'],
                201,
            );
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
            sendJsonResponse(false, 'ID penugasan tidak valid', null, 422);
        }

        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa objek JSON yang valid', null, 422);
        }

        $validated = $this->validateIds($input);
        if (isset($validated['error'])) {
            sendJsonResponse(false, $validated['error'], null, 422);
        }

        try {
            if ($this->model->findById($id) === null) {
                sendJsonResponse(false, 'Data penugasan tidak ditemukan', null, 404);
            }

            $this->validateReferences(
                $validated['id_karyawan'],
                $validated['id_pekerjaan'],
            );

            if (
                $this->model->isDuplicate(
                    $validated['id_karyawan'],
                    $validated['id_pekerjaan'],
                    $id,
                )
            ) {
                sendJsonResponse(
                    false,
                    'Karyawan sudah ditugaskan pada pekerjaan ini',
                    null,
                    409,
                );
            }

            $result = $this->model->update(
                $id,
                $validated['id_karyawan'],
                $validated['id_pekerjaan'],
            );

            if ($result['status'] === 'not_found') {
                sendJsonResponse(false, 'Data penugasan tidak ditemukan', null, 404);
            }

            if ($result['status'] === 'duplicate') {
                sendJsonResponse(
                    false,
                    'Karyawan sudah ditugaskan pada pekerjaan ini',
                    null,
                    409,
                );
            }

            if ($result['status'] === 'reference_not_found') {
                sendJsonResponse(
                    false,
                    'Data karyawan atau pekerjaan tidak ditemukan',
                    null,
                    422,
                );
            }

            sendJsonResponse(
                true,
                'Data penugasan berhasil diperbarui',
                $result['data'],
            );
        } catch (\PDOException $exception) {
            $this->respondWithDatabaseError($exception);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function destroy(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID penugasan tidak valid', null, 422);
        }

        try {
            $result = $this->model->delete($id);

            if ($result['not_found']) {
                sendJsonResponse(false, 'Data penugasan tidak ditemukan', null, 404);
            }

            if ($result['referenced']) {
                sendJsonResponse(
                    false,
                    $result['message'] ?? 'Data penugasan tidak dapat dibatalkan karena masih digunakan oleh absensi atau pengeluaran',
                    null,
                    409,
                );
            }

            sendJsonResponse(true, 'Penugasan berhasil dibatalkan', null);
        } catch (\PDOException $exception) {
            if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
                sendJsonResponse(
                    false,
                    'Data penugasan tidak dapat dihapus karena masih digunakan',
                    null,
                    409,
                );
            }

            $this->respondWithServerError($exception);
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

    private function validateIds(array $input): array
    {
        $employeeId = $this->parseInputId($input['id_karyawan'] ?? null);
        if ($employeeId === null) {
            return ['error' => 'id_karyawan wajib diisi dengan angka positif'];
        }

        $jobId = $this->parseInputId($input['id_pekerjaan'] ?? null);
        if ($jobId === null) {
            return ['error' => 'id_pekerjaan wajib diisi dengan angka positif'];
        }

        return [
            'id_karyawan' => $employeeId,
            'id_pekerjaan' => $jobId,
        ];
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

    private function validateReferences(int $employeeId, int $jobId): void
    {
        if (!$this->model->employeeExists($employeeId)) {
            sendJsonResponse(false, 'Data karyawan tidak ditemukan', null, 422);
        }

        if (!$this->model->jobExists($jobId)) {
            sendJsonResponse(false, 'Data pekerjaan tidak ditemukan', null, 422);
        }
    }

    private function respondWithDatabaseError(\PDOException $exception): never
    {
        if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
            sendJsonResponse(
                false,
                'Referensi karyawan atau pekerjaan tidak valid',
                null,
                422,
            );
        }

        $this->respondWithServerError($exception);
    }

    private function respondWithServerError(\Throwable $exception): never
    {
        error_log('Penugasan API request failed: ' . get_class($exception));
        sendJsonResponse(false, 'Terjadi kesalahan pada server', null, 500);
    }
}
