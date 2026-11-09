# Backend Pencatatan Kerja

Backend ini menyediakan REST API JSON dengan PHP 8 Native, PDO, dan
MySQL/MariaDB. Untuk pengembangan lokal, jalankan backend melalui Apache
Laragon; tidak perlu menjalankan PHP built-in server. Struktur database yang
ada berada di `database/database.sql` dan tidak perlu diubah. API CRUD karyawan,
pekerjaan, dan penugasan tersedia tanpa autentikasi pada tahap ini.

## Struktur direktori

```text
backend/
├── config/          Konfigurasi aplikasi dan koneksi PDO.
│   ├── cors.php     Whitelist origin dan pengaturan CORS.
│   └── database.php Koneksi PDO yang dapat digunakan ulang.
├── controllers/
│   ├── KaryawanController.php Validasi dan respons CRUD karyawan.
│   ├── PekerjaanController.php Validasi dan respons CRUD pekerjaan.
│   └── PenugasanController.php Validasi dan respons CRUD penugasan.
├── models/
│   ├── Karyawan.php Akses tabel karyawan melalui PDO.
│   ├── Pekerjaan.php Akses tabel pekerjaan melalui PDO.
│   └── Penugasan.php Akses tabel penugasan melalui PDO.
├── routes/
│   └── api.php      Daftar rute API.
├── middleware/
│   └── cors.php     Pemeriksaan origin dan header preflight.
├── helpers/
│   └── response.php Format respons JSON bersama.
├── uploads/         Lokasi file unggahan pada pengembangan berikutnya.
├── public/
│   ├── index.php    Titik masuk dan dispatcher HTTP API.
│   ├── .htaccess    Rewrite URL API ke index.php.
│   └── test-connection.php Endpoint sementara untuk uji PDO.
├── .htaccess        Aturan akses Apache untuk folder backend.
├── .env.example     Contoh nama variabel konfigurasi lokal.
├── database/
│   └── database.sql Definisi database dan tabel.
├── .gitignore       Mengabaikan konfigurasi rahasia dan file sementara.
└── README.md        Panduan ringkas struktur dan ruang lingkup backend.
```

Folder `api/` dan file starter lain yang telah ada di repositori dipertahankan.

## File konfigurasi lokal

Salin `.env.example` menjadi `.env` di root folder `backend/` untuk konfigurasi
lokal. Nilai awal ditujukan untuk Laragon: host `localhost`, port `3306`,
database `db_pencatatan_kerja`, user `root`, dan password kosong. Sesuaikan
sesuai konfigurasi MySQL lokal Anda. Variabel environment sistem akan
diprioritaskan dibanding isi file `.env`.

File `.env` diabaikan Git dan tidak boleh berisi kredensial yang dibagikan atau
di-commit. Koneksi tersedia melalui `getDatabaseConnection()` di
`config/database.php` dan menggunakan PDO dengan native prepared statements.

## Menjalankan backend melalui Apache Laragon

Pastikan folder proyek berada di `D:\laragon\www\pencatatan-kerja`, lalu
jalankan Apache dan MySQL/MariaDB dari Laragon. Apache harus mengizinkan
`.htaccess` (`AllowOverride All`) untuk folder proyek dan mengaktifkan modul
`mod_rewrite`. Folder `backend/public/` adalah entrypoint aplikasi, sedangkan
URL pada instalasi Laragon standar menggunakan path:

`http://localhost/pencatatan-kerja/backend/public`

Buat `backend/.env` dari `.env.example`, lalu sesuaikan nama database dan
kredensial MySQL lokal. Import `database/database.sql` melalui phpMyAdmin bila
database belum tersedia. Tidak ada perubahan skema database yang diperlukan.

Uji koneksi PDO dengan membuka:

`http://localhost/pencatatan-kerja/backend/public/test-connection.php`

Endpoint sementara ini menjalankan `SELECT 1` menggunakan prepared statement.

## URL dan routing API

`.htaccess` pada `public/` mengarahkan URL yang bukan file atau direktori ke
`public/index.php`. Dengan demikian, URL API menggunakan format
`/backend/public/api/...`; query string tetap diteruskan ke dispatcher.
Endpoint pemeriksaan API:

`GET http://localhost/pencatatan-kerja/backend/public/api`

Router native juga menangani `OPTIONS` preflight, mengembalikan JSON 404 untuk
endpoint yang tidak terdaftar, dan JSON 405 untuk method yang belum didukung
oleh endpoint. CORS mengizinkan `http://localhost:5173` dan
`http://127.0.0.1:5173`; credential/cookie sharing tidak diaktifkan.

Gunakan base URL berikut pada klien API:

`http://localhost/pencatatan-kerja/backend/public/api`

### CRUD Karyawan

- `GET /karyawan` — semua karyawan.
- `GET /karyawan/{id}` — detail karyawan.
- `POST /karyawan` — tambah karyawan.
- `PUT /karyawan/{id}` — perbarui nama, jabatan, dan tarif.
- `DELETE /karyawan/{id}` — hapus jika tidak direferensikan oleh user,
  penugasan, absensi, pengeluaran, atau gaji.

Semua endpoint memakai database PDO yang dikonfigurasi dari `.env`. Untuk
Postman, tambahkan path di atas ke base URL API dan kirim header
`Content-Type: application/json` untuk POST/PUT, misalnya:

```json
{
  "nama": "Andi Saputra",
  "jabatan": "Teknisi",
  "tarif_per_jam": 25000
}
```

