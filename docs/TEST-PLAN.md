# Rencana Pengujian Akhir EightMedi

Pengujian ini dilakukan setelah paket rilis dipasang pada XAMPP. Skenario transaksi memakai data sintetis dan obat demonstrasi, bukan data pasien nyata.

## A. Integritas transaksi stok

| ID | Skenario | Hasil yang diharapkan |
|---|---|---|
| STK-01 | Penerimaan obat | `stock_qty` bertambah dan satu mutasi IN tercatat dalam transaksi yang sama |
| STK-02 | Penyerahan resep | `stock_qty` berkurang dan mutasi OUT tercatat |
| STK-03 | Penyerahan sebagian | Sisa resep tetap benar |
| STK-04 | Penyerahan lanjutan | Total penyerahan tidak melebihi resep |
| STK-05 | Stok kurang | Transaksi ditolak, tidak ada mutasi atau penyerahan parsial |
| STK-06 | Obat sama pada beberapa baris resep | Validasi total kebutuhan dan saldo mutasi berjalan benar |
| STK-07 | Pembatalan resep | Resep berstatus batal dan tidak menghapus histori transaksi sebelumnya |
| STK-08 | Dua proses bersamaan | Penguncian baris dan transaksi mencegah stok minus |

## B. Keamanan autentikasi

| ID | Skenario | Hasil yang diharapkan |
|---|---|---|
| AUTH-01 | Password salah 5 kali untuk akun+IP yang sama | Kombinasi dibatasi selama 15 menit |
| AUTH-02 | Percobaan salah lintas akun dari IP sama hingga 20 kali | IP dibatasi selama 15 menit |
| AUTH-03 | Login berhasil | Session ID berubah dan penghitung dibersihkan |
| AUTH-04 | Password sementara | Pengguna wajib mengganti password sebelum membuka modul lain |
| AUTH-05 | Password lemah | Ditolak oleh validasi server |
| AUTH-06 | Logout lalu tombol Back | Halaman terlindungi tidak dapat digunakan tanpa login |
| AUTH-07 | Session idle melebihi batas | Pengguna diarahkan kembali ke login |
| AUTH-08 | Akun dinonaktifkan ketika masih login | Request terlindungi berikutnya mengakhiri sesi |

## C. Pengelolaan akun

- Administrator dapat membuat akun.
- Petugas tidak dapat membuka halaman pengelolaan akun.
- Pengguna dapat mengubah password sendiri.
- Administrator dapat mengatur ulang password pengguna.
- Administrator aktif terakhir tidak dapat dinonaktifkan atau diturunkan perannya.
- Semua peran tetap menggunakan modul operasional yang sama.

## D. Laporan

- Total stok sama dengan master obat.
- Mutasi IN dan OUT sama dengan transaksi sumber.
- Filter tanggal tidak mengubah data.
- CSV dapat dibuka di spreadsheet.
- Print/PDF menampilkan filter dan hasil yang benar.

## E. Hosting dan repository publik

- Tidak ada `.env`, dump database operasional, NIK, data pasien, atau file backup di Git.
- `/database/` dan `.env` tidak dapat diakses melalui HTTP.
- HTTPS aktif dan cookie session memiliki atribut Secure.
- Clone repository dan instalasi baru berhasil.
- Workflow GitHub Actions lulus PHP lint.
