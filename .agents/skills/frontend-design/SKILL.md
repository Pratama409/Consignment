---
name: frontend-design
description: Rancang dan implementasikan antarmuka Consignment Inventory menggunakan Vue dan Inertia.js. Gunakan saat merancang halaman Master Data, form, tabel, filter, atau interaksi pengguna.
---

# Consignment Inventory Frontend

## Dasar
- Ikuti versi Vue, Inertia, styling, dan struktur folder proyek yang tersedia.
- Gunakan pola Inertia untuk navigasi dan pengiriman form.
- Terima data halaman melalui props.
- Gunakan useForm untuk form jika sesuai versi Inertia yang terpasang.
- Jangan membuat REST API atau Axios CRUD tanpa kebutuhan khusus.

## Proyek baru
- Periksa komponen starter kit sebelum membuat komponen baru.
- Jangan menganggap DataTable, Modal, useToast, atau usePermission tersedia.
- Buat komponen bersama ketika ada kebutuhan pemakaian berulang.
- Jangan menambah dependency hanya untuk kebutuhan sederhana.

## Halaman Master Data
Rancang halaman:
- Barang.
- Vendor.
- Satuan.
- Lokasi.
- Import Master PN.

Tabel mendukung pencarian, filter status, pagination, tambah, edit,
serta aktif/nonaktif sesuai kewenangan.

## Form Barang
- Tampilkan part number, nama, kepemilikan, satuan,
  minimum, cara pelacakan, dan status.
- Kode internal dibuat sistem.
- STOCK tidak menampilkan input Vendor.
- CONSIGNMENT menampilkan minimal satu pasangan Vendor dan lokasi.
- Cegah pemilihan Vendor yang sama berulang kali.
- Validasi backend tetap menjadi sumber kebenaran.

## Pengalaman pengguna
- Gunakan label dan pesan berbahasa Indonesia.
- Bedakan status Aktif/Nonaktif dari jenis STOCK/CONSIGNMENT.
- Tampilkan error dekat input terkait.
- Cegah submit berulang selama proses.
- Sediakan keadaan kosong, proses, berhasil, dan gagal.
- Pertahankan filter ketika berpindah halaman.
- Pastikan tabel dapat digunakan pada layar sempit.
- Gunakan label form yang terhubung dengan input dan fokus keyboard terlihat.

## Batas tahap
Jika tugas masih perancangan, hasilkan struktur halaman dan perilaku form.
Jangan membuat halaman Vue sebelum diminta mengimplementasikan.