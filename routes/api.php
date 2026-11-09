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
        'DELETE' => static function (string $id) use ($getKaryawanController): never {
            ($getKaryawanController())->destroy($id);
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
    ],
    '/api/absensi/{id}' => [
        'GET' => static function (string $id) use ($getAbsensiController): never {
            ($getAbsensiController())->show($id);
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
];
