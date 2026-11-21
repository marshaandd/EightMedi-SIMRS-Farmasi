# Publikasi GitHub — EightMedi

Target repository: **Public**.

## 1. Buat repository

1. Masuk ke GitHub.
2. Pilih New repository.
3. Nama: `EightMedi-SIMRS-Farmasi`.
4. Deskripsi: `Academic demonstration project for a standalone hospital pharmacy management system built with PHP and MariaDB.`
5. Pilih **Public**.
6. Jangan centang inisialisasi README, `.gitignore`, atau license karena file tersebut sudah disiapkan di project.
7. Buat repository.

## 2. Pemeriksaan sebelum commit

Di folder project, pastikan:
- `.env` tidak ada di folder yang akan di-commit.
- Tidak ada backup `.sql` dari phpMyAdmin.
- Tidak ada data pasien/NIK asli.
- Tidak ada ZIP backup.
- `git status` tidak menampilkan file konfigurasi rahasia.

File `.env.example`, `database/schema.sql`, dan `database/seed_demo.sql` memang disediakan untuk instalasi dan demonstrasi.

## 3. Push dari Windows

Buka CMD atau terminal pada folder project:

```bat
cd C:\xampp\htdocs\simrs-farmasi
git init -b main
git add .
git status
git diff --cached --name-only
git commit -m "Initial public release EightMedi v1.2.0"
git remote add origin https://github.com/USERNAME/EightMedi-SIMRS-Farmasi.git
git push -u origin main
```

Ganti `USERNAME` dengan username GitHub pemilik repository.

Jika Git meminta identitas:

```bat
git config --global user.name "Nama Kamu"
git config --global user.email "email-github-kamu"
```

Jangan pernah menaruh Personal Access Token atau password GitHub di source code.

## 4. Sesudah push

1. Buka repository GitHub dan periksa struktur file.
2. Pastikan `.env` dan backup database tidak muncul.
3. Pastikan README tampil dengan benar.
4. Buka tab Actions dan periksa workflow PHP syntax check.
5. Tambahkan deskripsi, topics, dan screenshot aplikasi.
6. Buat Release dengan tag `v1.2.0` setelah pengujian lokal lulus.

## 5. Lisensi

Repository dapat dibuat Public tanpa memilih lisensi open-source. Tanpa file LICENSE, pengunjung dapat melihat repository tetapi tidak otomatis mendapat izin umum untuk menggunakan, mendistribusikan, atau memodifikasi kode.
