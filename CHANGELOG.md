# Changelog

## 1.2.0 — Security hardening and public repository preparation
- Menambahkan pembatasan percobaan login berbasis database.
- Menambahkan peran ADMIN/PETUGAS untuk pengelolaan akun; modul operasional tetap sama.
- Menambahkan pemaksaan penggantian password sementara dan halaman ubah password.
- Menambahkan timeout idle dan batas absolut sesi.
- Memverifikasi status akun pada request terlindungi.
- Memindahkan konfigurasi database ke environment/.env.
- Menambahkan schema, seed demo sintetis, migration, dokumentasi ERD, keamanan, dan hosting.
- Memperbaiki validasi stok dan saldo berjalan pada mutasi penyerahan resep untuk item obat berulang.
