<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readBody(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        respond(400, ['error' => 'Body request harus berupa JSON object.']);
    }
    return $body;
}

function parseLocation(mixed $location): array
{
    if ($location === null) {
        return [null, null, null];
    }
    if (!is_array($location)) {
        respond(400, ['error' => 'Format lokasi tidak valid.']);
    }

    $lat = $location['lat'] ?? null;
    $lng = $location['lng'] ?? null;
    if ($lat === null || $lng === null) {
        return [null, null, isset($location['alamat']) ? (string) $location['alamat'] : null];
    }
    if (!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        respond(400, ['error' => 'Koordinat lokasi tidak valid.']);
    }

    return [(float) $lat, (float) $lng, isset($location['alamat']) ? (string) $location['alamat'] : null];
}

function formatAttendance(array $row): array
{
    $row['lokasi_masuk'] = $row['masuk_latitude'] === null && $row['masuk_alamat'] === null
        ? null
        : [
            'lat' => $row['masuk_latitude'] === null ? null : (float) $row['masuk_latitude'],
            'lng' => $row['masuk_longitude'] === null ? null : (float) $row['masuk_longitude'],
            'alamat' => $row['masuk_alamat'],
        ];
    $row['lokasi_pulang'] = $row['pulang_latitude'] === null && $row['pulang_alamat'] === null
        ? null
        : [
            'lat' => $row['pulang_latitude'] === null ? null : (float) $row['pulang_latitude'],
            'lng' => $row['pulang_longitude'] === null ? null : (float) $row['pulang_longitude'],
            'alamat' => $row['pulang_alamat'],
        ];
    unset(
        $row['masuk_latitude'],
        $row['masuk_longitude'],
        $row['masuk_alamat'],
        $row['pulang_latitude'],
        $row['pulang_longitude'],
        $row['pulang_alamat']
    );
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $pdo = getPDO();
    $method = $_SERVER['REQUEST_METHOD'];
    $resource = $_GET['resource'] ?? '';

    if ($method === 'GET') {
        $queries = [
            'users' => 'SELECT id_user, nama, role, id_karyawan FROM users ORDER BY id_user',
            'karyawan' => 'SELECT id_karyawan, nama, jabatan, no_hp FROM karyawan ORDER BY id_karyawan',
            'pekerjaan' => 'SELECT id_pekerjaan, nama_pekerjaan, deskripsi, TIME_FORMAT(shift_mulai, "%H:%i") AS shift_mulai, TIME_FORMAT(shift_selesai, "%H:%i") AS shift_selesai, status, latitude, longitude, foto_lokasi FROM pekerjaan ORDER BY id_pekerjaan',
            'penugasan' => 'SELECT id_penugasan, id_pekerjaan, id_karyawan FROM penugasan ORDER BY id_penugasan',
            'absensi' => 'SELECT id_absensi, id_pekerjaan, id_karyawan, tanggal, TIME_FORMAT(jam_masuk, "%H:%i") AS jam_masuk, TIME_FORMAT(jam_pulang, "%H:%i") AS jam_pulang, status_verifikasi, foto_masuk, masuk_latitude, masuk_longitude, masuk_alamat, foto_pulang, pulang_latitude, pulang_longitude, pulang_alamat FROM absensi ORDER BY tanggal DESC, jam_masuk DESC',
        ];
        if (!isset($queries[$resource])) {
            respond(404, ['error' => 'Resource tidak ditemukan.']);
        }

        $rows = $pdo->query($queries[$resource])->fetchAll();
        if ($resource === 'absensi') {
            $rows = array_map('formatAttendance', $rows);
        }
        respond(200, ['data' => $rows]);
    }

    if ($resource === 'absensi' && $method === 'POST') {
        $body = readBody();
        $workerId = filter_var($body['id_karyawan'] ?? null, FILTER_VALIDATE_INT);
        $jobId = filter_var($body['id_pekerjaan'] ?? null, FILTER_VALIDATE_INT);
        if (!$workerId || !$jobId) {
            respond(400, ['error' => 'id_karyawan dan id_pekerjaan wajib berupa bilangan bulat.']);
        }

        $assignment = $pdo->prepare(
            "SELECT 1 FROM penugasan p JOIN pekerjaan j ON j.id_pekerjaan = p.id_pekerjaan
             WHERE p.id_karyawan = ? AND p.id_pekerjaan = ? AND j.status = 'aktif'"
        );
        $assignment->execute([$workerId, $jobId]);
        if (!$assignment->fetchColumn()) {
            respond(422, ['error' => 'Pekerja tidak ditugaskan ke pekerjaan aktif tersebut.']);
        }

        $active = $pdo->prepare(
            'SELECT 1 FROM absensi WHERE id_karyawan = ? AND id_pekerjaan = ? AND jam_pulang IS NULL LIMIT 1'
        );
        $active->execute([$workerId, $jobId]);
        if ($active->fetchColumn()) {
            respond(409, ['error' => 'Masih ada sesi aktif untuk pekerjaan tersebut.']);
        }

        $foto = $body['foto'] ?? null;
        if ($foto !== null && !is_string($foto)) {
            respond(400, ['error' => 'Foto harus berupa string data URL.']);
        }
        [$lat, $lng, $address] = parseLocation($body['lokasi'] ?? null);
        $insert = $pdo->prepare(
            "INSERT INTO absensi
             (id_karyawan, id_pekerjaan, tanggal, jam_masuk, status_verifikasi,
              foto_masuk, masuk_latitude, masuk_longitude, masuk_alamat)
             VALUES (?, ?, CURRENT_DATE(), CURRENT_TIME(), 'menunggu', ?, ?, ?, ?)"
        );
        $insert->execute([$workerId, $jobId, $foto, $lat, $lng, $address]);

        $select = $pdo->prepare(
            'SELECT id_absensi, id_pekerjaan, id_karyawan, tanggal,
                    TIME_FORMAT(jam_masuk, "%H:%i") AS jam_masuk,
                    TIME_FORMAT(jam_pulang, "%H:%i") AS jam_pulang,
                    status_verifikasi, foto_masuk, masuk_latitude, masuk_longitude, masuk_alamat,
                    foto_pulang, pulang_latitude, pulang_longitude, pulang_alamat
             FROM absensi WHERE id_absensi = ?'
        );
        $select->execute([(int) $pdo->lastInsertId()]);
        respond(201, ['data' => formatAttendance($select->fetch())]);
    }

    if ($resource === 'absensi' && $method === 'PATCH') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            respond(400, ['error' => 'Parameter id absensi wajib diisi.']);
        }

        $body = readBody();
        [$lat, $lng, $address] = parseLocation($body['lokasi'] ?? null);
        $foto = $body['foto'] ?? null;
        if ($foto !== null && !is_string($foto)) {
            respond(400, ['error' => 'Foto harus berupa string data URL.']);
        }

        $update = $pdo->prepare(
            'UPDATE absensi SET jam_pulang = CURRENT_TIME(), foto_pulang = ?,
             pulang_latitude = ?, pulang_longitude = ?, pulang_alamat = ?
             WHERE id_absensi = ? AND jam_pulang IS NULL'
        );
        $update->execute([$foto, $lat, $lng, $address, $id]);
        if ($update->rowCount() !== 1) {
            respond(404, ['error' => 'Sesi absensi aktif tidak ditemukan.']);
        }
        respond(200, ['message' => 'Absen pulang berhasil disimpan.']);
    }

    if ($resource === 'pekerjaan' && $method === 'PATCH') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $body = readBody();
        if (!$id || !isset($body['latitude'], $body['longitude']) ||
            !is_numeric($body['latitude']) || !is_numeric($body['longitude']) ||
            $body['latitude'] < -90 || $body['latitude'] > 90 ||
            $body['longitude'] < -180 || $body['longitude'] > 180) {
            respond(400, ['error' => 'ID pekerjaan dan koordinat latitude/longitude yang valid wajib diisi.']);
        }

        $update = $pdo->prepare(
            'UPDATE pekerjaan SET latitude = ?, longitude = ?, foto_lokasi = ?
             WHERE id_pekerjaan = ?'
        );
        $update->execute([
            $body['latitude'],
            $body['longitude'],
            $body['foto_lokasi'] ?? null,
            $id,
        ]);
        if ($update->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM pekerjaan WHERE id_pekerjaan = ?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) {
                respond(404, ['error' => 'Pekerjaan tidak ditemukan.']);
            }
        }
        respond(200, ['message' => 'Lokasi pekerjaan berhasil disimpan.']);
    }

    respond(405, ['error' => 'Metode tidak didukung untuk resource tersebut.']);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(500, ['error' => 'Terjadi kesalahan pada server. Periksa log PHP untuk detailnya.']);
}