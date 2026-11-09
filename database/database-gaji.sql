-- ============================================================
-- DATABASE: Sistem Pencatatan Kerja & Penggajian
-- Jalankan SQL ini di phpMyAdmin atau MySQL CLI
-- ============================================================

CREATE DATABASE IF NOT EXISTS skema_gaji_dan_rekap
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE skema_gaji_dan_rekap;

-- ---------- Tabel Karyawan ----------
CREATE TABLE IF NOT EXISTS karyawan (
    id_karyawan   INT AUTO_INCREMENT PRIMARY KEY,
    nama          VARCHAR(100) NOT NULL,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    jabatan       VARCHAR(100) DEFAULT 'Pekerja',
    peran         ENUM('admin', 'karyawan') DEFAULT 'karyawan',
    tarif_per_jam DECIMAL(12,2) DEFAULT 50000,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Tabel Pekerjaan (Proyek) ----------
CREATE TABLE IF NOT EXISTS pekerjaan (
    id_pekerjaan   INT AUTO_INCREMENT PRIMARY KEY,
    nama_pekerjaan VARCHAR(200) NOT NULL,
    lokasi         VARCHAR(200) DEFAULT NULL,
    status         ENUM('berjalan', 'selesai') DEFAULT 'berjalan',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Tabel Penugasan (Karyawan ↔ Pekerjaan) ----------
CREATE TABLE IF NOT EXISTS penugasan (
    id_penugasan INT AUTO_INCREMENT PRIMARY KEY,
    id_karyawan  INT NOT NULL,
    id_pekerjaan INT NOT NULL,
    UNIQUE KEY uq_penugasan (id_karyawan, id_pekerjaan),
    FOREIGN KEY (id_karyawan)  REFERENCES karyawan(id_karyawan)  ON DELETE CASCADE,
    FOREIGN KEY (id_pekerjaan) REFERENCES pekerjaan(id_pekerjaan) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Tabel Absensi (Jam Kerja) ----------
CREATE TABLE IF NOT EXISTS absensi (
    id_absensi         INT AUTO_INCREMENT PRIMARY KEY,
    id_karyawan        INT NOT NULL,
    id_pekerjaan       INT DEFAULT NULL,
    tanggal            DATE NOT NULL,
    jam_masuk          TIME NOT NULL,
    jam_pulang         TIME DEFAULT NULL,
    keterangan         TEXT DEFAULT NULL,
    status_verifikasi  ENUM('menunggu', 'disetujui', 'ditolak') DEFAULT 'menunggu',
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_karyawan)  REFERENCES karyawan(id_karyawan)  ON DELETE CASCADE,
    FOREIGN KEY (id_pekerjaan) REFERENCES pekerjaan(id_pekerjaan) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- Tabel Pengeluaran ----------
CREATE TABLE IF NOT EXISTS pengeluaran (
    id_pengeluaran     INT AUTO_INCREMENT PRIMARY KEY,
    id_karyawan        INT NOT NULL,
    id_pekerjaan       INT DEFAULT NULL,
    tanggal            DATE NOT NULL,
    nominal            DECIMAL(15,2) NOT NULL DEFAULT 0,
    jenis_dana         ENUM('pribadi', 'kas') DEFAULT 'pribadi',
    keterangan         TEXT DEFAULT NULL,
    status_verifikasi  ENUM('menunggu', 'disetujui', 'ditolak') DEFAULT 'menunggu',
    status_ganti       ENUM('belum', 'sudah') DEFAULT 'belum',
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_karyawan)  REFERENCES karyawan(id_karyawan)  ON DELETE CASCADE,
    FOREIGN KEY (id_pekerjaan) REFERENCES pekerjaan(id_pekerjaan) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- Tabel Gaji ----------
CREATE TABLE IF NOT EXISTS gaji (
    id_gaji       INT AUTO_INCREMENT PRIMARY KEY,
    id_karyawan   INT NOT NULL,
    periode       VARCHAR(7) NOT NULL COMMENT 'Format: YYYY-MM',
    total_jam     DECIMAL(10,2) DEFAULT 0,
    tarif         DECIMAL(12,2) DEFAULT 0,
    total_upah    DECIMAL(15,2) DEFAULT 0,
    total_ganti   DECIMAL(15,2) DEFAULT 0,
    total_dibayar DECIMAL(15,2) DEFAULT 0,
    status_bayar  ENUM('belum', 'sudah') DEFAULT 'belum',
    tanggal_bayar DATETIME DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gaji_periode (id_karyawan, periode),
    FOREIGN KEY (id_karyawan) REFERENCES karyawan(id_karyawan) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- DATA CONTOH (untuk testing)
-- ============================================================

-- Password: admin123 dan karyawan123 (plaintext untuk development)
INSERT INTO karyawan (nama, username, password, jabatan, peran, tarif_per_jam) VALUES
('Admin Demo',    'admin',    'admin123',    'Manajer Proyek', 'admin',    75000),
('Budi Santoso',  'budi',     'budi123',     'Tukang Batu',    'karyawan', 50000),
('Siti Rahayu',   'siti',     'siti123',     'Tukang Kayu',    'karyawan', 55000),
('Andi Pratama',  'andi',     'andi123',     'Pekerja Umum',   'karyawan', 45000);

INSERT INTO pekerjaan (nama_pekerjaan, lokasi, status) VALUES
('Renovasi Gedung A',  'Jl. Merdeka No. 10',    'berjalan'),
('Pembangunan Gudang', 'Jl. Industri Blok C',   'berjalan'),
('Perbaikan Jalan',    'Jl. Sudirman KM 5',     'selesai');

INSERT INTO penugasan (id_karyawan, id_pekerjaan) VALUES
(2, 1), (2, 2),
(3, 1), (3, 3),
(4, 2), (4, 3);

INSERT INTO absensi (id_karyawan, id_pekerjaan, tanggal, jam_masuk, jam_pulang, status_verifikasi) VALUES
(2, 1, CURDATE(), '08:00:00', '16:30:00', 'disetujui'),
(2, 2, CURDATE(), '08:00:00', '17:00:00', 'menunggu'),
(3, 1, CURDATE(), '07:30:00', '15:30:00', 'disetujui'),
(4, 2, CURDATE(), '09:00:00', '18:00:00', 'disetujui'),
(4, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:00:00', '16:00:00', 'disetujui');

INSERT INTO pengeluaran (id_karyawan, id_pekerjaan, tanggal, nominal, jenis_dana, keterangan, status_verifikasi, status_ganti) VALUES
(2, 1, CURDATE(), 150000, 'pribadi', 'Beli paku dan semen',     'disetujui', 'belum'),
(3, 1, CURDATE(), 200000, 'pribadi', 'Beli cat dan kuas',       'disetujui', 'belum'),
(4, 2, CURDATE(),  75000, 'pribadi', 'Beli sarung tangan kerja', 'menunggu',  'belum');

