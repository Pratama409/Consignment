---
name: database-review
description: Tinjau rancangan tabel, migration, model, relasi, indeks, dan transaksi database Consignment Inventory. Gunakan untuk menilai integritas Master Data dan perubahan database.
---

# Consignment Inventory Database Review

## Batas tugas
Lakukan tinjauan dan berikan rekomendasi.
Jangan mengubah skema atau kode kecuali diminta mengimplementasikan.

## Struktur awal
- units: id, code, name, is_active, timestamps.
- categories: UUID id, code unik, name, is_active, timestamps; items.category_id UUID nullable.
- vendors: id, vendor_code, vendor_name, is_active, timestamps.
- locations: id, location_code, location_name, is_active, timestamps.
- items: id, item_code, part_number, item_name, ownership_type,
  tracking_type, unit_id, minimum_stock, is_active, timestamps.
- vendor_items: id, item_id, vendor_id, location_id,
  is_active, timestamps.

Keputusan terbaru pengguna: seluruh tabel memakai primary key UUID, termasuk
tabel bawaan Laravel. FK ke tabel tersebut juga UUID dengan tipe yang sesuai.
Pertahankan kode bisnis terpisah dari PK; periksa penyesuaian session, cache,
queue, reset token, dan migration repository saat meninjau tabel framework.
Migrasi dari ID numerik harus memetakan data dan relasi lama tanpa menghapus
akun, Master Data, atau preview import pengguna.

## Pemeriksaan integritas
- Kode satuan, Vendor, lokasi, dan barang harus unik pada tabelnya.
- part_number diindeks tetapi tidak unik.
- Pasangan item_id dan vendor_id harus unik.
- Foreign key harus sesuai tipe primary key yang dirujuk.
- Lindungi referensi bisnis dari penghapusan permanen.
- minimum_stock tidak boleh negatif.
- Kategori baru/yang dipilih ulang harus aktif; kategori yang dipakai barang aktif tidak dapat dinonaktifkan. NULL berarti belum dikategorikan, bukan dugaan dari nama barang.
- Jangan menambahkan unique(part_number, item_name) tanpa keputusan pengguna.
- Simpan barang dan hubungan Vendornya dalam satu transaksi database.
- Jangan menghapus lalu membuat ulang vendor_items yang sudah dirujuk transaksi.
- Status nonaktif harus mempertahankan identitas dan riwayat data.

## Aturan hubungan
- STOCK tidak boleh memiliki vendor_items.
- CONSIGNMENT wajib memiliki minimal satu hubungan Vendor aktif saat disimpan.
- Hubungan baru menggunakan Vendor dan lokasi aktif.
- Penonaktifan hubungan terakhir barang CONSIGNMENT harus ditangani secara eksplisit.
- Lokasi STOCK memakai tabel stock_item_locations yang merujuk master locations;
  satu barang STOCK dapat mempunyai beberapa penempatan internal.
- Jangan menyimpan saldo stok pada Master Data tanpa rancangan transaksi.

## Import Master PN
- Gunakan tahap validasi dan preview sebelum penyimpanan.
- Normalisasi CONSIGMENT menjadi CONSIGNMENT.
- WAREHOUSE pada barang STOCK tidak dibuat sebagai Vendor.
- Normalisasi CARI LOKASI menjadi UNASSIGNED.
- Jangan menentukan duplikat berdasarkan part number saja.
- Jangan menggabungkan barang lintas Vendor hanya karena part number sama.
- Tetapkan aturan pencocokan dan import ulang sebelum implementasi.

## Hasil tinjauan
Klasifikasikan temuan menjadi:
- Risiko integritas.
- Rekomendasi.
- Keputusan terbuka.

Sebutkan file dan baris jika implementasi tersedia.
Jika belum ada kode, tinjau rancangan tanpa mengarang lokasi file.
