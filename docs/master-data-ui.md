# Website Master Data Consignment

Implementasi mengikuti `DESIGN.md` dan skill lokal
`.agents/skills/frontend-design/SKILL.md`. Gunakan PHP/Laravel, Inertia 3,
Vue 3, serta komponen dan token CSS bersama yang sudah ada. PhpSpreadsheet
ditambahkan untuk membaca/menulis workbook XLSX di server.

## Menjalankan

```powershell
composer install
npm install
php artisan migrate
npm run build
php artisan app:create-admin
php artisan serve
```

Gunakan konfigurasi database proyek pada `.env`. Pembuatan admin hanya perlu
dijalankan bila belum ada akun admin; nama/email/kata sandi diminta secara
interaktif. Tidak ada akun demo atau pendaftaran publik. Buka
`http://127.0.0.1:8000/login`; setelah login pengguna masuk Master Barang.
Untuk mengembangkan UI, jalankan `npm run dev` bersama server Laravel.

## Navigasi dan kewenangan

| Halaman | URL | Alur |
|---|---|---|
| Login | `/login` | Masuk, validasi, pembatasan percobaan, logout |
| Master Barang | `/master-data/barang` | Daftar, cari/filter/urut/paginasi, export, tambah, detail, edit, aktif/nonaktif |
| Master Kategori | `/master-data/kategori` | Daftar, cari/filter/paginasi, tambah/edit kode dan nama kategori, aktif/nonaktif |
| Master Vendor | `/master-data/vendor` | Daftar, cari/filter/paginasi, tambah/edit identitas dan PIC, aktif/nonaktif |
| Master Satuan | `/master-data/satuan` | Daftar, cari/filter/paginasi, tambah/edit, aktif/nonaktif |
| Master Lokasi | `/master-data/lokasi` | Daftar, cari/filter/paginasi, tambah/edit keterangan, aktif/nonaktif |
| Import Master PN | `/master-data/import` | Template, unggah, preview/tinjau/perbaiki, konfirmasi, hasil/export |

Semua halaman membutuhkan login. Role `user` hanya membaca master, detail
barang, dan export katalog sesuai filter. Role `admin` dapat mengubah data
dan mengakses import. Backend memeriksa kewenangan pada semua route perubahan.
Preview import hanya dapat diakses admin yang mengunggahnya.

Sidebar desktop berubah menjadi drawer di bawah 1280 px. Tabel memiliki scroll
horizontal lokal. Dialog memakai fokus terperangkap, Escape saat aman, dan fokus
kembali ke pemicu. Ringkasan/pencarian/paginasi memakai data server, tanpa angka
contoh. Halaman welcome bawaan dan placeholder Dashboard dikeluarkan dari alur.
Transaksi stock/refill, PR/PO, serta QR/serial belum masuk tahap ini.

## Barang dan referensi

- Kode internal dibuat server, PN boleh sama dan tanda bacanya dipertahankan.
- ID master, relasi, akun, import, dan seluruh tabel bawaan memakai UUID. Kode
  barang yang terlihat tetap ITM-000001 dan tidak berubah akibat konversi ID.
- Kategori produk opsional dipilih dari Master Kategori. Data lama tetap belum
  dikategorikan; tidak ada kategori contoh yang dibuat otomatis. Kategori yang
  digunakan barang aktif tidak dapat dinonaktifkan. Kategori nonaktif pada
  barang lama tetap ditampilkan, tetapi tidak dapat dipilih untuk hubungan baru
  atau pengaktifan barang.
- Pencarian Barang mencakup kode/PN/nama, kategori, Vendor, dan lokasi aktif.
  Filter kategori mendukung Belum dikategorikan. Filter Vendor dan lokasi memakai
  relasi aktif; jika keduanya dipilih, harus cocok pada pasangan barang–Vendor
  yang sama. Filter/pagination dan export mengikuti kumpulan barang yang sama.
- STOCK milik internal, tanpa Vendor, dapat ditempatkan pada beberapa lokasi.
- CONSIGNMENT milik Vendor; minimal satu hubungan Vendor aktif. Setiap
  pasangan barang–Vendor memiliki satu lokasi utama. Vendor tidak boleh dipilih
  berulang dalam form yang sama.
- Kepemilikan dikunci setelah pembuatan. Pelacakan saat ini QUANTITY.
- Minimum adalah target refill, dapat diubah admin, nonnegatif, maksimal empat
  desimal; berlaku sama untuk semua Vendor barang tersebut.
- Edit mempertahankan ID barang dan ID hubungan lama. Penghapusan hubungan dari
  form menonaktifkan hubungan tersebut; riwayat terlihat pada detail barang.
- Vendor/PIC menggunakan satu nama PIC opsional. WAREHOUSE tidak boleh menjadi
  Vendor. UNASSIGNED adalah lokasi sistem: kode/status dikunci, keterangan dapat
  diubah. Referensi yang masih dipakai tidak dapat dinonaktifkan sesuai aturan
  penggunaan masing-masing model.

## Import dan export Excel

