-- Migration: Pembaruan Status Pekerja Penugasan dan Tabel Laporan Pekerjaan
-- CV. Putra Anugrah Mandiri

-- 1. Tambah kolom status penugasan individual karyawan pada tabel penugasan
ALTER TABLE `penugasan`
ADD COLUMN `status_karyawan` ENUM('belum_hadir', 'sedang_bekerja', 'selesai_bertugas', 'tidak_hadir', 'meninggalkan_pekerjaan') NOT NULL DEFAULT 'belum_hadir' AFTER `jenis_penugasan`,
ADD COLUMN `catatan_admin` VARCHAR(255) NULL AFTER `status_karyawan`,
ADD COLUMN `status_konfirmasi_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `catatan_admin`;

-- 2. Buat tabel laporan_pekerjaan untuk pemantauan hasil kerja dan persetujuan penyelesaian pekerjaan
CREATE TABLE IF NOT EXISTS `laporan_pekerjaan` (
    `id_laporan` INT AUTO_INCREMENT PRIMARY KEY,
    `id_pekerjaan` INT NOT NULL,
    `id_karyawan` INT NOT NULL,
    `tipe_laporan` ENUM('progres', 'akhir') NOT NULL DEFAULT 'akhir',
    `judul` VARCHAR(200) NOT NULL,
    `keterangan` TEXT NOT NULL,
    `foto_dokumentasi` LONGTEXT NULL,
    `status_persetujuan` ENUM('menunggu', 'disetujui', 'ditolak') NOT NULL DEFAULT 'menunggu',
    `catatan_admin` TEXT NULL,
    `tanggal_laporan` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_laporan_pekerjaan`
        FOREIGN KEY (`id_pekerjaan`) REFERENCES `pekerjaan` (`id_pekerjaan`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_laporan_karyawan`
        FOREIGN KEY (`id_karyawan`) REFERENCES `karyawan` (`id_karyawan`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

