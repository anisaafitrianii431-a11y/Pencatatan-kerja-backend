CREATE DATABASE IF NOT EXISTS `pencatatan-kerja`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `pencatatan-kerja`;

CREATE TABLE IF NOT EXISTS karyawan (
  id_karyawan INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama VARCHAR(120) NOT NULL,
  jabatan VARCHAR(100) NOT NULL,
  no_hp VARCHAR(30) DEFAULT NULL,
  PRIMARY KEY (id_karyawan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id_user INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama VARCHAR(120) NOT NULL,
  role ENUM('admin', 'pekerja') NOT NULL,
  id_karyawan INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id_user),
  KEY idx_users_karyawan (id_karyawan),
  CONSTRAINT fk_users_karyawan FOREIGN KEY (id_karyawan)
    REFERENCES karyawan (id_karyawan) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pekerjaan (
  id_pekerjaan INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama_pekerjaan VARCHAR(180) NOT NULL,
  deskripsi TEXT NOT NULL,
  shift_mulai TIME NOT NULL,
  shift_selesai TIME NOT NULL,
  status ENUM('aktif', 'selesai') NOT NULL DEFAULT 'aktif',
  latitude DECIMAL(10,7) DEFAULT NULL,
  longitude DECIMAL(10,7) DEFAULT NULL,
  foto_lokasi LONGTEXT DEFAULT NULL,
  PRIMARY KEY (id_pekerjaan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS penugasan (
  id_penugasan INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_pekerjaan INT UNSIGNED NOT NULL,
  id_karyawan INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_penugasan),
  UNIQUE KEY uq_penugasan_pekerjaan_karyawan (id_pekerjaan, id_karyawan),
  CONSTRAINT fk_penugasan_pekerjaan FOREIGN KEY (id_pekerjaan)
    REFERENCES pekerjaan (id_pekerjaan) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_penugasan_karyawan FOREIGN KEY (id_karyawan)
    REFERENCES karyawan (id_karyawan) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS absensi (
  id_absensi INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_pekerjaan INT UNSIGNED NOT NULL,
  id_karyawan INT UNSIGNED NOT NULL,
  tanggal DATE NOT NULL,
  jam_masuk TIME NOT NULL,
  jam_pulang TIME DEFAULT NULL,
  status_verifikasi ENUM('menunggu', 'disetujui', 'ditolak') NOT NULL DEFAULT 'menunggu',
  foto_masuk LONGTEXT DEFAULT NULL,
  masuk_latitude DECIMAL(10,7) DEFAULT NULL,
  masuk_longitude DECIMAL(10,7) DEFAULT NULL,
  masuk_alamat TEXT DEFAULT NULL,
  foto_pulang LONGTEXT DEFAULT NULL,
  pulang_latitude DECIMAL(10,7) DEFAULT NULL,
  pulang_longitude DECIMAL(10,7) DEFAULT NULL,
  pulang_alamat TEXT DEFAULT NULL,
  PRIMARY KEY (id_absensi),
  KEY idx_absensi_karyawan_tanggal (id_karyawan, tanggal),
  KEY idx_absensi_pekerjaan_tanggal (id_pekerjaan, tanggal),
  KEY idx_absensi_status (status_verifikasi),
  CONSTRAINT fk_absensi_pekerjaan FOREIGN KEY (id_pekerjaan)
    REFERENCES pekerjaan (id_pekerjaan) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_absensi_karyawan FOREIGN KEY (id_karyawan)
    REFERENCES karyawan (id_karyawan) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO karyawan (id_karyawan, nama, jabatan, no_hp) VALUES
  (1, 'Budi Santoso', 'Teknisi', '08111000001'),
  (2, 'Citra Dewi', 'Surveyor', '08111000002'),
  (3, 'Dani Firmansyah', 'Operator', '08111000003');

INSERT IGNORE INTO users (id_user, nama, role, id_karyawan) VALUES
  (1, 'Admin Utama', 'admin', NULL),
  (2, 'Budi Santoso', 'pekerja', 1),
  (3, 'Citra Dewi', 'pekerja', 2),
  (4, 'Dani Firmansyah', 'pekerja', 3);

INSERT IGNORE INTO pekerjaan
  (id_pekerjaan, nama_pekerjaan, deskripsi, shift_mulai, shift_selesai, status, latitude, longitude)
VALUES
  (1, 'Pemasangan Jaringan Fiber Optik',
   'Pemasangan kabel FO dari titik distribusi ke rumah pelanggan di perumahan Griya Asri.',
   '07:00:00', '16:00:00', 'aktif', -6.9147440, 107.6098100),
  (2, 'Survei Lahan Proyek B',
   'Pengukuran dan dokumentasi lahan untuk proyek pembangunan gudang logistik.',
   '08:00:00', '17:00:00', 'aktif', -6.9201000, 107.6182000),
  (3, 'Pemeliharaan Generator Cadangan',
   'Pengecekan rutin dan perbaikan genset cadangan di kantor pusat.',
   '09:00:00', '15:00:00', 'selesai', NULL, NULL);

INSERT IGNORE INTO penugasan (id_penugasan, id_pekerjaan, id_karyawan) VALUES
  (1, 1, 1),
  (2, 1, 2),
  (3, 2, 2),
  (4, 2, 3),
  (5, 3, 1);

INSERT IGNORE INTO absensi
  (id_absensi, id_pekerjaan, id_karyawan, tanggal, jam_masuk, jam_pulang,
   status_verifikasi, masuk_latitude, masuk_longitude, masuk_alamat,
   pulang_latitude, pulang_longitude, pulang_alamat)
VALUES
  (1, 1, 1, '2026-11-01', '07:05:00', '16:10:00', 'disetujui',
   -6.9147000, 107.6098000, 'Jl. Griya Asri No.1, Bandung',
   -6.9147000, 107.6098000, 'Jl. Griya Asri No.1, Bandung'),
  (2, 1, 1, '2026-11-02', '07:00:00', '15:45:00', 'disetujui',
   -6.9147000, 107.6098000, 'Jl. Griya Asri No.1, Bandung', NULL, NULL, NULL),
  (3, 3, 1, '2026-11-03', '09:00:00', '14:30:00', 'menunggu',
   NULL, NULL, NULL, NULL, NULL, NULL),
  (4, 1, 2, '2026-11-01', '07:15:00', '16:00:00', 'disetujui',
   -6.9201000, 107.6182000, 'Jl. Proyek B, Bandung', NULL, NULL, NULL),
  (5, 2, 2, '2026-11-04', '08:05:00', NULL, 'menunggu',
   NULL, NULL, NULL, NULL, NULL, NULL);