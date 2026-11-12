-- Migrasi Sistem Penugasan Multi-Worker & Kuota Pekerja
-- Aplikasi Pencatatan Kerja - CV. Putra Anugrah Mandiri

-- 1. Modifikasi tabel pekerjaan: metode_penentuan mendukung 'gabungan', kuota_pekerja, dan status_pengambilan
ALTER TABLE `pekerjaan`
  MODIFY COLUMN `metode_penentuan` ENUM('langsung', 'terbuka', 'gabungan') NOT NULL DEFAULT 'terbuka',
  ADD COLUMN `kuota_pekerja` INT NULL DEFAULT NULL AFTER `metode_penentuan`,
  ADD COLUMN `status_pengambilan` ENUM('terbuka', 'ditutup') NOT NULL DEFAULT 'terbuka' AFTER `kuota_pekerja`;

-- 2. Modifikasi tabel penugasan: status_penugasan (aktif/dibatalkan), alasan_batal, dibatalkan_pada, dibatalkan_oleh
ALTER TABLE `penugasan`
  ADD COLUMN `status_penugasan` ENUM('aktif', 'dibatalkan') NOT NULL DEFAULT 'aktif' AFTER `jenis_penugasan`,
  ADD COLUMN `alasan_batal` VARCHAR(255) NULL AFTER `catatan_admin`,
  ADD COLUMN `dibatalkan_pada` DATETIME NULL AFTER `alasan_batal`,
  ADD COLUMN `dibatalkan_oleh` VARCHAR(100) NULL AFTER `dibatalkan_pada`;

