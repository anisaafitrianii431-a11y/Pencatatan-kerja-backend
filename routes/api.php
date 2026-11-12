<?php

declare(strict_types=1);

$getKaryawanController = static function (): KaryawanController {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'Karyawan.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'KaryawanController.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    return new KaryawanController(new Karyawan(getDatabaseConnection()));
};

$getPekerjaanController = static function (): PekerjaanController {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'Pekerjaan.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PekerjaanController.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    return new PekerjaanController(new Pekerjaan(getDatabaseConnection()));
};

$getPenugasanController = static function (): PenugasanController {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'Penugasan.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PenugasanController.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    return new PenugasanController(new Penugasan(getDatabaseConnection()));
};

$getAbsensiController = static function (): AbsensiController {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'Absensi.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'AbsensiController.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    return new AbsensiController(new Absensi(getDatabaseConnection()));
};

$getLokasiKerjaController = static function (): LokasiKerjaController {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'LokasiKerja.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'LokasiKerjaController.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    return new LokasiKerjaController(new LokasiKerja(getDatabaseConnection()));
};

$getUserController = static function (): UserController {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'User.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'UserController.php';
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    return new UserController(new User(getDatabaseConnection()));
};

return [
    '/api' => [
        'GET' => static function (): never {
            sendJsonResponse(
                true,
                'API Pencatatan Kerja berhasil berjalan',
                ['version' => '1.0.0'],
            );
        },
    ],
    '/api/karyawan' => [
        'GET' => static function () use ($getKaryawanController): never {
            ($getKaryawanController())->index();
        },
        'POST' => static function () use ($getKaryawanController): never {
            ($getKaryawanController())->store();
        },
    ],
    '/api/karyawan/{id}' => [
        'GET' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->show($id);
        },
        'PUT' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->update($id);
        },
        'PATCH' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->update($id);
        },
        'DELETE' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->destroy($id);
        },
    ],
    '/api/karyawan/{id}/status' => [
        'POST' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->toggleStatus($id);
        },
        'PUT' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->toggleStatus($id);
        },
        'PATCH' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->toggleStatus($id);
        },
    ],
    '/api/karyawan/{id}/reset-password' => [
        'POST' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->resetPassword($id);
        },
        'PUT' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->resetPassword($id);
        },
        'PATCH' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->resetPassword($id);
        },
    ],
    '/api/pekerjaan' => [
        'GET' => static function () use ($getPekerjaanController): never {
            ($getPekerjaanController())->index();
        },
        'POST' => static function () use ($getPekerjaanController): never {
            ($getPekerjaanController())->store();
        },
    ],
    '/api/pekerjaan/tersedia' => [
        'GET' => static function () use ($getPekerjaanController): never {
            ($getPekerjaanController())->tersedia();
        },
    ],
    '/api/pekerjaan/{id}/ambil' => [
        'POST' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->claim($id);
        },
    ],
    '/api/pekerjaan/{id}/status-pengambilan' => [
        'POST' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->togglePengambilan($id);
        },
        'PUT' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->togglePengambilan($id);
        },
    ],
    '/api/pekerjaan/{id}/pekerja/{assignment_id}/batalkan' => [
        'POST' => static function (string $id, string $assignment_id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->cancelWorkerAssignment($id, $assignment_id);
        },
        'PUT' => static function (string $id, string $assignment_id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->cancelWorkerAssignment($id, $assignment_id);
        },
        'DELETE' => static function (string $id, string $assignment_id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->cancelWorkerAssignment($id, $assignment_id);
        },
    ],
    '/api/pekerjaan/{id}/tambah-pekerja' => [
        'POST' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->addWorker($id);
        },
    ],
    '/api/pekerjaan/{id}/pekerja' => [
        'GET' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->pekerja($id);
        },
    ],
    '/api/pekerjaan/{id}/pekerja/{karyawan_id}/status' => [
        'POST' => static function (string $id, string $karyawan_id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->updateStatusPekerja($id, $karyawan_id);
        },
        'PUT' => static function (string $id, string $karyawan_id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->updateStatusPekerja($id, $karyawan_id);
        },
        'PATCH' => static function (string $id, string $karyawan_id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->updateStatusPekerja($id, $karyawan_id);
        },
    ],
    '/api/pekerjaan/{id}/laporan' => [
        'GET' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->laporan($id);
        },
        'POST' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->storeLaporan($id);
        },
    ],
    '/api/pekerjaan/laporan/{id}/setujui' => [
        'POST' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->setujuiLaporan($id);
        },
        'PUT' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->setujuiLaporan($id);
        },
    ],
    '/api/pekerjaan/laporan/{id}/tolak' => [
        'POST' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->tolakLaporan($id);
        },
        'PUT' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->tolakLaporan($id);
        },
    ],
    '/api/pekerjaan/{id}' => [
        'GET' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->show($id);
        },
        'PUT' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->update($id);
        },
        'DELETE' => static function (string $id) use ($getPekerjaanController): never {
            ($getPekerjaanController())->destroy($id);
        },
    ],
    '/api/penugasan' => [
        'GET' => static function () use ($getPenugasanController): never {
            ($getPenugasanController())->index();
        },
        'POST' => static function () use ($getPenugasanController): never {
            ($getPenugasanController())->store();
        },
    ],
    '/api/penugasan/{id}' => [
        'GET' => static function (string $id) use ($getPenugasanController): never {
            ($getPenugasanController())->show($id);
        },
        'PUT' => static function (string $id) use ($getPenugasanController): never {
            ($getPenugasanController())->update($id);
        },
        'DELETE' => static function (string $id) use ($getPenugasanController): never {
            ($getPenugasanController())->destroy($id);
        },
    ],
    '/api/absensi' => [
        'GET' => static function () use ($getAbsensiController): never {
            ($getAbsensiController())->index();
        },
        'POST' => static function () use ($getAbsensiController): never {
            ($getAbsensiController())->store();
        },
    ],
    '/api/absensi/{id}' => [
        'GET' => static function (string $id) use ($getAbsensiController): never {
            ($getAbsensiController())->show($id);
        },
        'PUT' => static function (string $id) use ($getAbsensiController): never {
            ($getAbsensiController())->checkout($id);
        },
    ],
    '/api/absensi/{id}/pulang' => [
        'POST' => static function (string $id) use ($getAbsensiController): never {
            ($getAbsensiController())->checkout($id);
        },
    ],
    '/api/absensi/{id}/verifikasi' => [
        'PUT' => static function (string $id) use ($getAbsensiController): never {
            ($getAbsensiController())->verify($id);
        },
        'POST' => static function (string $id) use ($getAbsensiController): never {
            ($getAbsensiController())->verify($id);
        },
    ],
    '/api/lokasi' => [
        'GET' => static function () use ($getLokasiKerjaController): never {
            ($getLokasiKerjaController())->index();
        },
        'POST' => static function () use ($getLokasiKerjaController): never {
            ($getLokasiKerjaController())->store();
        },
    ],
    '/api/lokasi/{id}' => [
        'GET' => static function (string $id) use ($getLokasiKerjaController): never {
            ($getLokasiKerjaController())->show($id);
        },
        'PUT' => static function (string $id) use ($getLokasiKerjaController): never {
            ($getLokasiKerjaController())->update($id);
        },
        'DELETE' => static function (string $id) use ($getLokasiKerjaController): never {
            ($getLokasiKerjaController())->destroy($id);
        },
    ],
    '/api/users' => [
        'GET' => static function () use ($getUserController): never {
            ($getUserController())->index();
        },
    ],
    '/api/users/{id}' => [
        'GET' => static function (string $id) use ($getUserController): never {
            ($getUserController())->show($id);
        },
    ],
    '/api/users/{id}/reset-password' => [
        'POST' => static function (string $id) use ($getUserController): never {
            ($getUserController())->resetPassword($id);
        },
        'PUT' => static function (string $id) use ($getUserController): never {
            ($getUserController())->resetPassword($id);
        },
        'PATCH' => static function (string $id) use ($getUserController): never {
            ($getUserController())->resetPassword($id);
        },
    ],
    '/api/users/{id}/status' => [
        'POST' => static function (string $id) use ($getUserController): never {
            ($getUserController())->toggleStatus($id);
        },
        'PUT' => static function (string $id) use ($getUserController): never {
            ($getUserController())->toggleStatus($id);
        },
        'PATCH' => static function (string $id) use ($getUserController): never {
            ($getUserController())->toggleStatus($id);
        },
    ],
];
