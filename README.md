# Backend Sistem Pencatatan Kerja

## Database Laragon

Skema dan data awal berada di `database/pencatatan_kerja.sql`. Impor file tersebut
melalui Laragon/phpMyAdmin atau jalankan dengan klien MySQL. File akan membuat
database bernama `pencatatan-kerja` beserta tabel `users`, `karyawan`,
`pekerjaan`, `penugasan`, dan `absensi`. Impor ulang tidak menggandakan data awal.

Koneksi default untuk Laragon adalah `127.0.0.1:3306`, user `root`, tanpa kata
sandi. Konfigurasi dapat ditimpa dengan environment variable `DB_HOST`,
`DB_PORT`, `DB_NAME`, `DB_USER`, dan `DB_PASS`.

## API

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