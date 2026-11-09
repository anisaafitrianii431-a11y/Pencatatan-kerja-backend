<?php

declare(strict_types=1);

final class AbsensiController
{
    public function __construct(private readonly Absensi $model)
    {
    }

    public function index(): never
    {
        try {
            $employeeId = isset($_GET['id_karyawan']) && is_numeric($_GET['id_karyawan'])
                ? (int) $_GET['id_karyawan']
                : null;
            $jobId = isset($_GET['id_pekerjaan']) && is_numeric($_GET['id_pekerjaan'])
                ? (int) $_GET['id_pekerjaan']
                : null;
            $date = isset($_GET['tanggal']) && is_string($_GET['tanggal']) && $_GET['tanggal'] !== ''
                ? trim($_GET['tanggal'])
                : null;
            $status = isset($_GET['status_verifikasi']) && is_string($_GET['status_verifikasi']) && $_GET['status_verifikasi'] !== ''
                ? trim($_GET['status_verifikasi'])
                : null;

            $records = $this->model->getAll($employeeId, $jobId, $date, $status);

            // Format duration labels
            $formatted = array_map(static function (array $item): array {
                $menit = (int) ($item['durasi_menit'] ?? 0);
                if ($menit < 0) {
                    $menit = 0;
                }
                $jam = intdiv($menit, 60);
                $sisaMenit = $menit % 60;
                $item['durasi_format'] = "{$jam}j {$sisaMenit}m";
                $item['durasi_jam'] = round($menit / 60, 2);
                return $item;
            }, $records);

            sendJsonResponse(
                true,
                'Data absensi berhasil diambil',
                $formatted,
            );
        } catch (\Throwable $exception) {
            error_log('AbsensiController::index error: ' . $exception->getMessage());
            sendJsonResponse(false, 'Terjadi kesalahan pada server saat memuat absensi', null, 500);
        }
    }

    public function show(string $rawId): never
    {
        $id = is_numeric($rawId) ? (int) $rawId : null;
        if ($id === null || $id < 1) {
            sendJsonResponse(false, 'ID absensi tidak valid', null, 422);
        }

        try {
            $record = $this->model->findById($id);
            if ($record === null) {
                sendJsonResponse(false, 'Data absensi tidak ditemukan', null, 404);
            }

            $menit = (int) ($record['durasi_menit'] ?? 0);
            if ($menit < 0) $menit = 0;
            $jam = intdiv($menit, 60);
            $sisaMenit = $menit % 60;
            $record['durasi_format'] = "{$jam}j {$sisaMenit}m";
            $record['durasi_jam'] = round($menit / 60, 2);

            sendJsonResponse(true, 'Data absensi berhasil diambil', $record);
        } catch (\Throwable $exception) {
            error_log('AbsensiController::show error: ' . $exception->getMessage());
            sendJsonResponse(false, 'Terjadi kesalahan pada server', null, 500);
        }
    }

    public function verify(string $rawId): never
    {
        $id = is_numeric($rawId) ? (int) $rawId : null;
        if ($id === null || $id < 1) {
            sendJsonResponse(false, 'ID absensi tidak valid', null, 422);
        }

        $input = $this->readJsonBody();
        $status = $input['status_verifikasi'] ?? null;
        if (!in_array($status, ['menunggu', 'disetujui', 'ditolak'], true)) {
            sendJsonResponse(false, 'Status verifikasi harus salah satu dari: menunggu, disetujui, ditolak', null, 422);
        }

        try {
            $updated = $this->model->updateVerificationStatus($id, $status);
            if (!$updated) {
                sendJsonResponse(false, 'Gagal memperbarui status absensi', null, 500);
            }

            sendJsonResponse(true, "Status absensi berhasil diperbarui menjadi {$status}", [
                'id_absensi' => $id,
                'status_verifikasi' => $status,
            ]);
        } catch (\Throwable $exception) {
            error_log('AbsensiController::verify error: ' . $exception->getMessage());
            sendJsonResponse(false, 'Terjadi kesalahan pada server saat verifikasi', null, 500);
        }
    }

    private function readJsonBody(): ?array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

