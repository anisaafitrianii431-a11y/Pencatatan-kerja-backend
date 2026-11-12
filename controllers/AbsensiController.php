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

    public function store(): never
    {
        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Format data JSON tidak valid', null, 400);
        }

        $karyawanId = isset($input['id_karyawan']) && is_numeric($input['id_karyawan']) ? (int) $input['id_karyawan'] : 0;
        $pekerjaanId = isset($input['id_pekerjaan']) && is_numeric($input['id_pekerjaan']) ? (int) $input['id_pekerjaan'] : 0;
        $tanggal = isset($input['tanggal']) && is_string($input['tanggal']) && $input['tanggal'] !== '' ? trim($input['tanggal']) : date('Y-m-d');
        $jamMasuk = isset($input['jam_masuk']) && is_string($input['jam_masuk']) && $input['jam_masuk'] !== '' ? trim($input['jam_masuk']) : date('H:i:s');
        $status = isset($input['status_verifikasi']) && in_array($input['status_verifikasi'], ['menunggu', 'disetujui', 'ditolak'], true) ? $input['status_verifikasi'] : 'menunggu';
        $latitude = isset($input['latitude']) && is_numeric($input['latitude']) ? (float) $input['latitude'] : null;
        $longitude = isset($input['longitude']) && is_numeric($input['longitude']) ? (float) $input['longitude'] : null;
        $alamat = isset($input['alamat']) && is_string($input['alamat']) ? trim($input['alamat']) : null;
        $fotoMasuk = isset($input['foto_masuk']) && is_string($input['foto_masuk']) ? $input['foto_masuk'] : (isset($input['foto']) && is_string($input['foto']) ? $input['foto'] : null);
        $jarakMeter = null;

        if ($karyawanId <= 0 || $pekerjaanId <= 0) {
            sendJsonResponse(false, 'ID karyawan dan ID pekerjaan wajib diisi', null, 422);
        }

        // Validasi Penugasan Aktif: Karyawan hanya dapat absen pada pekerjaan yang ditugaskan kepadanya
        try {
            $pdo = getDatabaseConnection();
            $chkAssign = $pdo->prepare('
                SELECT id_penugasan, status_penugasan 
                FROM penugasan 
                WHERE id_pekerjaan = :jobId AND id_karyawan = :empId AND COALESCE(status_penugasan, "aktif") = "aktif"
                LIMIT 1
            ');
            $chkAssign->execute(['jobId' => $pekerjaanId, 'empId' => $karyawanId]);
            $activeAssign = $chkAssign->fetch();

            if (!$activeAssign) {
                sendJsonResponse(false, 'Anda tidak terdaftar dalam penugasan aktif untuk pekerjaan ini.', null, 403);
            }
        } catch (\Throwable $chkErr) {
            error_log('Validasi penugasan absensi error: ' . $chkErr->getMessage());
        }

        // Validasi Radius Geofence Lokasi Kerja
        try {
            $pdo = getDatabaseConnection();
            $locStmt = $pdo->prepare('SELECT latitude, longitude, radius_meter FROM lokasi_kerja WHERE id_pekerjaan = :id LIMIT 1');
            $locStmt->execute(['id' => $pekerjaanId]);
            $jobLoc = $locStmt->fetch();

            if ($jobLoc && $jobLoc['latitude'] !== null && $jobLoc['longitude'] !== null) {
                $jobLat = (float) $jobLoc['latitude'];
                $jobLng = (float) $jobLoc['longitude'];
                $maxRadius = (int) ($jobLoc['radius_meter'] ?: 100);

                if ($latitude !== null && $longitude !== null) {
                    $dLat = deg2rad($latitude - $jobLat);
                    $dLon = deg2rad($longitude - $jobLng);
                    $a = sin($dLat / 2) * sin($dLat / 2) +
                         cos(deg2rad($jobLat)) * cos(deg2rad($latitude)) *
                         sin($dLon / 2) * sin($dLon / 2);
                    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                    $jarakMeter = (int) round(6371000 * $c);

                    // Peringatan jika di luar radius
                    $bypassRadius = !empty($input['bypass_radius']);
                    if ($jarakMeter > $maxRadius && !$bypassRadius) {
                        sendJsonResponse(false, "Lokasi Anda berada di luar radius pekerjaan. Jarak Anda terdeteksi {$jarakMeter} meter dari titik proyek (Batas toleransi maksimal {$maxRadius} meter). Pastikan Anda sudah berada di lokasi kerja.", [
                            'jarak_meter' => $jarakMeter,
                            'radius_meter' => $maxRadius,
                            'di_luar_radius' => true,
                        ], 422);
                    }
                }
            }
        } catch (\Throwable $locErr) {
            error_log('Geofence verification error: ' . $locErr->getMessage());
        }

        try {
            $newId = $this->model->create(
                $karyawanId,
                $pekerjaanId,
                $tanggal,
                $jamMasuk,
                $status,
                $latitude,
                $longitude,
                $alamat,
                $fotoMasuk,
                $jarakMeter
            );
            $record = $this->model->findById($newId);

            // Perbarui status pekerjaan ke 'berjalan' jika tanggal mulai telah tiba
            try {
                require_once dirname(__DIR__) . '/models/Pekerjaan.php';
                $pekerjaanModel = new Pekerjaan(getDatabaseConnection());
                $pekerjaanModel->autoUpdateJobStatusFromAttendance($pekerjaanId);

                // Perbarui status penugasan karyawan ke 'sedang_bekerja' jika belum dikonfirmasi khusus oleh Admin
                $pdo = getDatabaseConnection();
                $updPenugasan = $pdo->prepare('
                    UPDATE penugasan
                    SET status_karyawan = "sedang_bekerja"
                    WHERE id_pekerjaan = :jobId AND id_karyawan = :empId AND status_konfirmasi_admin = 0
                ');
                $updPenugasan->execute([
                    'jobId' => $pekerjaanId,
                    'empId' => $karyawanId,
                ]);
            } catch (\Throwable $syncErr) {
                error_log('Sync status pekerjaan on clockin error: ' . $syncErr->getMessage());
            }

            sendJsonResponse(true, 'Absensi masuk berhasil dicatat', $record, 201);
        } catch (\Throwable $exception) {
            error_log('AbsensiController::store error: ' . $exception->getMessage());
            sendJsonResponse(false, 'Gagal mencatat absensi: ' . $exception->getMessage(), null, 500);
        }
    }

    public function checkout(string $rawId): never
    {
        $id = is_numeric($rawId) ? (int) $rawId : null;
        if ($id === null || $id < 1) {
            sendJsonResponse(false, 'ID absensi tidak valid', null, 422);
        }

        $input = $this->readJsonBody() ?? [];
        $jamPulang = isset($input['jam_pulang']) && is_string($input['jam_pulang']) && $input['jam_pulang'] !== '' ? trim($input['jam_pulang']) : date('H:i:s');
        $latitude = isset($input['latitude']) && is_numeric($input['latitude']) ? (float) $input['latitude'] : null;
        $longitude = isset($input['longitude']) && is_numeric($input['longitude']) ? (float) $input['longitude'] : null;
        $alamat = isset($input['alamat']) && is_string($input['alamat']) ? trim($input['alamat']) : null;
        $fotoPulang = isset($input['foto_pulang']) && is_string($input['foto_pulang']) ? $input['foto_pulang'] : (isset($input['foto']) && is_string($input['foto']) ? $input['foto'] : null);

        try {
            $this->model->checkout($id, $jamPulang, $latitude, $longitude, $alamat, $fotoPulang);
            $record = $this->model->findById($id);

            // Perbarui status penugasan karyawan ke 'selesai_bertugas' jika belum dikonfirmasi khusus oleh Admin
            // PERHATIKAN: Sesuai aturan sistem, absensi pulang TIDAK otomatis mengubah status pekerjaan menjadi selesai.
            if ($record && !empty($record['id_pekerjaan']) && !empty($record['id_karyawan'])) {
                try {
                    $pdo = getDatabaseConnection();
                    $updPenugasan = $pdo->prepare('
                        UPDATE penugasan
                        SET status_karyawan = "selesai_bertugas"
                        WHERE id_pekerjaan = :jobId AND id_karyawan = :empId AND status_konfirmasi_admin = 0
                    ');
                    $updPenugasan->execute([
                        'jobId' => (int) $record['id_pekerjaan'],
                        'empId' => (int) $record['id_karyawan'],
                    ]);
                } catch (\Throwable $syncErr) {
                    error_log('Sync status penugasan on checkout error: ' . $syncErr->getMessage());
                }
            }

            sendJsonResponse(true, 'Absensi pulang berhasil dicatat', $record);
        } catch (\Throwable $exception) {
            error_log('AbsensiController::checkout error: ' . $exception->getMessage());
            sendJsonResponse(false, 'Gagal mencatat absensi pulang', null, 500);
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

