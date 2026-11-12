-- ============================================================
-- MIGRASI KODE KARYAWAN OTOMATIS & PENGELOLAAN AKUN KARYAWAN
-- CV. Putra Anugrah Mandiri
-- ============================================================
-- Menambahkan kolom kode_karyawan (e.g. BUDI001) pada tabel karyawan,
-- menambahkan kolom username pada tabel user, dan sinkronisasi data.
-- ============================================================

USE `db_pencatatan_kerja`;

-- 1. Tambah kolom kode_karyawan pada tabel karyawan
ALTER TABLE `karyawan`
  ADD COLUMN IF NOT EXISTS `kode_karyawan` VARCHAR(30) NULL UNIQUE AFTER `id_karyawan`;

-- 2. Tambah kolom username pada tabel user dan ubah email menjadi nullable
ALTER TABLE `user`
  ADD COLUMN IF NOT EXISTS `username` VARCHAR(50) NULL UNIQUE AFTER `id_karyawan`,
  MODIFY COLUMN `email` VARCHAR(100) NULL;

-- 3. Sinkronkan username dari kode_karyawan
UPDATE `user` u
JOIN `karyawan` k ON u.id_karyawan = k.id_karyawan
SET u.username = k.kode_karyawan
WHERE (u.username IS NULL OR u.username = '') AND k.kode_karyawan IS NOT NULL;

