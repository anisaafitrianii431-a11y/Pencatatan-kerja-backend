CREATE DATABASE IF NOT EXISTS `db_pencatatan_kerja`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `db_pencatatan_kerja`;

CREATE TABLE IF NOT EXISTS `karyawan` (
  `id_karyawan` INT AUTO_INCREMENT PRIMARY KEY,
  `nama` VARCHAR(100) NOT NULL,
  `jabatan` VARCHAR(100) NULL,
  `tarif_per_jam` DECIMAL(12,2) NOT NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user` (
  `id_user` INT AUTO_INCREMENT PRIMARY KEY,
  `id_karyawan` INT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `kata_sandi` VARCHAR(255) NOT NULL,
  `peran` ENUM('admin','karyawan') NOT NULL,
  `aktif` BOOLEAN NOT NULL,
  CONSTRAINT `fk_user_karyawan`
    FOREIGN KEY (`id_karyawan`) REFERENCES `karyawan` (`id_karyawan`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pekerjaan` (
  `id_pekerjaan` INT AUTO_INCREMENT PRIMARY KEY,
  `nama_pekerjaan` VARCHAR(150) NOT NULL,
  `deskripsi` TEXT NULL,
  `lokasi` VARCHAR(150) NULL,
  `tanggal_mulai` DATE NULL,
  `target_selesai` DATE NULL,
  `status` ENUM('belum_mulai','berjalan','selesai') NOT NULL,
  `metode_penentuan` ENUM('langsung','terbuka') NOT NULL DEFAULT 'terbuka'
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `penugasan` (
  `id_penugasan` INT AUTO_INCREMENT PRIMARY KEY,
  `id_pekerjaan` INT NOT NULL,
  `id_karyawan` INT NOT NULL,
  `jenis_penugasan` ENUM('admin','mandiri') NOT NULL DEFAULT 'admin',
  `tanggal_penugasan` DATE NOT NULL DEFAULT (CURRENT_DATE),
  CONSTRAINT `fk_penugasan_pekerjaan`
    FOREIGN KEY (`id_pekerjaan`) REFERENCES `pekerjaan` (`id_pekerjaan`),
  CONSTRAINT `fk_penugasan_karyawan`
    FOREIGN KEY (`id_karyawan`) REFERENCES `karyawan` (`id_karyawan`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `absensi` (
  `id_absensi` INT AUTO_INCREMENT PRIMARY KEY,
  `id_pekerjaan` INT NOT NULL,
  `id_karyawan` INT NOT NULL,
  `tanggal` DATE NOT NULL,
  `jam_masuk` TIME NOT NULL,
  `jam_pulang` TIME NULL,
  `status_verifikasi` ENUM('menunggu','disetujui','ditolak') NOT NULL,
  CONSTRAINT `fk_absensi_pekerjaan`
    FOREIGN KEY (`id_pekerjaan`) REFERENCES `pekerjaan` (`id_pekerjaan`),
  CONSTRAINT `fk_absensi_karyawan`
    FOREIGN KEY (`id_karyawan`) REFERENCES `karyawan` (`id_karyawan`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pengeluaran` (
  `id_pengeluaran` INT AUTO_INCREMENT PRIMARY KEY,
  `id_pekerjaan` INT NOT NULL,
  `id_karyawan` INT NOT NULL,
  `tanggal` DATE NOT NULL,
  `nominal` DECIMAL(12,2) NOT NULL,
  `jenis_dana` ENUM('kantor','pribadi') NOT NULL,
  `keterangan` VARCHAR(255) NULL,
  `foto_kwitansi` VARCHAR(255) NOT NULL,
  `status_verifikasi` ENUM('menunggu','disetujui','ditolak') NOT NULL,
  `status_ganti` ENUM('belum','sudah') NOT NULL,
  CONSTRAINT `fk_pengeluaran_pekerjaan`
    FOREIGN KEY (`id_pekerjaan`) REFERENCES `pekerjaan` (`id_pekerjaan`),
  CONSTRAINT `fk_pengeluaran_karyawan`
    FOREIGN KEY (`id_karyawan`) REFERENCES `karyawan` (`id_karyawan`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gaji` (
  `id_gaji` INT AUTO_INCREMENT PRIMARY KEY,
  `id_karyawan` INT NOT NULL,
  `periode` CHAR(7) NOT NULL,
  `total_jam` DECIMAL(6,2) NOT NULL,
  `tarif` DECIMAL(12,2) NOT NULL,
  `total_upah` DECIMAL(14,2) NOT NULL,
  `total_ganti` DECIMAL(14,2) NOT NULL,
  `total_dibayar` DECIMAL(14,2) NOT NULL,
  `status_bayar` ENUM('belum','sudah') NOT NULL,
  CONSTRAINT `uq_gaji_karyawan_periode` UNIQUE (`id_karyawan`, `periode`),
  CONSTRAINT `fk_gaji_karyawan`
    FOREIGN KEY (`id_karyawan`) REFERENCES `karyawan` (`id_karyawan`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- SKEMA ALTERNATIF / MODUL GAJI: db_jam_kerja
-- ==========================================================
-- ==========================================================
-- PERANCANGAN DATABASE SISTEM PENCATATAN KERJA & PENGELUARAN
-- Database: db_jam_kerja
-- ==========================================================

CREATE DATABASE IF NOT EXISTS db_jam_kerja CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_jam_kerja;

-- Hapus tabel jika sudah ada (urutkan sesuai FK)
DROP TABLE IF EXISTS gaji;
DROP TABLE IF EXISTS pengeluaran;
DROP TABLE IF EXISTS absensi;
DROP TABLE IF EXISTS penugasan;
DROP TABLE IF EXISTS pekerjaan;
DROP TABLE IF EXISTS `user`;
DROP TABLE IF EXISTS karyawan;

-- ----------------------------------------------------------
-- 1. TABEL: karyawan
-- Menyimpan data karyawan/pekerja yang bekerja dan digaji
-- ----------------------------------------------------------
CREATE TABLE karyawan (
    id_karyawan INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    jabatan VARCHAR(100) DEFAULT NULL,
    tarif_per_jam DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- 2. TABEL: user
-- Menyimpan akun untuk login (Admin & Karyawan)
-- ----------------------------------------------------------
CREATE TABLE `user` (
    id_user INT AUTO_INCREMENT PRIMARY KEY,
    id_karyawan INT DEFAULT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    kata_sandi VARCHAR(255) NOT NULL,
    peran ENUM('admin', 'karyawan') NOT NULL DEFAULT 'karyawan',
    aktif BOOLEAN NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_karyawan FOREIGN KEY (id_karyawan) 
        REFERENCES karyawan(id_karyawan) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT uq_user_karyawan UNIQUE (id_karyawan)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- 3. TABEL: pekerjaan
-- Menyimpan daftar pekerjaan (proyek), deskripsi, dan statusnya
-- ----------------------------------------------------------
CREATE TABLE pekerjaan (
    id_pekerjaan INT AUTO_INCREMENT PRIMARY KEY,
    nama_pekerjaan VARCHAR(150) NOT NULL,
    deskripsi TEXT DEFAULT NULL,
    lokasi VARCHAR(150) DEFAULT NULL,
    titik_lokasi VARCHAR(100) DEFAULT NULL COMMENT 'Koordinat titik lokasi kerja',
    tanggal_mulai DATE DEFAULT NULL,
    target_selesai DATE DEFAULT NULL,
    status ENUM('belum_mulai', 'berjalan', 'selesai') NOT NULL DEFAULT 'belum_mulai',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- 4. TABEL: penugasan
-- Menghubungkan karyawan dengan pekerjaan (Fitur: Siapa yang Bekerja)
-- ----------------------------------------------------------
CREATE TABLE penugasan (
    id_penugasan INT AUTO_INCREMENT PRIMARY KEY,
    id_pekerjaan INT NOT NULL,
    id_karyawan INT NOT NULL,
    peran_tugas VARCHAR(100) DEFAULT 'Anggota' COMMENT 'Peran/tugas pekerja pada proyek ini',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_penugasan_pekerjaan FOREIGN KEY (id_pekerjaan) 
        REFERENCES pekerjaan(id_pekerjaan) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_penugasan_karyawan FOREIGN KEY (id_karyawan) 
        REFERENCES karyawan(id_karyawan) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT uq_pekerjaan_karyawan UNIQUE (id_pekerjaan, id_karyawan)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- 5. TABEL: absensi
-- Mencatat jam masuk & pulang, durasi, verifikasi, dan foto lokasi
-- ----------------------------------------------------------
CREATE TABLE absensi (
    id_absensi INT AUTO_INCREMENT PRIMARY KEY,
    id_pekerjaan INT NOT NULL,
    id_karyawan INT NOT NULL,
    tanggal DATE NOT NULL,
    jam_masuk TIME NOT NULL,
    jam_pulang TIME DEFAULT NULL,
    status_verifikasi ENUM('menunggu', 'disetujui', 'ditolak') NOT NULL DEFAULT 'menunggu',
    foto_lokasi VARCHAR(255) DEFAULT NULL,
    titik_lokasi VARCHAR(100) DEFAULT NULL,
    catatan VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_absensi_pekerjaan FOREIGN KEY (id_pekerjaan) 
        REFERENCES pekerjaan(id_pekerjaan) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_absensi_karyawan FOREIGN KEY (id_karyawan) 
        REFERENCES karyawan(id_karyawan) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- 6. TABEL: pengeluaran
-- Mencatat uang terpakai, jenis dana (kantor/pribadi), foto kwitansi
-- ----------------------------------------------------------
CREATE TABLE pengeluaran (
    id_pengeluaran INT AUTO_INCREMENT PRIMARY KEY,
    id_pekerjaan INT NOT NULL,
    id_karyawan INT NOT NULL,
    tanggal DATE NOT NULL,
    nominal DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    jenis_dana ENUM('kantor', 'pribadi') NOT NULL,
    keterangan VARCHAR(255) DEFAULT NULL,
    foto_kwitansi VARCHAR(255) DEFAULT NULL,
    status_verifikasi ENUM('menunggu', 'disetujui', 'ditolak') NOT NULL DEFAULT 'menunggu',
    status_ganti ENUM('belum', 'sudah') NOT NULL DEFAULT 'belum' COMMENT 'Reimbursement untuk dana pribadi',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pengeluaran_pekerjaan FOREIGN KEY (id_pekerjaan) 
        REFERENCES pekerjaan(id_pekerjaan) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pengeluaran_karyawan FOREIGN KEY (id_karyawan) 
        REFERENCES karyawan(id_karyawan) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- 7. TABEL: gaji
-- Menyimpan hasil hitungan gaji tiap karyawan per bulan
-- ----------------------------------------------------------
CREATE TABLE gaji (
    id_gaji INT AUTO_INCREMENT PRIMARY KEY,
    id_karyawan INT NOT NULL,
    periode CHAR(7) NOT NULL COMMENT 'Format YYYY-MM, contoh: 2026-11',
    total_jam DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    tarif DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    total_upah DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    total_ganti DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    total_dibayar DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    status_bayar ENUM('belum', 'sudah') NOT NULL DEFAULT 'belum',
    tanggal_bayar DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gaji_karyawan FOREIGN KEY (id_karyawan) 
        REFERENCES karyawan(id_karyawan) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT uq_gaji_karyawan_periode UNIQUE (id_karyawan, periode)
) ENGINE=InnoDB;

-- ==========================================================
-- DATA AWAL / SAMPLE DATA UNTUK PENGUJIAN
-- ==========================================================

-- Data Karyawan
INSERT INTO karyawan (id_karyawan, nama, jabatan, tarif_per_jam) VALUES
(1, 'Budi Santoso', 'Teknisi Jaringan Senior', 50000.00),
(2, 'Siti Rahma', 'Sistem Analis', 60000.00),
(3, 'Agus Setiawan', 'Teknisi Lapangan', 40000.00),
(4, 'Dewi Lestari', 'Quality Assurance', 45000.00);

-- Data User (Password hash atau teks plain untuk kemudahan demo)
-- Password default: 'admin123' untuk admin, 'karyawan123' untuk karyawan
INSERT INTO `user` (id_user, id_karyawan, email, kata_sandi, peran, aktif) VALUES
(1, NULL, 'admin@jamkerja.com', '$2y$10$w0u3F/sE712z2x91QY6yYOiYwD3Gk0nK10UqW3hG0o6aY.M.bM05m', 'admin', 1),
(2, 1, 'budi@jamkerja.com', '$2y$10$w0u3F/sE712z2x91QY6yYOiYwD3Gk0nK10UqW3hG0o6aY.M.bM05m', 'karyawan', 1),
(3, 2, 'siti@jamkerja.com', '$2y$10$w0u3F/sE712z2x91QY6yYOiYwD3Gk0nK10UqW3hG0o6aY.M.bM05m', 'karyawan', 1),
(4, 3, 'agus@jamkerja.com', '$2y$10$w0u3F/sE712z2x91QY6yYOiYwD3Gk0nK10UqW3hG0o6aY.M.bM05m', 'karyawan', 1);

-- Data Pekerjaan
INSERT INTO pekerjaan (id_pekerjaan, nama_pekerjaan, deskripsi, lokasi, titik_lokasi, tanggal_mulai, target_selesai, status) VALUES
(1, 'Instalasi Jaringan Gedung B', 'Pemasangan kabel UTP CAT6, konfigurasi Switch Core, dan Access Point lantai 1-3.', 'Kantor Cabang Sudirman', '-6.2088,106.8456', '2026-11-01', '2026-11-15', 'berjalan'),
(2, 'Pemeliharaan Server & Database', 'Maintenance rutin bulanan server produksi, optimasi query, dan backup data.', 'Data Center Gedung Utama', '-6.1754,106.8272', '2026-11-02', '2026-11-08', 'berjalan'),
(3, 'Perbaikan Kelistrikan Gudang', 'Penggantian panel MCB dan instalasi jalur kabel daya lampu darurat.', 'Gudang Logistik Cakung', '-6.1932,106.9482', '2026-10-20', '2026-10-30', 'selesai');

-- Data Penugasan (Fitur: Siapa yang Bekerja)
INSERT INTO penugasan (id_pekerjaan, id_karyawan, peran_tugas) VALUES
(1, 1, 'Lead Cabling & Hardware'),
(1, 3, 'Teknisi Penarikan Kabel'),
(2, 1, 'Dukungan Infrastruktur Server'),
(2, 2, 'Spesialis Optimasi Database & Backup'),
(3, 3, 'Teknisi Instalasi Listrik');

-- Data Absensi
INSERT INTO absensi (id_pekerjaan, id_karyawan, tanggal, jam_masuk, jam_pulang, status_verifikasi, catatan) VALUES
(1, 1, '2026-11-02', '08:00:00', '17:00:00', 'disetujui', 'Pekerjaan crimping kabel lantai 1'),
(1, 1, '2026-11-03', '08:30:00', '16:30:00', 'disetujui', 'Pemasangan rak server dan switch'),
(1, 3, '2026-11-02', '08:00:00', '16:00:00', 'disetujui', 'Bantu tarik kabel ducting'),
(1, 3, '2026-11-03', '08:00:00', '17:00:00', 'menunggu', 'Terminasi patch panel'),
(2, 2, '2026-11-02', '09:00:00', '18:00:00', 'disetujui', 'Audit indexing database'),
(2, 1, '2026-11-03', '09:00:00', '15:00:00', 'menunggu', 'Cek temperatur data center');

-- Data Pengeluaran (Sesuai contoh use case)
INSERT INTO pengeluaran (id_pekerjaan, id_karyawan, tanggal, nominal, jenis_dana, keterangan, foto_kwitansi, status_verifikasi, status_ganti) VALUES
(1, 1, '2026-11-02', 150000.00, 'pribadi', 'Beli konektor RJ45 Cat6 dan isolasi listrik', 'uploads/kwitansi_sample1.jpg', 'disetujui', 'sudah'),
(1, 1, '2026-11-03', 500000.00, 'kantor', 'Sewa tangga scaffolding 2 hari', 'uploads/kwitansi_sample2.jpg', 'disetujui', 'sudah'),
(2, 2, '2026-11-02', 300000.00, 'kantor', 'Lisensi software utility tuning database', 'uploads/kwitansi_sample3.jpg', 'disetujui', 'sudah'),
(1, 3, '2026-11-04', 75000.00, 'pribadi', 'Beli paku klem kabel dan mata bor beton', 'uploads/kwitansi_sample4.jpg', 'menunggu', 'belum');

-- Data Gaji (Bulan sebelumnya / periode 2026-10)
INSERT INTO gaji (id_karyawan, periode, total_jam, tarif, total_upah, total_ganti, total_dibayar, status_bayar, tanggal_bayar) VALUES
(1, '2026-10', 160.00, 50000.00, 8000000.00, 250000.00, 8250000.00, 'sudah', '2026-10-31 15:00:00'),
(2, '2026-10', 155.00, 60000.00, 9300000.00, 0.00, 9300000.00, 'sudah', '2026-10-31 15:00:00'),
(3, '2026-10', 140.00, 40000.00, 5600000.00, 120000.00, 5720000.00, 'sudah', '2026-10-31 15:00:00');
