# Master Data v1

Database aplikasi: MySQL `consignment`. Database tes: MySQL
`consignment_testing` atau SQLite `:memory:`. Jangan menjalankan tes pada
database aplikasi. `Tests\TestCase` memeriksa koneksi sebelum trait pengujian
menjalankan migration.

Koneksi MySQL proyek secara eksplisit menggunakan InnoDB agar foreign key dan
rollback transaksi berlaku, termasuk jika default server WAMP adalah MyISAM.
Konfigurasi tes memakai APP_KEY khusus pengujian yang tidak dipakai aplikasi.

## Tabel dan aturan

- `units`: kode unik, nama, status.
- `vendors`: kode unik, nama, `pic_name` opsional, status. WAREHOUSE bukan Vendor.
- `locations`: kode unik, keterangan opsional, status. UNASSIGNED adalah lokasi
  sistem untuk penempatan yang belum diketahui.
- `items`: kode unik buatan sistem, PN berindeks nonunik, nama, ownership,
  tracking, satuan, target refill `minimum_stock DECIMAL(18,4)`, status.
- `vendor_items`: pasangan barang–Vendor unik, satu lokasi utama, status.
- `stock_item_locations`: pasangan barang STOCK–lokasi unik, status. Mendukung
  penyimpanan lokasi internal yang berulang dalam Excel tanpa saldo stok.

Semua tabel menggunakan primary key UUID, termasuk tabel bawaan Laravel dan
`migrations`. Kolom FK memakai UUID dengan ON DELETE RESTRICT. Pada MySQL,
UUID disimpan sebagai CHAR(36). Model aplikasi menggunakan HasUuids Laravel
(UUID v7); insert Query Builder yang tidak mengisi ID memakai default UUID
database. Angka jumlah, nomor urut, dan timestamp tetap bertipe numerik.
Ownership dikunci setelah dibuat. Minimum tidak negatif dan dapat diedit;
nilainya berlaku sama untuk setiap Vendor dari barang tersebut. SERIALIZED
dicadangkan di skema; alur penyimpanan v1 hanya menerima QUANTITY. QR dan
transaksi stok belum dibuat.

## Batas constraint database dan aturan aplikasi

Constraint/trigger database melindungi kode unik, FK, pasangan unik, pilihan
ownership/tracking, minimum nonnegatif, ownership immutable, dan pemisahan
vendor_items CONSIGNMENT dari stock_item_locations STOCK.

Aturan lintas record, terutama minimal satu Vendor aktif, pemilihan master
aktif, dan penonaktifan hubungan terakhir, dijalankan melalui model dan
`App\Actions\MasterData\SaveItem`. MySQL tidak memiliki deferred constraint
untuk memeriksa keberadaan child saat parent dan child dibuat bersamaan.
Karena itu buat/edit barang melalui `SaveItem::handle($data, $item)` dalam
aplikasi, bukan lewat insert/query builder langsung. SQL langsung dapat
melewati event model dan pemeriksaan agregat aplikasi.

`SaveItem` menerima form lengkap: part_number, item_name, ownership_type,
unit_id, minimum_stock; tracking_type/is_active opsional. Relasi consignment
diisi melalui vendor_items berisi vendor_id, location_id, is_active opsional.
Penempatan STOCK menggunakan stock_locations berisi location_id dan status
opsional. Payload relasi adalah kondisi lengkap yang diinginkan, bukan PATCH:
relasi yang tidak dikirim dinonaktifkan. Relasi yang digunakan lagi diaktifkan
pada ID lama. Pengganti Vendor disimpan sebelum hubungan lama dinonaktifkan.

Kode ITM-000001 terpisah dari PK UUID. Tabel `number_sequences` memiliki PK UUID
dan menyimpan counter numerik `last_value` untuk `item_code`. `ItemCodeGenerator`
mengunci baris counter dalam transaksi penyimpanan barang. Tidak menggunakan
MAX(id) + 1 atau memotong UUID. Konversi mempertahankan kode lama dan melanjutkan
nomor sesudah kode tertinggi yang sudah ada. Nomor dari transaksi yang dibatalkan
tidak dianggap sebagai kode barang yang telah diterbitkan.

## Relasi Vendor, part, dan lokasi

Vendor dan lokasi masing-masing mempunyai tabel master sendiri. Keputusan
terbaru tetap mengizinkan satu barang dari beberapa Vendor:

| FK UUID | Referensi PK UUID | Aturan |
|---|---|---|
| items.unit_id | units.id | Satuan barang |
| vendor_items.item_id | items.id | Barang consignment |
| vendor_items.vendor_id | vendors.id | Vendor pemasok/titipan |
| vendor_items.location_id | locations.id | Satu lokasi utama per pasangan barang–Vendor |
| stock_item_locations.item_id | items.id | Barang STOCK internal |
| stock_item_locations.location_id | locations.id | Penempatan STOCK; dapat beberapa lokasi |
| master_data_imports.user_id | users.id | Pemilik preview import |
| sessions.user_id | users.id | Pemilik sesi; dapat NULL untuk tamu |

`vendor_items` mempunyai PK UUID sendiri dan unique(item_id, vendor_id).
Vendor pada list part dibaca dari relasi ini. Menambahkan satu vendor_id pada
items akan membatasi barang menjadi satu Vendor, sehingga struktur banyak
Vendor tetap menggunakan tabel penghubung.

## Tabel bawaan Laravel dan konversi UUID

