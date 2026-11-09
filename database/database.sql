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
