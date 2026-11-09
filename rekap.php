<?php
// Router Penengah: Mengarahkan permintaan rekap ke modul Admin atau Karyawan sesuai sesi
require_once __DIR__ . '/config/database.php';
$currentUser = requireLogin();

if ($currentUser['peran'] === 'admin') {
    require __DIR__ . '/admin/rekap.php';
} else {
    require __DIR__ . '/karyawan/rekap.php';
}

