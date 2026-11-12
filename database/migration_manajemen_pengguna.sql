-- ============================================================
-- MIGRASI INTEGRASI DATA KARYAWAN & MANAJEMEN PENGGUNA
-- CV. Putra Anugrah Mandiri
-- ============================================================
-- Menambahkan kolom kode_user (USER001, USER002, ...) pada tabel user,
-- memastikan urutan ID pengguna tidak berulang, dan sinkronisasi
-- seluruh karyawan dengan akun pengguna sistem.
-- ============================================================

USE `db_pencatatan_kerja`;

-- 1. Tambah kolom kode_user jika belum ada
ALTER TABLE `user`
  ADD COLUMN IF NOT EXISTS `kode_user` VARCHAR(30) NULL UNIQUE AFTER `id_user`;

-- 2. Update username admin jika masih kosong
UPDATE `user`
SET `username` = 'admin'
WHERE `id_user` = 1 AND (`username` IS NULL OR `username` = '');