Karyawan yang masih memiliki relasi menghasilkan HTTP 409; ID yang tidak
ditemukan menghasilkan HTTP 404. Endpoint ini mengelola tabel `karyawan` saja,
tanpa mengubah modul atau tabel lain.

### CRUD Pekerjaan

- `GET /pekerjaan` — semua pekerjaan.
- `GET /pekerjaan/{id}` — detail pekerjaan.
- `POST /pekerjaan` — tambah pekerjaan.
- `PUT /pekerjaan/{id}` — perbarui pekerjaan.
- `DELETE /pekerjaan/{id}` — hapus jika tidak direferensikan.

Body POST dan PUT:

```json
{
  "nama_pekerjaan": "Pemasangan Keramik",
  "deskripsi": "Pekerjaan pemasangan area lobi",
  "lokasi": "Bandung",
  "tanggal_mulai": "2026-11-10",
  "target_selesai": "2026-11-20",
  "status": "belum_mulai"
}
```

Tanggal dan kolom deskripsi/lokasi boleh dikirim `null` atau string kosong.
Format tanggal adalah `YYYY-MM-DD`; target tidak boleh lebih awal daripada
tanggal mulai. Status yang diterima: `belum_mulai`, `berjalan`, atau `selesai`.
Input atau ID yang tidak valid menghasilkan HTTP 422, data yang tidak
ditemukan menghasilkan 404, dan penghapusan pekerjaan yang masih direferensikan
oleh `penugasan`, `absensi`, atau `pengeluaran` menghasilkan 409.

Postman menggunakan base URL API yang sama dengan CRUD Karyawan dan
`Content-Type: application/json` untuk POST/PUT.

### CRUD Penugasan

- `GET /penugasan` — semua penugasan, termasuk ID dan nama karyawan serta
  pekerjaan.
- `GET /penugasan/{id}` — detail penugasan.
- `POST /penugasan` — menambahkan penugasan.
- `PUT /penugasan/{id}` — memperbarui karyawan atau pekerjaan pada penugasan.
- `DELETE /penugasan/{id}` — menghapus penugasan.

Body POST dan PUT:

```json
{
  "id_karyawan": 1,
  "id_pekerjaan": 1
}
```

ID karyawan dan pekerjaan harus positif dan sudah tersedia. Pasangan karyawan
dan pekerjaan yang sama tidak dapat ditugaskan lebih dari sekali; saat update,
penugasan yang sedang diedit tidak dianggap duplikat dirinya sendiri. Input
atau ID foreign key yang tidak valid menghasilkan HTTP 422, data yang tidak
ditemukan menghasilkan 404, dan pasangan duplikat atau penugasan yang masih
direferensikan menghasilkan 409.

Semua endpoint memakai base URL API di atas dan request POST/PUT menggunakan
header `Content-Type: application/json`. Penugasan ditampilkan melalui JOIN
dengan tabel karyawan dan pekerjaan.

## Upload

Folder `uploads/` disediakan untuk kebutuhan berikutnya. File di dalamnya
diabaikan Git, sementara `.gitkeep` dipertahankan agar folder tetap tercatat
di repositori. Aturan akses web untuk file unggahan perlu ditinjau sebelum
fitur upload diimplementasikan.

## Batasan tahap saat ini

- Tidak membuat atau mengubah database maupun tabel.
- Tidak menambahkan login atau autentikasi.
- CRUD API saat ini tersedia untuk karyawan, pekerjaan, dan penugasan.

Pastikan Apache/PHP pada Laragon menggunakan PHP 8 atau lebih baru ketika
backend mulai dikembangkan.

---

## Petunjuk API & Database Alternatif (Laragon)

### Database Laragon

Skema dan data awal berada di `database/pencatatan_kerja.sql`. Impor file tersebut
melalui Laragon/phpMyAdmin atau jalankan dengan klien MySQL. File akan membuat
database bernama `pencatatan-kerja` beserta tabel `users`, `karyawan`,
`pekerjaan`, `penugasan`, dan `absensi`. Impor ulang tidak menggandakan data awal.

Koneksi default untuk Laragon adalah `127.0.0.1:3306`, user `root`, tanpa kata
sandi. Konfigurasi dapat ditimpa dengan environment variable `DB_HOST`,
`DB_PORT`, `DB_NAME`, `DB_USER`, dan `DB_PASS`.

### API Tambahan (`api/index.php`)

API menggunakan JSON dan tersedia di `api/index.php`. Contoh URL lokal:

- `GET /api/index.php?resource=users`
- `GET /api/index.php?resource=karyawan`
- `GET /api/index.php?resource=pekerjaan`
- `GET /api/index.php?resource=penugasan`
- `GET /api/index.php?resource=absensi`
- `POST /api/index.php?resource=absensi` dengan body `{"id_karyawan":1,"id_pekerjaan":1,"foto":"data:image/jpeg;base64,...","lokasi":{"lat":-6.91,"lng":107.60,"alamat":"Bandung"}}`
- `PATCH /api/index.php?resource=absensi&id=1` untuk absen pulang, body berisi `foto` dan `lokasi`
- `PATCH /api/index.php?resource=pekerjaan&id=1` untuk menyimpan `latitude`, `longitude`, dan opsional `foto_lokasi`

Data contoh yang sudah berada di frontend dimasukkan sebagai data awal database.
Frontend belum diubah atau dihubungkan ke endpoint ini; agar input dari halaman
frontend tersimpan ke database, pemanggilan API pada frontend masih perlu
diaktifkan secara terpisah.
