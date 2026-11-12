-- ============================================================
-- MIGRASI PENYEMPURNAAN TABEL KARYAWAN
-- CV. Putra Anugrah Mandiri
-- ============================================================
-- Menambahkan kolom identitas lengkap, sistem upah (per jam, per hari, per bulan),
-- upah lembur opsional, catatan admin internal, dan status kepegawaian.
-- ============================================================

USE `db_pencatatan_kerja`;

-- 1. Tambahkan kolom baru pada tabel karyawan (jika belum ada)
ALTER TABLE `karyawan`
  ADD COLUMN IF NOT EXISTS `nomor_hp` VARCHAR(20) NULL AFTER `jabatan`,
  ADD COLUMN IF NOT EXISTS `email` VARCHAR(100) NULL AFTER `nomor_hp`,
  ADD COLUMN IF NOT EXISTS `jenis_upah` ENUM('per_jam', 'per_hari', 'per_bulan') NOT NULL DEFAULT 'per_jam' AFTER `email`,
  ADD COLUMN IF NOT EXISTS `nominal_upah` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `jenis_upah`,
  ADD COLUMN IF NOT EXISTS `lembur_aktif` TINYINT(1) NOT NULL DEFAULT 0 AFTER `nominal_upah`,
  ADD COLUMN IF NOT EXISTS `tarif_lembur` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `lembur_aktif`,
  ADD COLUMN IF NOT EXISTS `catatan_admin` TEXT NULL AFTER `tarif_lembur`,
  ADD COLUMN IF NOT EXISTS `status` ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif' AFTER `catatan_admin`,
  ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `status`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- 2. Migrasi data lama: inisialisasi nominal_upah dari tarif_per_jam yang sudah ada
UPDATE `karyawan`
SET `nominal_upah` = `tarif_per_jam`
WHERE (`nominal_upah` IS NULL OR `nominal_upah` = 0) AND `tarif_per_jam` > 0;

-- 3. Sinkronkan email dari tabel user jika karyawan tersebut sudah memiliki akun
UPDATE `karyawan` k
JOIN `user` u ON u.id_karyawan = k.id_karyawan
SET k.email = u.email
WHERE (k.email IS NULL OR k.email = '') AND u.email IS NOT NULL AND u.email != '';

