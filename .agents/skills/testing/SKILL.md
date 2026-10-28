---
name: testing
description: Verifikasi perilaku, validasi, integritas data, dan otorisasi Consignment Inventory. Gunakan saat menguji implementasi Master Data, import, atau perubahan backend dan frontend.
---

# Consignment Inventory Testing

## Persiapan
- Periksa apakah proyek menggunakan Pest atau PHPUnit.
- Gunakan framework dan konfigurasi yang tersedia.
- Gunakan database pengujian terpisah.
- Jangan menjalankan RefreshDatabase atau migrate:fresh pada database pengguna.
- Jangan menganggap role, seeder, factory, atau suite tertentu sudah tersedia.

## Cakupan Master Data
Uji perilaku yang sudah diimplementasikan:
- Daftar dan pagination.
- Pencarian dan filter status.
- Tambah dan edit data.
- Penolakan kode unik yang duplikat.
- Part number sama tetap diperbolehkan.
- Penolakan minimum negatif.
- Nonaktif dan aktif kembali.
- Data nonaktif tidak ditawarkan untuk pilihan baru.
- Referensi lama tetap dapat ditampilkan.

## Barang dan Vendor
- STOCK dapat disimpan tanpa Vendor.
- STOCK dengan payload Vendor ditolak.
- CONSIGNMENT tanpa Vendor ditolak.
- Vendor berulang ditolak.
- Vendor dan lokasi baru harus valid dan aktif.
- Kegagalan penyimpanan relasi membatalkan seluruh penyimpanan barang.
- Perubahan hubungan mempertahankan ID yang telah dirujuk transaksi.

## Import
Setelah import diimplementasikan, uji:
- Validasi dan preview.
- Normalisasi CONSIGMENT, WAREHOUSE, dan CARI LOKASI.
- Part number sama tidak otomatis dianggap duplikat.
- Import ulang mengikuti aturan pencocokan yang disepakati.
- Baris bermasalah tidak tersimpan tanpa penanganan yang jelas.

## Inertia dan akses
- Periksa nama halaman dan props melalui assertion Inertia.
- Uji akses pengguna yang diizinkan dan tidak diizinkan
  setelah mekanisme otorisasi ditetapkan.
- Periksa interaksi form, pesan error, dan layout secara manual
  bila lingkungan browser tersedia.

## Pelaksanaan
- Jalankan tes terarah lebih dahulu.
- Jalankan pemeriksaan tambahan sesuai risiko perubahan.
- Gunakan MySQL pengujian untuk perilaku yang bergantung pada MySQL.
- Laporkan perintah, hasil, dan keterbatasan secara jujur.
- Selama tahap perancangan, hasilkan skenario uji tanpa membuat
  implementasi atau mengklaim tes sudah dijalankan.