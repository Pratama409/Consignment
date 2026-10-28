---
name: laravel-refactor
description: Tinjau dan refactor backend Laravel Consignment Inventory secara proporsional. Gunakan saat merapikan controller, validasi, query, atau proses penyimpanan tanpa mengubah perilaku bisnis.
---

# Consignment Inventory Laravel Refactor

## Prinsip
- Periksa implementasi sebelum mengusulkan refactor.
- Jika belum ada kode, jelaskan bahwa tugasnya adalah perancangan
  atau implementasi baru, bukan refactor.
- Jangan membuat lapisan arsitektur yang belum dibutuhkan.

## Pola backend
- Gunakan web routes dengan autentikasi dan otorisasi sesuai proyek.
- Gunakan Inertia::render untuk halaman.
- Gunakan redirect dan flash setelah mutasi.
- Gunakan Form Request untuk validasi.
- Gunakan Eloquent langsung untuk CRUD sederhana.
- Pertimbangkan Action untuk penyimpanan barang beserta beberapa Vendor.
- Gunakan DB::transaction untuk mutasi yang harus berhasil bersama.
- Gunakan eager loading ketika mengakses relasi dalam daftar.
- Pagination dilakukan pada backend.

## Validasi bisnis
- STOCK tidak menerima hubungan Vendor.
- CONSIGNMENT wajib memiliki minimal satu Vendor.
- Vendor dalam satu barang tidak boleh berulang.
- Setiap hubungan Vendor wajib memiliki lokasi.
- minimum_stock tidak boleh negatif.
- Part number boleh sama.
- Kode internal tetap unik dan stabil.
- Identitas vendor_items dipertahankan jika sudah digunakan transaksi.

## Otorisasi
- Jangan menganggap custom RBAC atau Spatie sudah terpasang.
- Gunakan mekanisme yang benar-benar tersedia.
- Menyembunyikan tombol di Vue tidak menggantikan otorisasi backend.
- Jangan menetapkan authorize() selalu true tanpa mengetahui
  tempat pemeriksaan otorisasi dilakukan.

## Verifikasi
- Jalankan pemeriksaan relevan yang tersedia sebelum dan sesudah refactor.
- Pertahankan kontrak route, props, dan flash yang sudah digunakan.
- Laporkan pemeriksaan yang tidak dapat dijalankan.
- Jangan mengubah kode di luar ruang lingkup tugas.