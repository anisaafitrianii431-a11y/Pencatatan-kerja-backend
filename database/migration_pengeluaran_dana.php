<?php
require_once __DIR__ . '/../../frontend/api/config/database.php';

try {
    echo "Running migration for pengeluaran, dana_kantor, and pengembalian_dana...\n";

    // 1. Alter pengeluaran if columns don't exist
    $cols = $pdo->query("SHOW COLUMNS FROM pengeluaran")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('kategori', $cols)) {
        $pdo->exec("ALTER TABLE pengeluaran ADD COLUMN kategori VARCHAR(50) NOT NULL DEFAULT 'operasional' AFTER nominal");
        echo "Added 'kategori' to pengeluaran.\n";
    }
    if (!in_array('id_dana', $cols)) {
        $pdo->exec("ALTER TABLE pengeluaran ADD COLUMN id_dana INT NULL DEFAULT NULL AFTER jenis_dana");
        echo "Added 'id_dana' to pengeluaran.\n";
    }
    if (!in_array('id_penanggung_jawab', $cols)) {
        $pdo->exec("ALTER TABLE pengeluaran ADD COLUMN id_penanggung_jawab INT NULL DEFAULT NULL AFTER id_dana");
        echo "Added 'id_penanggung_jawab' to pengeluaran.\n";
    }
    if (!in_array('catatan_penolakan', $cols)) {
        $pdo->exec("ALTER TABLE pengeluaran ADD COLUMN catatan_penolakan TEXT NULL DEFAULT NULL AFTER status_ganti");
        echo "Added 'catatan_penolakan' to pengeluaran.\n";
    }

    // 2. Create dana_kantor table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dana_kantor (
            id_dana INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            sumber_dana VARCHAR(100) NOT NULL DEFAULT 'Kas Perusahaan',
            pemberi_dana VARCHAR(100) NOT NULL,
            penerima_dana VARCHAR(100) NOT NULL,
            id_penanggung_jawab INT NOT NULL,
            nominal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            keterangan TEXT NULL,
            bukti_transaksi VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dana_pj (id_penanggung_jawab),
            INDEX idx_dana_tgl (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table 'dana_kantor' verified/created.\n";

    // 3. Create pengembalian_dana table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pengembalian_dana (
            id_pengembalian INT AUTO_INCREMENT PRIMARY KEY,
            id_dana INT NULL DEFAULT NULL,
            id_karyawan INT NOT NULL,
            id_penanggung_jawab INT NOT NULL,
            tanggal DATE NOT NULL,
            nominal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            penerima_pengembalian VARCHAR(100) NOT NULL,
            keterangan TEXT NULL,
            bukti_pengembalian VARCHAR(255) NULL,
            status_verifikasi ENUM('menunggu', 'disetujui', 'ditolak') NOT NULL DEFAULT 'menunggu',
            catatan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_kembali_karyawan (id_karyawan),
            INDEX idx_kembali_pj (id_penanggung_jawab),
            INDEX idx_kembali_tgl (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Table 'pengembalian_dana' verified/created.\n";

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}