Semua 17 tabel memiliki PK `id` UUID: units, vendors, locations, items,
vendor_items, stock_item_locations, users, master_data_imports, number_sequences,
password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches,
failed_jobs, migrations.

Lookup bawaan tetap memiliki unique key yang sesuai: email pada reset password,
key pada cache/lock, serta session_key pada sesi. Token cookie sesi tetap token
acak Laravel; identitas baris sessions menggunakan UUID terpisah.
`UuidDatabaseSessionHandler` menghubungkan session_key dengan baris sesi.
`UuidDatabaseQueue` mengisi dan mengembalikan UUID saat push/release/bulk,
sehingga antrean tidak bergantung pada insertGetId auto-increment.

Migration `2026_10_29_001000_convert_all_table_primary_keys_to_uuid` mengonversi
skema lama tanpa migrate:fresh pada database aplikasi. Hentikan worker antrean,
pastikan jobs kosong, lalu gunakan mode pemeliharaan selama konversi:

```powershell
php artisan down --retry=30
php artisan migrate --force
php artisan up
```

Migration membuat snapshot terenkripsi di
`storage/app/private/database-backups/pre-uuid-*.enc` menggunakan APP_KEY yang
ada. Snapshot menyertakan data, skema MySQL, serta pemetaan ID lama–UUID. Simpan
APP_KEY bersama backup secara aman untuk kebutuhan pemulihan. Pada tes isolated,
backup file tidak dibuat. Tidak ada backup yang diekspos melalui web.

Tabel tujuan disiapkan terpisah, setiap nilai/baris diverifikasi, lalu tabel
ditukar (atomic RENAME TABLE pada MySQL). FK, pemilik sesi, pemetaan satuan pada
preview, serta ID barang pada hasil import dikonversi bersama. Kode bisnis,
password admin, role, dan isi Master Data dipertahankan. Guard integritas dipasang
sebelum tabel lama dilepas. Kegagalan sebelum finalisasi mengembalikan tabel asal.
Konversi diuji dengan data lama terisi dan kegagalan saat penyalinan maupun
pemasangan guard. `down()` konversi sengaja tidak mengubah UUID menjadi angka;
pemulihan ke skema lama memerlukan backup sebelum konversi.

Master dinonaktifkan, bukan dihapus melalui model. Vendor/lokasi yang masih
memiliki hubungan aktif tidak dapat dinonaktifkan. Satuan yang masih digunakan
barang aktif juga tidak dapat dinonaktifkan. Penonaktifan barang tidak menghapus
hubungan historis dan bukan konversi ownership; CONSIGNMENT tetap mempertahankan
minimal satu hubungan Vendor aktif.

## Data awal dan import

`MasterDataSeeder` menambahkan PC, SET, KG, M, L dan lokasi UNASSIGNED dengan
firstOrCreate sehingga tidak menimpa nama/status yang sudah diubah. Tidak
membuat akun demo, Vendor WAREHOUSE, saldo, atau barang contoh.

Master PN.xlsx belum diimport ke database aplikasi. UI Barang, Vendor/PIC,
Satuan, Lokasi, serta import/export Excel tersedia; lihat
`docs/master-data-ui.md` untuk seluruh alur, login admin, dan pengujiannya.
Import pertama harus melalui preview dan pemetaan identitas barang. Export
berikutnya harus membawa item_code untuk pencocokan import ulang. PN atau
PN–nama tidak boleh menjadi unique key. Tiga baris consignment multi-lokasi
harus ditinjau untuk menentukan satu lokasi utama. Lokasi eksponensial serta
UOM 001 harus diverifikasi; CARI LOKASI dinormalisasi menjadi UNASSIGNED dan
CONSIGMENT menjadi CONSIGNMENT. Jangan menghapus tanda baca PN secara otomatis.

Tabel `master_data_imports` menyimpan snapshot baris sumber, opsi, keputusan
kelompok, koreksi baris, pemilik preview, waktu kedaluwarsa, dan hasil konfirmasi.
Preview hanya dapat dibaca/diubah pemiliknya yang berrole admin, berlaku 24 jam,
dan tidak mengubah master. Konfirmasi mengunci batch, memvalidasi ulang terhadap
master terkini, lalu menjalankan seluruh perubahan master dalam satu transaksi
InnoDB. Batch selesai mengembalikan hasil yang sama pada konfirmasi ulang.
Hash file mencatat sumber, bukan unique key: file yang sama boleh diunggah untuk
tinjauan baru. Pencocokan import ulang harus memakai ITEM_CODE atau keputusan
admin, tidak memakai PN secara otomatis.

Import menambahkan atau memperbarui relasi yang tercantum dan mempertahankan
relasi lama yang tidak dicantumkan. RELATION_ACTIVE=0 menonaktifkan relasi
secara eksplisit. Ini berbeda dari form edit lengkap SaveItem; importer menyusun
payload lengkap dari snapshot barang dan perubahan file sebelum memanggilnya.
Export membawa semua relasi aktif/nonaktif agar status dan identitas tidak
hilang saat import ulang. Semua sel data ditulis sebagai nilai teks, termasuk
PN berawalan nol dan teks yang menyerupai formula.

## Perintah

```powershell
php artisan migrate --seed
php artisan test
php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml
php vendor/bin/pint --test
```

Konfigurasi MySQL pengujian mengikuti WAMP lokal (127.0.0.1:3306). Sesuaikan
kredensial pengujian jika lingkungan berubah; nama database tes tetap terpisah.
DDL rollback hanya diuji pada database tes.
