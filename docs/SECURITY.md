# Keamanan EightMedi

## Kontrol yang tersedia

- Password disimpan menggunakan `password_hash()` dan diverifikasi dengan `password_verify()`.
- Kebijakan password baru: minimal 12 karakter, huruf besar, huruf kecil, angka, dan simbol.
- Percobaan login dibatasi 5 kali per kombinasi username+IP dan 20 kali per IP dalam 15 menit.
- Kunci rate limit disimpan sebagai SHA-256; IP mentah tidak disimpan.
- Session memakai strict mode, cookie-only, HttpOnly, SameSite=Lax, dan Secure saat HTTPS terdeteksi.
- Session idle timeout default 30 menit dan batas sesi absolut 8 jam.
- Session ID diregenerasi setelah login dan perubahan password.
- Status akun aktif diverifikasi kembali pada setiap request terlindungi.
- Halaman autentikasi dan perubahan akun memakai token CSRF.
- Query database memakai PDO prepared statements.
- Halaman terlindungi mengirim header no-store untuk mencegah cache browser setelah logout.
- `app/` dan `database/` diblokir dari akses HTTP melalui `.htaccess`.

## Peran pengguna

`ADMIN` dapat mengelola akun. `PETUGAS` dan `ADMIN` tetap memiliki tampilan dan akses yang sama pada modul operasional. Administrator aktif terakhir tidak dapat dinonaktifkan atau diturunkan perannya.

## Wajib dilakukan sebelum hosting

1. Gunakan password database yang kuat dan user database khusus, bukan `root`.
2. Gunakan HTTPS.
3. Jangan import `seed_demo.sql` di production.
4. Buat administrator produksi dengan password unik.
5. Hapus data demo dan pastikan tidak ada data pasien nyata pada repository.
6. Pastikan `.env`, backup, dump SQL operasional, dan log tidak masuk Git.
7. Batasi akses phpMyAdmin dan panel hosting.
8. Aktifkan backup database terjadwal di hosting.

## Batasan

Kontrol ini adalah hardening dasar untuk proyek demonstrasi. Ini bukan audit penetrasi, audit kepatuhan, atau sertifikasi keamanan. Sebelum pemakaian klinis, diperlukan review profesional, pengujian keamanan independen, kebijakan privasi/retensi, kontrol akses yang sesuai, dan prosedur operasional rumah sakit.
