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

        $validated = $this->validateEmployee($input, null, true);
        if (isset($validated['error'])) {
            sendJsonResponse(false, $validated['error'], null, 400);
        }

        try {
            $employee = $this->model->create(
                $validated['nama'],
                $validated['jabatan'],
                $validated['nomor_hp'],
                $validated['jenis_upah'],
                $validated['nominal_upah'],
                $validated['lembur_aktif'],
                $validated['tarif_lembur'],
                $validated['catatan_admin'],
                $validated['status'],
                $validated['kata_sandi'],
            );

            sendJsonResponse(
                true,
                "Karyawan berhasil ditambahkan dengan ID {$employee['kode_karyawan']}",
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

        // Dukungan quick update status jika request hanya mengirim status
        if (isset($input['status']) && count($input) === 1) {
            $status = in_array($input['status'], ['aktif', 'nonaktif'], true) ? $input['status'] : 'aktif';
            try {
                $employee = $this->model->updateStatus($id, $status);
                if ($employee === null) {
                    sendJsonResponse(false, 'Data karyawan tidak ditemukan', null, 404);
                }
                sendJsonResponse(true, "Status karyawan berhasil diubah menjadi {$status}", $employee);
            } catch (\Throwable $exception) {
                $this->respondWithServerError($exception);
            }
        }

        $validated = $this->validateEmployee($input, $id, false);
        if (isset($validated['error'])) {
            sendJsonResponse(false, $validated['error'], null, 400);
        }

        try {
            $employee = $this->model->update(
                $id,
                $validated['nama'],
                $validated['jabatan'],
                $validated['nomor_hp'],
                $validated['jenis_upah'],
                $validated['nominal_upah'],
                $validated['lembur_aktif'],
                $validated['tarif_lembur'],
                $validated['catatan_admin'],
                $validated['status'],
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

    public function resetPassword(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID karyawan tidak valid', null, 400);
        }

        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa objek JSON yang valid', null, 400);
        }

        $password = trim((string)($input['kata_sandi'] ?? $input['password'] ?? ''));
        $confirmPassword = trim((string)($input['konfirmasi_kata_sandi'] ?? $input['confirm_password'] ?? ''));

        if ($password === '') {
            sendJsonResponse(false, 'Kata sandi baru wajib diisi', null, 400);
        }

        if (strlen($password) < 6) {
            sendJsonResponse(false, 'Kata sandi baru minimal 6 karakter', null, 400);
        }

        if ($confirmPassword !== '' && $password !== $confirmPassword) {
            sendJsonResponse(false, 'Konfirmasi kata sandi tidak cocok dengan kata sandi baru', null, 400);
        }

        try {
            $employee = $this->model->resetPassword($id, $password);
            sendJsonResponse(
                true,
                "Kata sandi untuk karyawan {$employee['nama']} ({$employee['kode_karyawan']}) berhasil direset.",
                $employee,
            );
        } catch (\InvalidArgumentException $e) {
            sendJsonResponse(false, $e->getMessage(), null, 400);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function toggleStatus(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID karyawan tidak valid', null, 400);
        }

        $input = $this->readJsonBody();
        $status = $input['status'] ?? null;
        if (!in_array($status, ['aktif', 'nonaktif'], true)) {
            sendJsonResponse(false, "Status harus 'aktif' atau 'nonaktif'", null, 400);
        }

        try {
            $employee = $this->model->updateStatus($id, $status);
            if ($employee === null) {
                sendJsonResponse(false, 'Data karyawan tidak ditemukan', null, 404);
            }

            sendJsonResponse(
                true,
                "Status karyawan berhasil diubah menjadi {$status}",
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
                    'Data karyawan tidak dapat dihapus karena masih terkait dengan data historis (penugasan, absensi, pengeluaran, gaji, atau akun pengguna). Silakan gunakan fitur Nonaktifkan Karyawan agar integritas data operasional tetap terjaga.',
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

    private function validateEmployee(array $input, ?int $currentId = null, bool $isCreate = false): array
    {
        // 1. Nama (wajib)
        $name = $input['nama'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return ['error' => 'Nama lengkap karyawan wajib diisi'];
        }
        $name = trim($name);
        $nameLength = preg_match_all('/./us', $name);
        if ($nameLength === false || $nameLength > 100) {
            return ['error' => 'Nama karyawan maksimal 100 karakter'];
        }
        if ($nameLength < 2) {
            return ['error' => 'Nama karyawan minimal 2 karakter'];
        }

        // 2. Jabatan (opsional)
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

        // 3. Nomor HP (opsional, divalidasi)
        $phone = $input['nomor_hp'] ?? null;
        if ($phone !== null && !is_string($phone)) {
            return ['error' => 'Nomor HP harus berupa teks nomor telepon'];
        }
        if (is_string($phone)) {
            $phone = trim($phone);
            if ($phone !== '') {
                // Validasi format nomor HP Indonesia / internasional (minimal 8 digit)
                if (!preg_match('/^\+?[0-9\s\-]{8,20}$/D', $phone)) {
                    return ['error' => 'Format nomor HP tidak valid (minimal 8 digit angka, contoh: 081234567890)'];
                }
            } else {
                $phone = null;
            }
        }

        // 4. Jenis Gaji ('per_jam', 'per_hari', 'per_bulan')
        $wageType = $input['jenis_upah'] ?? 'per_jam';
        if (!in_array($wageType, ['per_jam', 'per_hari', 'per_bulan'], true)) {
            return ['error' => "Jenis gaji harus salah satu dari: 'per_jam', 'per_hari', atau 'per_bulan'"];
        }

        // 5. Nominal Gaji
        $wageAmount = $input['nominal_upah'] ?? ($input['tarif_per_jam'] ?? null);
        if (
            (!is_string($wageAmount) && !is_int($wageAmount) && !is_float($wageAmount)) ||
            (is_string($wageAmount) && trim($wageAmount) === '') ||
            !is_numeric($wageAmount)
        ) {
            return ['error' => 'Besar gaji wajib diisi dengan nilai angka yang valid'];
        }

        $wageAmountStr = trim((string) $wageAmount);
        if (
            !preg_match('/^\+?(?:\d+(?:\.\d*)?|\.\d+)$/D', $wageAmountStr) ||
            !is_finite((float) $wageAmountStr) ||
            (float) $wageAmountStr < 0 ||
            (float) $wageAmountStr > 999999999999.99
        ) {
            return ['error' => 'Besar gaji harus berupa angka yang valid (Rp0 atau lebih)'];
        }

        // 6. Lembur Aktif & Tarif Lembur
        $overtimeActive = !empty($input['lembur_aktif']);
        $overtimeRateStr = '0.00';
        if ($overtimeActive) {
            $overtimeRate = $input['tarif_lembur'] ?? 0;
            if (!is_numeric($overtimeRate) || (float) $overtimeRate < 0) {
                return ['error' => 'Besar gaji lembur per jam wajib diisi dengan angka yang valid saat lembur diaktifkan'];
            }
            $overtimeRateStr = number_format((float) $overtimeRate, 2, '.', '');
        }

        // 7. Catatan Admin (opsional)
        $adminNotes = $input['catatan_admin'] ?? null;
        if ($adminNotes !== null && !is_string($adminNotes)) {
            return ['error' => 'Catatan admin harus berupa teks atau null'];
        }
        if (is_string($adminNotes)) {
            $adminNotes = trim($adminNotes);
            $adminNotes = $adminNotes === '' ? null : $adminNotes;
        }

        // 8. Status ('aktif', 'nonaktif')
        $status = $input['status'] ?? 'aktif';
        if (!in_array($status, ['aktif', 'nonaktif'], true)) {
            $status = 'aktif';
        }

        // 9. Validasi Password khusus saat Tambah Karyawan Baru
        $password = null;
        if ($isCreate) {
            $rawPass = $input['kata_sandi'] ?? $input['password'] ?? null;
            $rawConfirm = $input['konfirmasi_kata_sandi'] ?? $input['confirm_password'] ?? null;

            if (!is_string($rawPass) || trim($rawPass) === '') {
                return ['error' => 'Kata sandi login karyawan wajib diisi'];
            }
            if (strlen(trim($rawPass)) < 6) {
                return ['error' => 'Kata sandi minimal 6 karakter'];
            }
            if (!is_string($rawConfirm) || trim($rawConfirm) === '') {
                return ['error' => 'Konfirmasi kata sandi wajib diisi'];
            }
            if (trim($rawPass) !== trim($rawConfirm)) {
                return ['error' => 'Konfirmasi kata sandi tidak cocok dengan kata sandi yang dimasukkan'];
            }

            $password = trim($rawPass);
        }

        return [
            'nama' => $name,
            'jabatan' => $position,
            'nomor_hp' => $phone,
            'jenis_upah' => $wageType,
            'nominal_upah' => number_format((float) $wageAmountStr, 2, '.', ''),
            'lembur_aktif' => $overtimeActive,
            'tarif_lembur' => $overtimeRateStr,
            'catatan_admin' => $adminNotes,
            'status' => $status,
            'kata_sandi' => $password,
        ];
    }

    private function respondWithDatabaseError(\PDOException $exception): never
    {
        if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
            sendJsonResponse(
                false,
                'Terjadi konflik kunci atau ID pada database.',
                null,
                409,
            );
        }

        $this->respondWithServerError($exception);
    }

    private function respondWithServerError(\Throwable $exception): never
    {
        error_log('Karyawan API error: ' . $exception->getMessage());
        sendJsonResponse(false, 'Terjadi kesalahan pada server: ' . $exception->getMessage(), null, 500);
    }
}
