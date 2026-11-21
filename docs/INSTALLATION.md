# Instalasi dan Upgrade EightMedi

## A. Instalasi baru di XAMPP

1. Ekstrak folder `simrs-farmasi` ke `C:\xampp\htdocs\`.
2. Jalankan Apache dan MySQL di XAMPP.
3. Buka phpMyAdmin, lalu import `database/schema.sql`.
4. Import `database/seed_demo.sql` untuk data contoh lokal.
5. Salin `.env.example` menjadi `.env` di folder `C:\xampp\htdocs\simrs-farmasi\`.
6. Periksa nilai `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, dan `APP_BASE_URL`.
7. Buka `http://localhost/simrs-farmasi/`.
8. Login menggunakan akun demo di README. Ganti password saat diminta.

## B. Upgrade database EightMedi yang sudah berjalan

1. Backup database melalui phpMyAdmin > pilih `simrs_farmasi` > Export > Quick > SQL.
2. Backup folder `C:\xampp\htdocs\simrs-farmasi`.
3. Salin source code rilis baru ke `C:\xampp\htdocs\simrs-farmasi` dan Replace.
4. Salin `.env.example` menjadi `.env` bila file `.env` belum ada. Jika sudah ada, pertahankan file `.env` lama dan cek nilai konfigurasi.
5. Di phpMyAdmin, pilih database `simrs_farmasi`.
6. Buka tab Import dan jalankan satu kali `database/migrations/2026_11_security_hardening.sql`.
7. Migration menambahkan kolom `role`, `must_change_password`, dan tabel `auth_login_attempts`. Akun aktif tertua menjadi administrator dan diminta mengganti password pada login berikutnya.
8. Buka `http://localhost/simrs-farmasi/` dan login dengan password akun lama.
9. Buat password baru sesuai kebijakan.
10. Pastikan menu Pengguna hanya terlihat pada administrator.

**Jangan** import `database/schema.sql` atau `database/seed_demo.sql` ke database operasional yang sudah berisi data.

## C. Jika akun lama tidak diketahui

- Jangan hapus tabel `users`.
- Jangan membuat akun melalui SQL dengan password teks biasa.
- Pulihkan dari backup atau gunakan prosedur reset password administrator yang aman melalui akses server/CLI.
- Jangan mengirim password atau hash ke repository publik.
