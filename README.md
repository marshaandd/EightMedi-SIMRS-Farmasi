# EightMedi — SIMRS Farmasi

EightMedi adalah aplikasi Sistem Informasi Manajemen Rumah Sakit (SIMRS) untuk demonstrasi akademik pengelolaan farmasi. Aplikasi dibuat dengan PHP 8.2+, PDO, dan MariaDB/MySQL.

> **Status:** proyek akademik / demonstrasi. Bukan pengganti sistem klinis tervalidasi dan belum dinyatakan layak untuk operasional rumah sakit atau penyimpanan data pasien nyata.

## Fitur

- Dashboard ringkasan farmasi.
- Master obat, kategori, pasien, dokter, poliklinik, dan pemasok.
- Simulasi penerimaan data pasien dan resep dari poliklinik.
- Riwayat kunjungan pasien.
- Penerimaan obat langsung dari pemasok.
- Stok terkini, stok minimum, dan tanggal kedaluwarsa acuan per obat.
- Penyerahan resep penuh maupun sebagian.
- Riwayat mutasi stok masuk dan keluar.
- Laporan stok, mutasi, resep, dan penggunaan obat.
- Cetak melalui browser dan ekspor CSV.
- Autentikasi, pembatasan percobaan login, timeout sesi, dan pengelolaan akun.

## Teknologi

- PHP 8.2 atau lebih baru
- MariaDB 10.4+ atau MySQL 8.0+
- PDO MySQL
- Apache (XAMPP untuk pengembangan lokal)
- HTML, CSS, dan JavaScript

## Struktur project

```text
EightMedi/
├── app/                 # konfigurasi, koneksi DB, helper, bootstrap
├── assets/              # CSS dan aset tampilan
├── auth/                # login, logout, ubah password, kelola akun
├── database/            # schema, seed demo, migration, CLI utility
├── docs/                # ERD, panduan, keamanan, hosting
├── laporan/
├── master/
├── pelayanan/
├── persediaan/
├── .env.example
├── .gitignore
├── .htaccess
├── README.md
└── index.php
```

## Instalasi lokal dengan XAMPP

1. Instal dan jalankan Apache serta MySQL/MariaDB dari XAMPP.
2. Buat database `simrs_farmasi` melalui phpMyAdmin, atau import `database/schema.sql` yang sudah membuat database tersebut.
3. Import `database/schema.sql` terlebih dahulu.
4. Import `database/seed_demo.sql` untuk data demonstrasi.
5. Salin `.env.example` menjadi `.env` di folder root project.
6. Untuk XAMPP standar, gunakan:
   - `APP_ENV=local`
   - `APP_BASE_URL=/simrs-farmasi`
   - `DB_HOST=127.0.0.1`
   - `DB_NAME=simrs_farmasi`
   - `DB_USER=root`
   - `DB_PASS=` (kosong hanya jika instalasi lokal memang tidak memakai password)
7. Letakkan folder project sebagai `C:\xampp\htdocs\simrs-farmasi`.
8. Buka `http://localhost/simrs-farmasi/`.

### Akun demonstrasi lokal

- Username: `adminfarmasi`
- Password awal: `FarmasiDemo!2026`

Akun demo dipaksa mengganti password pada login pertama. Kredensial ini hanya untuk instalasi lokal/demonstrasi dan **tidak boleh digunakan pada hosting publik**. Jangan import `seed_demo.sql` pada lingkungan produksi.

## Memperbarui database yang sudah ada

Jika database `simrs_farmasi` sudah berisi data dari versi sebelumnya:

1. Ekspor/backup database terlebih dahulu.
2. Jangan import ulang `schema.sql` ke database aktif untuk mencoba menambahkan kolom.
3. Jalankan satu kali file `database/migrations/2026_11_security_hardening.sql` melalui phpMyAdmin > database `simrs_farmasi` > Import.
4. Migration menambahkan `role`, `must_change_password`, dan tabel teknis `auth_login_attempts`.
5. Akun pengguna tertua dijadikan administrator awal dan diminta mengganti password saat login berikutnya.

## Akun dan hak akses

- `ADMIN`: dapat mengelola akun pengguna.
- `PETUGAS`: menjalankan modul operasional.
- Kedua peran memakai tampilan dan modul operasional farmasi yang sama. Pembatasan peran hanya diterapkan pada halaman pengelolaan akun.

Kebijakan password: minimal 12 karakter, huruf besar, huruf kecil, angka, dan simbol. Sesi berakhir setelah 30 menit tidak aktif secara default, dengan batas maksimum 8 jam.

## Dokumentasi

- [ERD dan desain database](docs/ERD.md)
- [Panduan instalasi dan upgrade](docs/INSTALLATION.md)
- [Panduan penggunaan](docs/USER_GUIDE.md)
- [Keamanan aplikasi](docs/SECURITY.md)
- [Rencana pengujian akhir](docs/TEST-PLAN.md)
- [Publikasi GitHub](docs/GITHUB.md)
- [Persiapan hosting](docs/DEPLOYMENT.md)
- [Riwayat perubahan](CHANGELOG.md)

## Database publik

Repository hanya menyediakan:
- `database/schema.sql`: struktur tabel kosong.
- `database/seed_demo.sql`: data fiktif untuk demo lokal.

Backup database asli, data pasien, NIK, transaksi operasional, password, dan file `.env` tidak boleh diunggah. Direktori `database/` diblokir dari akses HTTP oleh `.htaccess`.

## Foto dan aset

Foto kegiatan farmasi pada tampilan diambil dari sumber eksternal; lihat `assets/PHOTO-CREDITS.txt` untuk atribusi. Foto eksternal memerlukan koneksi internet.

## Catatan lisensi

Repository ini dipublikasikan untuk peninjauan dan dokumentasi proyek akademik. Belum ada lisensi open-source yang diberikan. Hak penggunaan, distribusi, dan modifikasi di luar izin pemilik tetap memerlukan persetujuan tertulis.
