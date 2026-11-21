# Persiapan Hosting EightMedi

Dokumen ini menyiapkan aplikasi untuk hosting PHP + MariaDB. Deployment belum dilakukan oleh repository ini; domain, provider, dan kredensial hosting harus disediakan pemilik proyek.

## Kebutuhan server

- PHP 8.2+
- Extension `pdo_mysql`, `mbstring`
- MariaDB 10.4+ atau MySQL 8.0+
- Apache dengan dukungan `.htaccess`, atau aturan setara pada Nginx
- HTTPS aktif
- Document root diarahkan ke folder aplikasi

## Konfigurasi `.env`

Buat `.env` di root project (jangan commit):

```dotenv
APP_ENV=production
APP_BASE_URL=

DB_HOST=HOST_DATABASE_DARI_PROVIDER
DB_NAME=NAMA_DATABASE
DB_USER=USER_DATABASE_KHUSUS
DB_PASS=PASSWORD_DATABASE_KUAT
DB_CHARSET=utf8mb4
SESSION_IDLE_TIMEOUT=1800
```

Jika aplikasi dipasang pada subfolder, isi `APP_BASE_URL`, misalnya `/farmasi`.

## Database produksi

1. Buat database MariaDB/MySQL.
2. Buat user database khusus EightMedi.
3. Berikan hak akses minimum yang diperlukan hanya pada database tersebut.
4. Import `database/schema.sql`.
5. Jangan import `database/seed_demo.sql`.
6. Buat akun administrator produksi menggunakan akun admin yang dibuat secara aman atau CLI helper `database/create_admin.php`.
7. Hapus data demo yang mungkin tersisa.

## Membuat administrator melalui CLI

Setelah `.env` berisi konfigurasi produksi dan migration/schema sudah diterapkan, atur environment berikut pada terminal server lalu jalankan:

```bash
php database/create_admin.php
```

Script membaca `EIGHTMEDI_ADMIN_NAME`, `EIGHTMEDI_ADMIN_USERNAME`, dan `EIGHTMEDI_ADMIN_PASSWORD` dari environment proses. Gunakan password unik yang memenuhi kebijakan. Script hanya berjalan pada CLI dan menolak membuat admin jika administrator aktif sudah ada.

Jangan menyimpan password tersebut di file repository, screenshot, atau command history bersama.

## HTTPS dan Apache/Nginx

- Aktifkan sertifikat SSL pada panel hosting.
- Paksa redirect HTTP ke HTTPS melalui panel hosting atau konfigurasi web server.
- Pastikan PHP mendeteksi koneksi HTTPS agar cookie session memakai atribut Secure.
- Pertahankan aturan larangan akses ke `.env`, `.git`, file SQL, backup, dan folder `database/`.
- Jika hosting menggunakan Nginx, terapkan aturan deny yang setara karena `.htaccess` tidak dibaca oleh Nginx.

## Pemeriksaan setelah deployment

- Login dengan administrator produksi.
- Ganti password awal jika akun dibuat sebagai password sementara.
- Buat akun petugas dan uji aksesnya.
- Uji penerimaan obat, penyerahan resep, mutasi stok, dan laporan.
- Uji CSV dan print.
- Uji logout, tombol Back, dan akses URL langsung.
- Pastikan URL `.env`, `/database/schema.sql`, dan file backup mengembalikan 403/404.
- Periksa log PHP dan MariaDB.

## Data pasien

Jangan gunakan data pasien nyata pada demo publik. Penggunaan data kesehatan nyata memerlukan dasar hukum, tata kelola, kontrol akses, keamanan infrastruktur, backup, retensi, dan prosedur insiden yang sesuai.
