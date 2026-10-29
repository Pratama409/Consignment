# Layout aplikasi dan Master Satuan

Panduan visual: `DESIGN.md` dan `.agents/skills/frontend-design/SKILL.md`.
Halaman menggunakan Laravel web routes, Inertia 3, Vue 3, Vite 7, dan Tailwind 4
yang dipadukan dengan token CSS bersama. Tidak memerlukan font atau ikon dari
layanan eksternal. Ikon berasal dari satu komponen SVG `AppIcon`.

## Menjalankan aplikasi

```powershell
composer install
npm install
php artisan migrate
npm run build
php artisan app:create-admin
php artisan serve
```

Buka `http://127.0.0.1:8000/login`. Perintah pembuatan admin meminta nama, email,
kata sandi minimal 12 karakter, dan konfirmasi secara interaktif. Akun yang
sudah ada tidak dipromosikan otomatis. Tidak ada akun/password admin bawaan
atau pendaftaran publik. Saat pengembangan UI, `npm run dev` dapat dijalankan
bersama server Laravel; hasil build dapat berjalan tanpa Vite dev server.

## Perilaku halaman

- URL Master Satuan: `/master-data/satuan`.
- Semua akun yang sudah login dapat membaca; hanya role `admin` boleh menambah,
  mengedit, mengaktifkan, dan menonaktifkan. Policy dan Form Request memeriksa
  akses pada backend, bukan hanya menyembunyikan tombol.
- Kolom role default `user`. Role tidak termasuk mass-assignment User.
- Ringkasan Total/Aktif/Nonaktif mencakup seluruh satuan di database. Jumlah
  hasil tabel mengikuti filter dan pagination server.
- Pencarian kode/nama ditunda 350 ms setelah input. Filter status, ukuran
  halaman 10/25/50, serta urutan kode/nama diterapkan server.
- Kode di-trim dan dinormalisasi menjadi uppercase. Kode nonaktif tetap unik.
- Edit mempertahankan ID. Penonaktifan ditolak jika satuan masih digunakan
  barang aktif. Tidak ada route penghapusan.
- Tambah/edit dan perubahan status memakai Inertia `useForm`. Error server
  ditampilkan dekat field; input dipertahankan saat validasi gagal.
- Modal dan drawer memakai native dialog ditambah siklus fokus Tab/Shift+Tab,
  Escape ketika tidak memproses, dan pengembalian fokus ke pemicu.
- Desktop menampilkan sidebar tetap. Di bawah 1280 px sidebar menjadi drawer.
  Pada mobile, tabel dapat digeser horizontal dalam container-nya.
- Navigasi terhubung ke Barang, Kategori, Vendor, Satuan, Lokasi, dan Import Master PN
  (khusus admin). Setelah login, halaman awal adalah Master Barang.
- Login menampilkan identitas/role asli setelah autentikasi. Dashboard,
  notifikasi, dark mode, dan transaksi tidak masuk tahap Master Data.
- Seluruh alur website dan Excel dijelaskan pada `docs/master-data-ui.md`.

## Verifikasi

```powershell
php artisan test
php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml
npm run build
npm run test:browser
php vendor/laravel/pint/builds/pint --test
```

Tes PHP memakai SQLite memory atau database MySQL `consignment_testing`.
Tes browser membuat database SQLite baru dalam direktori temporary
`consignment-ui-*`, menjalankan server khusus pada port 8011, dan memakai akun
fixture pengujian. Tes tidak mengubah database aplikasi. Jangan gunakan
kredensial fixture untuk akun aplikasi.

Playwright menggunakan Chrome/Edge lokal jika ditemukan. Jika tidak ada browser
lokal, jalankan `npx playwright install chromium` atau tentukan executable lewat
`PLAYWRIGHT_CHROMIUM_EXECUTABLE`.

Tes browser mencakup lebar 360, 768, 1280, 1920 px, overflow, navigasi modal,
tambah/edit/aktif/nonaktif, validasi duplikat, filter/pagination, kegagalan
koneksi, dan akses baca pengguna nonadmin. Screenshot layout menggunakan lima
satuan fixture dan identitas admin pengujian, bukan identitas pengguna aplikasi.
Artefak disimpan di `test-results/` yang tidak disertakan ke version control.
