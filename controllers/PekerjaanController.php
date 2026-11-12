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
        'gabungan',
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
            $workerId = null;
            if (isset($_GET['worker_id']) && is_numeric($_GET['worker_id'])) {
                $workerId = (int) $_GET['worker_id'];
            } elseif (isset($_GET['id_karyawan']) && is_numeric($_GET['id_karyawan'])) {
                $workerId = (int) $_GET['id_karyawan'];
            }

            sendJsonResponse(
                true,
                'Daftar pekerjaan tersedia berhasil diambil',
                $this->model->getAvailableJobs($workerId),
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
                $validated['latitude'] ?? null,
                $validated['longitude'] ?? null,
                $validated['radius_meter'] ?? 100,
                $validated['kuota_pekerja'] ?? null,
                $validated['status_pengambilan'] ?? 'terbuka',
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
                $validated['latitude'] ?? null,
                $validated['longitude'] ?? null,
                $validated['radius_meter'] ?? null,
                $validated['kuota_pekerja'] ?? null,
                $validated['status_pengambilan'] ?? null,
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

            if ($result['status'] === 'already_claimed_by_you' || $result['status'] === 'quota_full') {
                sendJsonResponse(false, $result['message'], null, 409);
            }

            if ($result['status'] === 'expired' || $result['status'] === 'not_open' || $result['status'] === 'closed' || $result['status'] === 'completed') {
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

    public function togglePengambilan(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        $input = $this->readJsonBody() ?? [];
        $status = isset($input['status']) && in_array($input['status'], ['terbuka', 'ditutup'], true)
            ? $input['status']
            : null;

        try {
            $res = $this->model->toggleStatusPengambilan($id, $status);
            if (!$res['success']) {
                sendJsonResponse(false, $res['message'], null, 404);
            }

            sendJsonResponse(true, $res['message'], ['status_pengambilan' => $res['status_pengambilan']]);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function cancelWorkerAssignment(string $rawJobId, string $rawAssignmentId): never
    {
        $jobId = $this->parseId($rawJobId);
        $assignmentId = $this->parseId($rawAssignmentId);
        if ($jobId === null || $assignmentId === null) {
            sendJsonResponse(false, 'ID pekerjaan atau ID penugasan tidak valid', null, 422);
        }

        $input = $this->readJsonBody() ?? [];
        $alasan = isset($input['alasan']) && is_string($input['alasan']) ? trim($input['alasan']) : 'Dibatalkan oleh Administrator';

        try {
            $res = $this->model->cancelAssignment($jobId, $assignmentId, $alasan, 'admin');
            if (!$res['success']) {
                sendJsonResponse(false, $res['message'], null, 400);
            }

            sendJsonResponse(true, $res['message'], $res['assignment']);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function addWorker(string $rawJobId): never
    {
        $jobId = $this->parseId($rawJobId);
        if ($jobId === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        $input = $this->readJsonBody() ?? [];
        $employeeId = $this->parseInputId($input['id_karyawan'] ?? null);
        if ($employeeId === null) {
            sendJsonResponse(false, 'id_karyawan wajib diisi dengan ID karyawan yang valid', null, 422);
        }

        try {
            $res = $this->model->addWorkerToJob($jobId, $employeeId);
            if (!$res['success']) {
                sendJsonResponse(false, $res['message'], null, 400);
            }

            sendJsonResponse(true, $res['message'], ['id_penugasan' => $res['id_penugasan']], 201);
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

    public function pekerja(string $rawId): never
    {
        $id = $this->parseId($rawId);
        if ($id === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        try {
            $workers = $this->model->getWorkersWithStatus($id);
            sendJsonResponse(true, 'Data pekerja dan kehadiran berhasil diambil', $workers);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function updateStatusPekerja(string $rawJobId, string $rawEmployeeId): never
    {
        $jobId = $this->parseId($rawJobId);
        $employeeId = $this->parseId($rawEmployeeId);
        if ($jobId === null || $employeeId === null) {
            sendJsonResponse(false, 'ID pekerjaan atau ID karyawan tidak valid', null, 422);
        }

        $input = $this->readJsonBody() ?? [];
        $status = $input['status'] ?? null;
        $catatan = isset($input['catatan']) && is_string($input['catatan']) ? trim($input['catatan']) : null;

        $allowed = ['belum_hadir', 'sedang_bekerja', 'selesai_bertugas', 'tidak_hadir', 'meninggalkan_pekerjaan', 'otomatis'];
        if (!in_array($status, $allowed, true)) {
            sendJsonResponse(false, 'Status pekerja tidak valid', null, 422);
        }

        try {
            $updated = $this->model->updateWorkerStatus($jobId, $employeeId, (string) $status, $catatan);
            if (!$updated) {
                sendJsonResponse(false, 'Gagal memperbarui status pekerja', null, 400);
            }

            sendJsonResponse(true, 'Status pekerja berhasil diperbarui', [
                'id_pekerjaan' => $jobId,
                'id_karyawan' => $employeeId,
                'status' => $status,
                'catatan' => $catatan,
            ]);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function laporan(string $rawJobId): never
    {
        $jobId = $this->parseId($rawJobId);
        if ($jobId === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        try {
            require_once dirname(__DIR__) . '/models/Laporan.php';
            $laporanModel = new Laporan(getDatabaseConnection());
            $reports = $laporanModel->getByJob($jobId);
            sendJsonResponse(true, 'Daftar laporan pekerjaan berhasil diambil', $reports);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function storeLaporan(string $rawJobId): never
    {
        $jobId = $this->parseId($rawJobId);
        if ($jobId === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa JSON yang valid', null, 422);
        }

        $employeeId = $this->parseInputId($input['id_karyawan'] ?? null);
        $judul = trim((string) ($input['judul'] ?? 'Laporan Penyelesaian Pekerjaan'));
        $keterangan = trim((string) ($input['keterangan'] ?? ''));
        $tipe = in_array($input['tipe_laporan'] ?? '', ['progres', 'akhir'], true) ? $input['tipe_laporan'] : 'akhir';
        $foto = isset($input['foto_dokumentasi']) && is_string($input['foto_dokumentasi']) ? $input['foto_dokumentasi'] : null;
        $tanggal = isset($input['tanggal_laporan']) && is_string($input['tanggal_laporan']) ? $input['tanggal_laporan'] : null;

        if ($employeeId === null || $employeeId <= 0) {
            sendJsonResponse(false, 'ID karyawan wajib diisi', null, 422);
        }
        if ($judul === '') {
            sendJsonResponse(false, 'Judul laporan wajib diisi', null, 422);
        }

        try {
            require_once dirname(__DIR__) . '/models/Laporan.php';
            $laporanModel = new Laporan(getDatabaseConnection());
            $created = $laporanModel->create($jobId, $employeeId, $tipe, $judul, $keterangan, $foto, $tanggal);
            sendJsonResponse(true, 'Laporan pekerjaan berhasil dikirim', $created, 201);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function setujuiLaporan(string $rawLaporanId): never
    {
        $laporanId = $this->parseId($rawLaporanId);
        if ($laporanId === null) {
            sendJsonResponse(false, 'ID laporan tidak valid', null, 422);
        }

        $input = $this->readJsonBody() ?? [];
        $catatan = isset($input['catatan']) && is_string($input['catatan']) ? trim($input['catatan']) : null;

        try {
            require_once dirname(__DIR__) . '/models/Laporan.php';
            $laporanModel = new Laporan(getDatabaseConnection());
            $res = $laporanModel->approve($laporanId, $catatan);
            if (!$res['success']) {
                sendJsonResponse(false, $res['message'] ?? 'Gagal menyetujui laporan', null, 400);
            }
            sendJsonResponse(true, 'Laporan pekerjaan berhasil disetujui.', $res['report']);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function tolakLaporan(string $rawLaporanId): never
    {
        $laporanId = $this->parseId($rawLaporanId);
        if ($laporanId === null) {
            sendJsonResponse(false, 'ID laporan tidak valid', null, 422);
        }

        $input = $this->readJsonBody() ?? [];
        $catatan = isset($input['catatan']) && is_string($input['catatan']) ? trim($input['catatan']) : 'Laporan belum memenuhi standar penyelesaian';

        try {
            require_once dirname(__DIR__) . '/models/Laporan.php';
            $laporanModel = new Laporan(getDatabaseConnection());
            $res = $laporanModel->reject($laporanId, $catatan);
            if (!$res['success']) {
                sendJsonResponse(false, $res['message'] ?? 'Gagal menolak laporan', null, 400);
            }
            sendJsonResponse(true, 'Laporan pekerjaan ditolak dengan catatan evaluasi.', $res['report']);
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
        if ($status === null || $status === '') {
            $status = 'belum_mulai';
        } elseif (!is_string($status) || !in_array($status, self::ALLOWED_STATUSES, true)) {
            return ['error' => 'Status harus bernilai belum_mulai, berjalan, atau selesai'];
        }

        $method = $input['metode_penentuan'] ?? 'terbuka';
        if (!is_string($method) || !in_array($method, self::ALLOWED_METHODS, true)) {
            return ['error' => 'Metode penentuan harus bernilai langsung, terbuka, atau gabungan'];
        }

        $employeeIds = [];
        if (in_array($method, ['langsung', 'gabungan'], true)) {
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
            if ($method === 'langsung' && empty($employeeIds)) {
                return ['error' => 'Silakan pilih minimal satu pekerja untuk metode penetapan oleh Admin'];
            }
        }

        // Validasi kuota pekerja
        $kuotaPekerja = null;
        if (isset($input['kuota_pekerja']) && $input['kuota_pekerja'] !== '' && $input['kuota_pekerja'] !== null) {
            $parsedKuota = filter_var($input['kuota_pekerja'], FILTER_VALIDATE_INT);
            if ($parsedKuota !== false && $parsedKuota > 0) {
                $kuotaPekerja = $parsedKuota;
            }
        }

        // Jika metode gabungan dan kuota ditentukan, kuota minimal sebesar karyawan yang dipilih admin
        if ($method === 'gabungan' && $kuotaPekerja !== null && count($employeeIds) > $kuotaPekerja) {
            return ['error' => 'Jumlah karyawan yang dipilih Admin (' . count($employeeIds) . ' orang) tidak boleh melebihi kuota maksimal (' . $kuotaPekerja . ' orang)'];
        }

        $statusPengambilan = in_array($input['status_pengambilan'] ?? '', ['terbuka', 'ditutup'], true)
            ? $input['status_pengambilan']
            : ($method === 'langsung' ? 'ditutup' : 'terbuka');

        $latitude = isset($input['latitude']) && is_numeric($input['latitude']) ? (float) $input['latitude'] : null;
        $longitude = isset($input['longitude']) && is_numeric($input['longitude']) ? (float) $input['longitude'] : null;
        $radiusMeter = isset($input['radius_meter']) && is_numeric($input['radius_meter']) ? (int) $input['radius_meter'] : 100;

        return [
            'nama_pekerjaan' => $name,
            'deskripsi' => $description,
            'lokasi' => $location,
            'tanggal_mulai' => $startDate['value'],
            'target_selesai' => $targetDate['value'],
            'status' => $status,
            'metode_penentuan' => $method,
            'id_karyawan_list' => $employeeIds,
            'kuota_pekerja' => $kuotaPekerja,
            'status_pengambilan' => $statusPengambilan,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_meter' => $radiusMeter,
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
