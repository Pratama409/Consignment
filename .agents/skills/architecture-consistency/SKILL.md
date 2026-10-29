---
name: architecture-consistency
description: Tinjau rancangan dan konsistensi arsitektur proyek Consignment Inventory berbasis Laravel, Inertia.js, Vue, dan MySQL. Gunakan saat merancang modul, meninjau implementasi, atau merencanakan refactor.
---

# Consignment Inventory Architecture

## Konteks proyek
- Proyek baru menggunakan Laravel, Inertia.js, Vue, dan MySQL.
- Periksa composer.json dan package.json sebelum menentukan versi framework.
- Gunakan web routes, controller Laravel, dan halaman Inertia.
- REST API terpisah tidak diperlukan untuk CRUD internal.

## Tahap pekerjaan
Fokus saat ini adalah perancangan Master Data:
- Satuan: units.
- Vendor: vendors.
- Lokasi: locations.
- Barang: items.
- Hubungan barang–Vendor: vendor_items.

Selama pengguna masih meminta perancangan, hasilkan rancangan kolom,
relasi, validasi, dan keputusan terbuka. Jangan membuat migration atau CRUD.

## Aturan domain
- Part number boleh sama.
- Setiap barang mempunyai ID dan kode internal unik.
- Jenis kepemilikan: STOCK atau CONSIGNMENT.
- STOCK tidak mempunyai hubungan Vendor.
- CONSIGNMENT mempunyai minimal satu Vendor.
- Satu barang dapat terhubung dengan beberapa Vendor.
- Setiap pasangan barang–Vendor mempunyai satu lokasi.
- Minimum disimpan per barang dan berlaku sama untuk semua Vendornya.
- WAREHOUSE bukan Vendor.
- Lokasi belum diketahui menggunakan UNASSIGNED.
- Master yang sudah digunakan dinonaktifkan, bukan dihapus permanen.
- Seluruh tabel memakai PK UUID, termasuk tabel bawaan Laravel; FK juga UUID.
- Kode barang yang terlihat pengguna terpisah dari PK UUID.

## Cara meninjau
1. Baca file proyek dan dokumentasi yang benar-benar tersedia.
2. Bedakan keputusan yang disepakati, usulan, dan hal yang belum ditentukan.
3. Gunakan controller, Form Request, dan Eloquent untuk CRUD sederhana.
4. Usulkan Action atau Query hanya jika kompleksitas membutuhkannya.
5. Jangan menganggap komponen, RBAC, atau dokumentasi sudah tersedia.
6. Jangan membawa aturan WO, PR, PO, SAP, atau MRP dari proyek lain.

## Keputusan terbuka
- Lokasi barang STOCK memakai stock_item_locations yang merujuk locations.
- QUANTITY dan SERIALIZED adalah cara pelacakan, bukan jenis kepemilikan.
- Jangan membangun transaksi serialized sebelum alurnya dirancang.

## Hasil tinjauan
Laporkan:
- Bagian yang sudah konsisten.
- Masalah dan dampaknya.
- Usulan perbaikan.
- Keputusan yang masih diperlukan.
