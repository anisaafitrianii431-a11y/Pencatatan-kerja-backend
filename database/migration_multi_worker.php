<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$db = getDatabaseConnection();

echo "Running migration for Multi-Worker and Quota System...\n";

// 1. Update ENUM pada pekerjaan.metode_penentuan
try {
    $db->exec("ALTER TABLE `pekerjaan` MODIFY COLUMN `metode_penentuan` ENUM('langsung', 'terbuka', 'gabungan') NOT NULL DEFAULT 'terbuka'");
    echo "✔ pekerjaan.metode_penentuan updated to ENUM('langsung','terbuka','gabungan')\n";
} catch (\Throwable $e) {
    echo "Notice metode_penentuan: " . $e->getMessage() . "\n";
}

// 2. Tambah kuota_pekerja pada pekerjaan
$colsPekerjaan = $db->query("SHOW COLUMNS FROM `pekerjaan`")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('kuota_pekerja', $colsPekerjaan, true)) {
    $db->exec("ALTER TABLE `pekerjaan` ADD COLUMN `kuota_pekerja` INT NULL DEFAULT NULL AFTER `metode_penentuan`");
    echo "✔ Column pekerjaan.kuota_pekerja added\n";
} else {
    echo "✔ Column pekerjaan.kuota_pekerja already exists\n";
}

if (!in_array('status_pengambilan', $colsPekerjaan, true)) {
    $db->exec("ALTER TABLE `pekerjaan` ADD COLUMN `status_pengambilan` ENUM('terbuka', 'ditutup') NOT NULL DEFAULT 'terbuka' AFTER `kuota_pekerja`");
    echo "✔ Column pekerjaan.status_pengambilan added\n";
} else {
    echo "✔ Column pekerjaan.status_pengambilan already exists\n";
}

// 3. Tambah status_penugasan pada penugasan
$colsPenugasan = $db->query("SHOW COLUMNS FROM `penugasan`")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('status_penugasan', $colsPenugasan, true)) {
    $db->exec("ALTER TABLE `penugasan` ADD COLUMN `status_penugasan` ENUM('aktif', 'dibatalkan') NOT NULL DEFAULT 'aktif' AFTER `jenis_penugasan`");
    echo "✔ Column penugasan.status_penugasan added\n";
} else {
    echo "✔ Column penugasan.status_penugasan already exists\n";
}

if (!in_array('alasan_batal', $colsPenugasan, true)) {
    $db->exec("ALTER TABLE `penugasan` ADD COLUMN `alasan_batal` VARCHAR(255) NULL AFTER `catatan_admin`");
    echo "✔ Column penugasan.alasan_batal added\n";
} else {
    echo "✔ Column penugasan.alasan_batal already exists\n";
}

if (!in_array('dibatalkan_pada', $colsPenugasan, true)) {
    $db->exec("ALTER TABLE `penugasan` ADD COLUMN `dibatalkan_pada` DATETIME NULL AFTER `alasan_batal`");
    echo "✔ Column penugasan.dibatalkan_pada added\n";
} else {
    echo "✔ Column penugasan.dibatalkan_pada already exists\n";
}

if (!in_array('dibatalkan_oleh', $colsPenugasan, true)) {
    $db->exec("ALTER TABLE `penugasan` ADD COLUMN `dibatalkan_oleh` VARCHAR(100) NULL AFTER `dibatalkan_pada`");
    echo "✔ Column penugasan.dibatalkan_oleh added\n";
} else {
    echo "✔ Column penugasan.dibatalkan_oleh already exists\n";
}

echo "Migration completed successfully!\n";