1. Unduh template atau pakai Master PN lama dengan enam header wajib:
   VENDOR, PART_NUMBER, DESCRIPTION, LOCATION, UOM, MATERIAL_TYPE.
   Kolom tambahan yang didukung: ITEM_CODE, MINIMUM_STOCK, IS_ACTIVE,
   TRACKING_TYPE, RELATION_ACTIVE, CATEGORY_CODE. Hanya sheet pertama dibaca.
   CATEGORY diterima sebagai alias CATEGORY_CODE dan nilainya harus berupa kode
   kategori terdaftar, bukan deskripsi yang ditebak menjadi kategori baru.
2. Pilih XLSX maksimal 5 MB / 5.000 baris data / 30 kolom; isi terurai maksimal
   32 MB. Tentukan target refill default untuk barang baru. Opsi membuat Vendor
   dan lokasi yang belum terdaftar harus dipilih secara eksplisit.
3. Tekan Validasi & Preview. Ini menyimpan snapshot preview selama 24 jam,
   belum menyimpan barang atau referensi baru. Riwayat delapan upload terakhir
   milik admin tersedia pada halaman unggah.
4. Tinjau kelompok. Barang dengan ITEM_CODE memperbarui identitas tersebut.
   PN yang berpotensi sama dengan barang terdaftar atau beberapa baris berbeda
   dalam satu kelompok harus diputuskan: satu barang, barang terpisah per baris
   unik, perbarui kode terdaftar, atau lewati. Satu kode tujuan tidak boleh
   ditimpa beberapa kelompok. Duplikat persis ditampilkan sebagai baris dilewati.
5. Perbaiki baris bila perlu. PN, nama, Vendor, lokasi, satuan, jenis, target,
   kategori, cara pelacakan, dan status dapat dikoreksi dalam preview. File asli tetap sama.
   Satuan yang berbeda memerlukan pemetaan eksplisit atau penambahan satuan.
   Lokasi gabungan/eksponensial dan formula sel wajib diperbaiki atau dilewati.
   CONSIGMENT dinormalisasi menjadi CONSIGNMENT; CARI LOKASI menjadi UNASSIGNED.
6. Setelah seluruh kelompok siap atau dilewati, buka Konfirmasi. Jika ada baris
   dilewati, admin wajib mencentang pengakuan sebelum Import Sekarang.
   Kolom di luar template ditampilkan sebagai peringatan pada preview; admin
   wajib mengakui secara terpisah bahwa nilai kolom tersebut tidak diimport.
   Server memvalidasi ulang dan menyimpan seluruh batch dalam satu transaksi.
   Kegagalan tidak meninggalkan perubahan sebagian. Konfirmasi ulang batch
   selesai tidak membuat barang ganda.
7. Halaman hasil menampilkan jumlah yang benar-benar dibuat/diperbarui/dilewati
   dan maksimal 50 tautan barang. Export Hasil berisi seluruh barang batch.

Barang lama mempertahankan target bila MINIMUM_STOCK kosong. Relasi yang tidak
dicantumkan tetap dipertahankan; RELATION_ACTIVE=0 menonaktifkan secara eksplisit.
Export katalog mengikuti pencarian, kategori, Vendor, lokasi, status, jenis dan urutan, mencakup seluruh
hasil filter (bukan hanya halaman saat ini), dan menyertakan ITEM_CODE serta
relasi aktif/nonaktif. Gunakan hasil export untuk pencocokan import berikutnya.
Nilai teks ditulis eksplisit agar nol di depan PN dan teks mirip formula tetap
utuh. Master PN asli tidak diimport otomatis oleh pemasangan aplikasi.

CATEGORY_CODE kosong mempertahankan kategori barang lama saat update; barang
baru tetap belum dikategorikan. Untuk menghapus kategori, gunakan form edit
Barang dan pilih Belum dikategorikan. Kategori yang belum dikenal tidak dibuat
oleh opsi pembuatan Vendor/lokasi baru dan harus diperbaiki pada preview.
Export membawa seluruh hubungan aktif/nonaktif dari barang yang lolos filter,
termasuk ketika filter Vendor/lokasi dipilih, agar data hubungan tetap lengkap
untuk import ulang. Format yang didukung tetap XLSX; CSV belum ditambahkan.

## Verifikasi

```powershell
php artisan test
php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml
php vendor/bin/pint --test
npm run build
npm run test:browser
```

PHP memakai SQLite memory atau database khusus `consignment_testing` di MySQL.
Browser memakai database SQLite baru di folder temporary `consignment-ui-*`,
akun fixture, dan server port 8011. Pengujian tidak mengisi database aplikasi.
Playwright memakai Chrome/Edge lokal; bila tidak tersedia, gunakan
`npx playwright install chromium` atau `PLAYWRIGHT_CHROMIUM_EXECUTABLE`.

Tes mencakup aturan bisnis, CRUD/otorisasi, validasi dan rollback import,
pencocokan kode, duplikat, koreksi, kedaluwarsa, export/import ulang, serta alur
browser dan layout pada 360/768/1280/1920 px. Screenshot dan trace disimpan di
`test-results/`, tidak dimasukkan ke version control.

Verifikasi 29 Oktober 2026: 112 tes PHP (975 assertion) lulus pada SQLite dan
MySQL pengujian; seluruh tes browser lulus. Build Vite dan pemeriksaan Pint
juga lulus. Migration kategori telah diterapkan pada database aplikasi tanpa
mengisi kategori barang lama secara otomatis.
