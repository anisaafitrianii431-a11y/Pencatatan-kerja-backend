<?php

declare(strict_types=1);

final class LokasiKerjaController
{
    public function __construct(private readonly LokasiKerja $model)
    {
    }

    public function index(): never
    {
        try {
            sendJsonResponse(
                true,
                'Data lokasi kerja berhasil dimuat',
                $this->model->getAll(),
            );
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function show(string $rawPekerjaanId): never
    {
        $id = $this->parseId($rawPekerjaanId);
        if ($id === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        try {
            $lokasi = $this->model->findByPekerjaanId($id);
            if ($lokasi === null) {
                sendJsonResponse(false, 'Lokasi kerja untuk pekerjaan ini belum disetel', null, 404);
            }

            sendJsonResponse(true, 'Data lokasi kerja berhasil dimuat', $lokasi);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function store(): never
    {
        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa JSON yang valid', null, 422);
        }

        $pekerjaanId = $this->parseId((string) ($input['id_pekerjaan'] ?? ''));
        if ($pekerjaanId === null) {
            sendJsonResponse(false, 'id_pekerjaan wajib diisi dengan angka valid', null, 422);
        }

        $latitude = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($latitude === false || $longitude === false) {
            sendJsonResponse(false, 'Latitude dan Longitude wajib berupa angka koordinat valid', null, 422);
        }

        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            sendJsonResponse(false, 'Nilai Latitude (-90 s/d 90) atau Longitude (-180 s/d 180) di luar rentang bumi', null, 422);
        }

        $namaLokasi = isset($input['nama_lokasi']) ? trim((string) $input['nama_lokasi']) : null;
        $alamat = isset($input['alamat']) ? trim((string) $input['alamat']) : null;
        $radiusMeter = isset($input['radius_meter']) ? (int) $input['radius_meter'] : 100;
        $fotoLokasi = isset($input['foto_lokasi']) ? (string) $input['foto_lokasi'] : null;

        try {
            $result = $this->model->save(
                $pekerjaanId,
                $namaLokasi,
                $alamat,
                (float) $latitude,
                (float) $longitude,
                $radiusMeter,
                $fotoLokasi,
            );

            sendJsonResponse(true, 'Titik koordinat dan lokasi kerja berhasil disimpan', $result, 200);
        } catch (\InvalidArgumentException $e) {
            sendJsonResponse(false, $e->getMessage(), null, 404);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function update(string $rawPekerjaanId): never
    {
        $input = $this->readJsonBody();
        if ($input === null) {
            sendJsonResponse(false, 'Body harus berupa JSON yang valid', null, 422);
        }

        $input['id_pekerjaan'] = $rawPekerjaanId;
        $_POST_DATA_EMULATED = $input;

        $pekerjaanId = $this->parseId($rawPekerjaanId);
        if ($pekerjaanId === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        $latitude = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($latitude === false || $longitude === false) {
            sendJsonResponse(false, 'Latitude dan Longitude wajib berupa angka koordinat valid', null, 422);
        }

        $namaLokasi = isset($input['nama_lokasi']) ? trim((string) $input['nama_lokasi']) : null;
        $alamat = isset($input['alamat']) ? trim((string) $input['alamat']) : null;
        $radiusMeter = isset($input['radius_meter']) ? (int) $input['radius_meter'] : 100;
        $fotoLokasi = isset($input['foto_lokasi']) ? (string) $input['foto_lokasi'] : null;

        try {
            $result = $this->model->save(
                $pekerjaanId,
                $namaLokasi,
                $alamat,
                (float) $latitude,
                (float) $longitude,
                $radiusMeter,
                $fotoLokasi,
            );

            sendJsonResponse(true, 'Lokasi kerja berhasil diperbarui', $result, 200);
        } catch (\InvalidArgumentException $e) {
            sendJsonResponse(false, $e->getMessage(), null, 404);
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    public function destroy(string $rawPekerjaanId): never
    {
        $id = $this->parseId($rawPekerjaanId);
        if ($id === null) {
            sendJsonResponse(false, 'ID pekerjaan tidak valid', null, 422);
        }

        try {
            $deleted = $this->model->delete($id);
            if ($deleted) {
                sendJsonResponse(true, 'Lokasi kerja berhasil dihapus', null);
            } else {
                sendJsonResponse(false, 'Gagal menghapus lokasi kerja', null, 400);
            }
        } catch (\Throwable $exception) {
            $this->respondWithServerError($exception);
        }
    }

    private function parseId(string $rawId): ?int
    {
        if (!ctype_digit($rawId)) {
            return null;
        }

        $id = (int) $rawId;
        return $id > 0 ? $id : null;
    }

    private function readJsonBody(): ?array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private function respondWithServerError(\Throwable $exception): never
    {
        error_log('LokasiKerjaController error: ' . $exception->getMessage());
        sendJsonResponse(false, 'Terjadi kesalahan pada server saat mengelola lokasi kerja: ' . $exception->getMessage(), null, 500);
    }
}

