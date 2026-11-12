<?php

declare(strict_types=1);

final class UserController
{
    public function __construct(private readonly User $model)
    {
    }

    public function index(): never
    {
        try {
            sendJsonResponse(
                true,
                'Daftar pengguna berhasil diambil',
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
            sendJsonResponse(false, 'ID pengguna tidak valid', null, 400);
        }

        try {
            $user = $this->model->findById($id);
            if ($user === null) {
                sendJsonResponse(false, 'Pengguna tidak ditemukan', null, 404);
            }

            sendJsonResponse(true, 'Data pengguna berhasil diambil', $user);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function resetPassword(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID pengguna tidak valid', null, 400);
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
            $user = $this->model->resetPassword($id, $password);
            sendJsonResponse(
                true,
                "Kata sandi untuk pengguna {$user['nama']} ({$user['kode_user']}) berhasil diperbarui.",
                $user,
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
            sendJsonResponse(false, 'ID pengguna tidak valid', null, 400);
        }

        $input = $this->readJsonBody();
        $rawStatus = $input['status'] ?? $input['aktif'] ?? null;

        if ($rawStatus === null) {
            sendJsonResponse(false, "Status keaktifan harus ditentukan ('aktif'/'nonaktif' atau 1/0)", null, 400);
        }

        $aktif = ($rawStatus === 1 || $rawStatus === '1' || $rawStatus === true || $rawStatus === 'aktif') ? 1 : 0;

        try {
            $user = $this->model->updateStatus($id, $aktif);
            $statusLabel = $aktif === 1 ? 'diaktifkan' : 'dinonaktifkan';
            sendJsonResponse(
                true,
                "Akun pengguna {$user['nama']} ({$user['kode_user']}) berhasil {$statusLabel}.",
                $user,
            );
        } catch (\InvalidArgumentException $e) {
            sendJsonResponse(false, $e->getMessage(), null, 400);
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

    private function respondWithServerError(\Throwable $exception): never
    {
        error_log('User API error: ' . $exception->getMessage());
        sendJsonResponse(false, 'Terjadi kesalahan pada server: ' . $exception->getMessage(), null, 500);
    }
}

