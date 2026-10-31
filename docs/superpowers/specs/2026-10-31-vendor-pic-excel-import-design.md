# Vendor & PIC Excel Import Design

## Intent

Admin dapat menambahkan atau memperbarui Master Vendor dan PIC melalui file Excel dua kolom agar data awal Vendor tidak perlu diketik satu per satu.

## Input

- File `.xlsx`, maksimum 5 MB.
- Header wajib: `NAMA VENDOR` dan `PIC` (tidak peka huruf besar/kecil dan spasi tepi).
- Nama Vendor wajib diisi.
- Nilai PIC kosong atau `-` dinormalisasi menjadi `null`.
- Baris kosong diabaikan; formula ditolak.

## Matching dan perubahan data

- Vendor dicocokkan menggunakan nama yang sudah dinormalisasi, tanpa membedakan huruf besar/kecil.
- Vendor yang ditemukan: preview menunjukkan `UPDATE`; setelah konfirmasi hanya `pic_name` yang diperbarui. Kode dan status tidak berubah.
- Vendor yang belum ditemukan: preview menunjukkan `CREATE`; kode sementara unik dibuat dari nama dalam format uppercase slug, dipotong sesuai batas kolom, dan diberi suffix angka bila bentrok.
- Dua baris dalam file yang merujuk Vendor sama ditolak sebagai duplikat agar hasil tidak ambigu.
- `WAREHOUSE` ditolak karena merupakan pihak internal.

## Alur UI

1. Admin membuka Master Vendor dan memilih **Import Vendor & PIC**.
2. Admin dapat mengunduh template atau mengunggah file Excel.
3. Sistem menyimpan batch sementara dan menampilkan preview nomor baris, nama Vendor, PIC, aksi `CREATE/UPDATE`, kode yang digunakan, serta error.
4. Tombol konfirmasi aktif hanya ketika tidak ada error.
5. Konfirmasi menjalankan seluruh perubahan dalam satu transaksi database dan menampilkan jumlah Vendor dibuat/diperbarui.

## Keamanan dan akses

- Hanya Admin yang dapat mengunggah, melihat preview, dan mengonfirmasi.
- Batch menggunakan UUID dan terikat kepada user pengunggah.
- Konfirmasi bersifat idempotent: batch yang sudah selesai tidak dapat dijalankan ulang.
- File tidak disimpan sebagai dokumen publik.

## Data contoh penerimaan

File dengan SWI/BASUKI, SEFAS/-, SBS/THEA, NOVENA/ANNISA, MTG/-, IJT/APRIL, HYDAC/DARA, DUMA/ADHE, CBP/-, CBC Indonesia/-, BJP/-, ASIAREP/SATRIA, dan ANUGRAH CITRA PRIMATAMA/- harus dapat dipreview dan dikonfirmasi sesuai aturan di atas.

## Non-goals

- Import akun login Vendor.
- Menghubungkan Vendor ke Master Part secara otomatis.
- Mengubah kode Vendor lama dari file ini.
