<?php

declare(strict_types=1);

final class KaryawanController
{
    public function __construct(private readonly Karyawan $model)
    {
    }

    public function index(): never
    {
        try {
            sendJsonResponse(
                true,
                'Data karyawan berhasil diambil',
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
            sendJsonResponse(false, 'ID karyawan tidak valid', null, 400);
        }

        try {
            $employee = $this->model->findById($id);
            if ($employee === null) {
                sendJsonResponse(false, 'Data karyawan tidak ditemukan', null, 404);
            }

            sendJsonResponse(true, 'Data karyawan berhasil diambil', $employee);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function store(): never
    {
        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa objek JSON yang valid', null, 400);
        }

        $validated = $this->validateEmployee($input);
        if (isset($validated['error'])) {
            sendJsonResponse(false, $validated['error'], null, 400);
        }

        try {
            $employee = $this->model->create(
                $validated['nama'],
                $validated['jabatan'],
                $validated['tarif_per_jam'],
            );

            sendJsonResponse(
                true,
                'Data karyawan berhasil ditambahkan',
                $employee,
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
            sendJsonResponse(false, 'ID karyawan tidak valid', null, 400);
        }

        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa objek JSON yang valid', null, 400);
        }

        $validated = $this->validateEmployee($input);
        if (isset($validated['error'])) {
            sendJsonResponse(false, $validated['error'], null, 400);
        }

        try {
            $employee = $this->model->update(
                $id,
                $validated['nama'],
                $validated['jabatan'],
                $validated['tarif_per_jam'],
            );

            if ($employee === null) {
                sendJsonResponse(false, 'Data karyawan tidak ditemukan', null, 404);
            }

            sendJsonResponse(
                true,
                'Data karyawan berhasil diperbarui',
                $employee,
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
            sendJsonResponse(false, 'ID karyawan tidak valid', null, 400);
        }

        try {
            $result = $this->model->delete($id);

            if (!$result['deleted'] && !$result['referenced']) {
                sendJsonResponse(false, 'Data karyawan tidak ditemukan', null, 404);
            }

            if ($result['referenced']) {
                sendJsonResponse(
                    false,
                    'Data karyawan tidak dapat dihapus karena masih digunakan oleh data lain',
                    null,
                    409,
                );
            }

            sendJsonResponse(true, 'Data karyawan berhasil dihapus', null);
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

    private function readJsonBody(): ?array
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            return null;
        }

        try {
            $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($input) && !array_is_list($input) ? $input : null;
    }

    private function validateEmployee(array $input): array
    {
        $name = $input['nama'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return ['error' => 'Nama wajib diisi'];
        }

        $name = trim($name);
        $nameLength = preg_match_all('/./us', $name);
        if ($nameLength === false || $nameLength > 100) {
            return ['error' => 'Nama maksimal 100 karakter'];
        }

        $position = $input['jabatan'] ?? null;
        if ($position !== null && !is_string($position)) {
            return ['error' => 'Jabatan harus berupa teks atau null'];
        }

        if (is_string($position)) {
            $position = trim($position);
            $positionLength = preg_match_all('/./us', $position);
            if ($positionLength === false || $positionLength > 100) {
                return ['error' => 'Jabatan maksimal 100 karakter'];
            }
            $position = $position === '' ? null : $position;
        }

        $rate = $input['tarif_per_jam'] ?? null;
        if (
            (!is_string($rate) && !is_int($rate) && !is_float($rate)) ||
            (is_string($rate) && trim($rate) === '') ||
            !is_numeric($rate)
        ) {
            return ['error' => 'Tarif per jam wajib diisi dengan angka'];
        }

        $rateString = trim((string) $rate);
        if (
            !preg_match('/^\+?(?:\d+(?:\.\d*)?|\.\d+)$/D', $rateString) ||
            !is_finite((float) $rateString) ||
            (float) $rateString < 0 ||
            (float) $rateString > 9999999999.99
        ) {
            return ['error' => 'Tarif per jam harus berupa angka non-negatif yang valid'];
        }

        $decimalPoint = strpos($rateString, '.');
        if (
            $decimalPoint !== false &&
            strlen(rtrim(substr($rateString, $decimalPoint + 1), '0')) > 2
        ) {
            return ['error' => 'Tarif per jam maksimal memiliki 2 angka desimal'];
        }

        return [
            'nama' => $name,
            'jabatan' => $position,
            'tarif_per_jam' => number_format((float) $rateString, 2, '.', ''),
        ];
    }

    private function respondWithDatabaseError(\PDOException $exception): never
    {
        if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
            sendJsonResponse(
                false,
                'Data karyawan berkonflik dengan data lain',
                null,
                409,
            );
        }

        $this->respondWithServerError($exception);
    }

    private function respondWithServerError(\Throwable $exception): never
    {
        error_log('Karyawan API request failed: ' . get_class($exception));
        sendJsonResponse(false, 'Terjadi kesalahan pada server', null, 500);
    }
}
