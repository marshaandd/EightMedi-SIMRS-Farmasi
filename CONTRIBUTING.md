# Kontribusi

1. Buat branch untuk perubahan.
2. Jangan commit file `.env`, dump database operasional, backup, atau data pasien.
3. Jalankan `php -l` pada seluruh file PHP yang diubah.
4. Untuk perubahan transaksi stok, sertakan skenario berhasil dan rollback.
5. Jelaskan perubahan database melalui migration SQL terpisah.
6. Jangan mengubah schema produksi tanpa backup dan review.
