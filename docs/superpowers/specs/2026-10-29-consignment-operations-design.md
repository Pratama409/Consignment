# Desain Operasional Consignment: Role, Ledger, Refill, MR, Procurement, dan Rekonsiliasi

Tanggal: 29 Oktober 2026 (WITA)  
Status: rancangan untuk persetujuan pengguna  
Proyek: Laravel 13, Inertia 3, Vue 3, MySQL, UUID

## 1. Maksud dan alasan

Master Data yang ada sudah dapat menyimpan barang internal (`STOCK`), barang
milik Vendor (`CONSIGNMENT`), relasi banyak Vendor per barang, lokasi, kategori,
satuan, dan target refill. Tahap berikutnya mengubah aplikasi dari pengelola
master menjadi sistem operasional yang dapat menjawab tiga pertanyaan dengan
jejak yang dapat diaudit:

1. barang siapa, berada di lokasi mana, dan berapa saldo yang tersedia;
2. transaksi apa yang menambah atau mengurangi saldo tersebut; dan
3. dokumen operasional serta dokumen SAP apa yang mendasarinya.

Sistem harus mendukung pekerjaan Admin, Warehouse, dan Vendor tanpa
mencampurkan saldo internal dengan saldo milik masing-masing Vendor. Proses PR,
PO, validasi, dan unggah dokumen melengkapi transaksi; proses itu tidak boleh
menambah atau mengurangi stok untuk kedua kalinya.

## 2. Sumber keputusan dan urutan otoritas

Desain ini menyatukan:

- keputusan terbaru pengguna dalam percakapan;
- transkrip meeting gabungan;
- video web referensi buatan pengguna;
- `DESIGN.md` dan dokumentasi Master Data;
- struktur serta dependensi proyek saat ini.

Jika terdapat perbedaan, keputusan terbaru pengguna mengalahkan contoh pada
video atau usulan dalam transkrip. Video adalah referensi alur dan tampilan,
bukan bukti bahwa logika web lama harus disalin apa adanya.

## 3. Sasaran

Ruang lingkup pekerjaan terdiri dari delapan bagian yang dibangun di atas satu
fondasi stok:

1. User Management dengan role Admin, Warehouse, dan Vendor.
2. Saldo awal dan aktivasi cut-off operasional.
3. Ledger stok per barang–Vendor–lokasi atau barang–lokasi.
4. Posting, reversal, audit, dan idempotensi.
5. Vendor Refill, Incoming Refill, dan Data IN manual.
6. MR internal dan Data OUT.
7. Reservasi/PR, validasi, PO, GRGI, dan dokumen MR.
8. Rekap per shift, Stock Sistem, dan BA Reconcile Vendor.

Keberhasilan berarti saldo dapat ditelusuri sampai transaksi sumber, transaksi
ulang tidak menggandakan stok, Vendor hanya melihat datanya sendiri, saldo tidak
dapat negatif, dan laporan dapat direproduksi dari ledger.

## 4. Di luar ruang lingkup

Tahap ini tidak mencakup:

- QR/barcode dan pencetakan label;
- pelacakan per serial number;
- rotable, tyre, damage core, dan siklus repair;
- integrasi langsung atau real-time dengan SAP;
- portal requester atau role approver departemen;
- Stock Opname lengkap, blind count, foto, AI, dan freeze stok;
- deployment production;
- dashboard finansial, ITO, dan umur komponen yang sumber datanya belum ada.

Kolom physical quantity pada BA dapat diisi Admin sebagai angka rekonsiliasi
sementara. Modul Stock Opname penuh tetap pekerjaan terpisah.

## 5. Anti-sasaran

Desain dianggap gagal walaupun halaman terlihat selesai jika salah satu hal ini
terjadi:

- stok disimpan hanya sebagai angka yang ditimpa tanpa histori pergerakan;
- saldo Vendor A dan Vendor B untuk barang yang sama tercampur;
- PR, PO, atau unggah dokumen ikut mengubah stok;
- klik ulang atau request jaringan ulang memposting transaksi dua kali;
- transaksi posted dapat diedit atau dihapus tanpa reversal;
- akun Vendor dapat melihat query, detail, export, atau dokumen Vendor lain;
- perubahan jadwal shift mengubah pengelompokan transaksi lama;
- saldo awal diubah diam-diam setelah periode aktif.

## 6. Pendekatan yang dipertimbangkan

### 6.1 Modular monolith dengan immutable ledger, dibangun bertahap — dipilih

Tetap menggunakan Laravel, Inertia, dan Vue yang sudah ada. Modul memiliki tabel
dan aturan masing-masing, tetapi semua perubahan stok diposting melalui satu
layanan ledger. Saldo cepat adalah proyeksi/cache; ledger tetap sumber kebenaran.
Pendekatan ini menjaga integritas stok, masih mudah dipahami tim, dan dapat
dirilis bertahap.

### 6.2 CRUD per modul dengan saldo langsung

Lebih cepat pada awalnya, tetapi setiap modul harus mengubah saldo sendiri.
Risiko penerimaan refill dan Data IN menggandakan stok, koreksi sulit diaudit,
dan concurrency dapat menghasilkan saldo salah. Tidak dipilih.

### 6.3 Event sourcing penuh

Audit dan rekonstruksi sangat kuat, tetapi memerlukan event store, projection
management, serta pola pengembangan yang terlalu berat untuk proyek dan tim
saat ini. Tidak dipilih.

## 7. Arsitektur aplikasi

Sistem tetap berupa modular monolith:

- **Master Data** menyediakan identitas barang, Vendor, lokasi, kategori, dan
  satuan.
- **Identity & Access** mengelola pengguna dan pembatasan Vendor.
- **Inventory Core** memiliki posting service, ledger, lot, saldo proyeksi,
  reversal, dan adjustment.
- **Refill** mengelola permintaan, revisi Vendor, pengiriman, dan penerimaan.
- **Material Request** mengelola permintaan internal dan pengeluaran.
- **Procurement Documentation** mengelola Reservasi, PR, validasi, PO, GRGI,
  lampiran, serta import/export Excel.
- **Monitoring** membaca ledger dan dokumen untuk rekap shift, Stock Sistem,
  dan BA.
- **Audit** mencatat perubahan bisnis yang tidak seluruhnya direpresentasikan
  oleh ledger.

Controller hanya mengatur HTTP, validasi, dan respons Inertia. Perubahan status
dan stok dilakukan oleh action/service dalam transaksi database. Policy dan
query scope membatasi akses di backend; menyembunyikan menu saja tidak dianggap
sebagai otorisasi.

## 8. Model identitas dan akses

### 8.1 Role

Hanya terdapat tiga jenis role:

| Role | Hak utama |
|---|---|
| `ADMIN` | Master Data, User Management, aktivasi saldo awal, approval/koreksi, reversal/adjustment, dokumen procurement, monitoring, finalisasi BA |
| `WAREHOUSE` | membuat refill, menerima barang, Data IN manual, MR, Data OUT, follow-up, serta melihat stok dan laporan operasional |
| `VENDOR` | melihat data Vendor sendiri, merespons refill, mengajukan revisi qty, mengisi informasi pengiriman, meninjau BA Vendor sendiri |

Tiga nilai di atas adalah tiga **jenis role**, bukan batas hanya tiga record user.

### 8.2 Relasi akun Vendor

- Setiap user berrole `VENDOR` wajib mempunyai tepat satu `vendor_id` aktif.
- Satu Vendor boleh memiliki beberapa akun PIC.
- User `ADMIN` dan `WAREHOUSE` wajib memiliki `vendor_id = null`.
- Pemindahan akun Vendor, perubahan role, reset kredensial, aktivasi, dan
  penonaktifan dicatat dalam audit.
- User yang telah dipakai transaksi tidak dihapus; akun dinonaktifkan.

Perubahan pada tabel `users` bersifat aditif: `role`, `vendor_id`, `is_active`,
opsional `employee_no`, `position`, dan timestamp login terakhir. Role lama
`admin/user` dimigrasikan secara eksplisit ke `ADMIN/WAREHOUSE`; migrasi tidak
boleh menebak user sebagai Vendor.

## 9. Shift dan waktu operasional

- Zona waktu bisnis adalah `Asia/Makassar`.
- Default awal:
  - Shift siang: 06.00–18.00.
  - Shift malam: 18.00–06.00 hari berikutnya.
- Admin dapat mengubah jadwal untuk periode mendatang.
- Definisi jadwal menggunakan rentang berlaku. Perubahan jadwal tidak mengubah
  shift transaksi historis.
- `operational_shifts` menyimpan instance aktual `starts_at` dan `ends_at`.
  Setiap transaksi posted dihubungkan ke instance shift berdasarkan
  `posted_at`, bukan tanggal kejadian fisik yang diinput pengguna.
- Rekap operasional dibuat per shift 12 jam. BA Vendor tetap menggunakan periode
  bulanan dan menggabungkan semua shift dalam periode tersebut.
- Cut-off batch Reservasi/PR dapat dilakukan pada akhir shift.
- Transaksi terlambat menyimpan `physical_occurred_at` sebagai referensi dan
  diberi penanda `late_posting` beserta alasan. Dampak stok, rekap shift, serta
  BA mengikuti `posted_at`. Transaksi tersebut tidak dimasukkan kembali ke
  shift atau BA lama secara diam-diam.

## 10. Prinsip database

### 10.1 Identitas dan tipe data

- Seluruh PK dan FK internal menggunakan UUID.
- Nomor tampilan seperti VRV, MR, IN, OUT, PR batch, dan BA adalah business key
  unik yang dibuat sistem; nomor tersebut bukan PK.
- Quantity menggunakan `DECIMAL(18,4)` dan wajib lebih besar dari nol pada baris
  transaksi.
- Waktu disimpan sebagai timestamp dan ditampilkan dalam WITA.
- Nomor dokumen eksternal disimpan sebagai string; leading zero tidak dibuang.
- Master yang sudah dirujuk histori dinonaktifkan, bukan dihapus.

### 10.2 Tabel inti baru

Nama final boleh diselaraskan dengan konvensi proyek, tetapi tanggung jawab dan
constraint berikut wajib dipertahankan.

#### Identity dan jadwal

- `users`: tambahan role, vendor, status aktif, identitas opsional.
- `shift_definitions`: kode, nama, jam mulai/selesai, tanggal berlaku, status.
- `operational_shifts`: definisi, waktu mulai/selesai aktual, status open/closed,
  penutup, waktu penutupan.

#### Periode dan saldo awal

- `inventory_periods`: cutoff, status `DRAFT/ACTIVE/CLOSED`, aktivator, waktu.
- `opening_balance_batches`: period, nomor batch, sumber FORM/XLSX, status,
  pembuat, approver/aktivator, checksum file.
- `opening_balance_lines`: batch, item, `vendor_item_id` nullable, lokasi,
  quantity, nomor baris sumber, pesan validasi.

Constraint unik mencegah dua baris saldo awal untuk bucket yang sama dalam satu
batch. Bucket CONSIGNMENT adalah item–Vendor–lokasi; bucket STOCK adalah
item–lokasi dan tidak mempunyai Vendor.

#### Ledger dan proyeksi stok

- `stock_transactions`: nomor, tipe, status, `physical_occurred_at`, `posted_at`,
  shift posting, pembuat, poster, idempotency key unik, alasan, reversal
  reference, late-posting flag.
- `stock_ledger_entries`: transaksi, item, vendor item nullable, Vendor snapshot
  nullable, lokasi, signed quantity, entry sequence, waktu kejadian.
- `stock_balances`: item, vendor item nullable, lokasi, on-hand quantity,
  version; satu baris unik per bucket.
- `stock_lots`: sumber ledger IN, bucket, received/opened timestamp, original
  quantity, remaining quantity.
- `stock_lot_allocations`: ledger OUT, lot, allocated quantity.
- `stock_adjustments`: alasan, bukti/catatan, status, pembuat, approver, stock
  transaction ketika posted.
- `audit_logs`: actor, action, entity type/id, before JSON, after JSON, alasan,
  request metadata, timestamp.

`stock_balances` dan remaining lot adalah proyeksi operasional yang diperbarui
atomik bersama ledger. Saldo dapat direkonstruksi dari ledger dan allocation.

#### Refill dan penerimaan

- `refill_requests`: nomor VRV, Vendor, status, planned delivery date, pembuat,
  submitter, catatan.
- `refill_request_lines`: request, vendor item, target snapshot, stock snapshot,
  outstanding snapshot, suggested qty, requested/approved/received/cancelled qty.
- `refill_revisions`: line, qty sebelumnya/usulan, alasan Vendor, status,
  reviewer, waktu review.
- `refill_shipments`: request, surat jalan/resi, ekspedisi, tanggal kirim,
  catatan, status.
- `refill_shipment_lines`: shipment, refill line, shipped quantity.
- `refill_receipts`: nomor penerimaan, shipment/request, tanggal diterima,
  nomor DO Vendor, penerima, catatan, stock transaction unik.
- `refill_receipt_lines`: receipt, refill line, lokasi aktual, received quantity.
- `manual_receipts`: nomor Data IN, Vendor nullable sesuai ownership, DO,
  penerima, alasan urgent, catatan, stock transaction unik.
- `manual_receipt_lines`: receipt, item/vendor item, lokasi, quantity.

#### MR dan Data OUT

- `material_requests`: nomor MR internal, waktu request, requester name,
  department text/reference, nomor referensi eksternal, catatan, status.
- `material_request_lines`: MR, item, requested/issued/cancelled quantity,
  catatan.
- `material_issues`: nomor Data OUT, MR, waktu penyerahan, pengambil, petugas,
  catatan, stock transaction unik.
- `material_issue_lines`: issue, MR line, item, vendor item nullable, lokasi,
  issued quantity.

Satu MR dapat memuat STOCK dan CONSIGNMENT. Satu MR dapat dipenuhi parsial
melalui lebih dari satu Data OUT tanpa memposting baris yang sama dua kali.

#### Procurement dan dokumen

- `procurement_requirements`: satu kebutuhan immutable untuk setiap
  `material_issue_line` CONSIGNMENT, dengan unique row key, issued quantity, dan
  status kelengkapan.
- `procurement_document_lines`: requirement, tipe referensi
  RESERVATION/WO/PR/PO/GRGI, nomor, quantity yang dicakup, versi, dan status.
  Satu issued line boleh dipecah ke beberapa baris dokumen SAP, tetapi jumlah
  quantity aktif per tahap tidak boleh melebihi issued quantity.
- `procurement_import_batches`: tipe import, file metadata/checksum, pembuat,
  waktu, hasil, status.
- `procurement_import_rows`: batch, row key UUID, nilai sumber, hasil validasi,
  error, applied timestamp.
- `procurement_documents`: procurement line/batch, jenis dokumen, nama asli,
  disk/path, MIME, ukuran, checksum, pengunggah, versi, status.
- `procurement_validations`: line, keputusan valid/revisi/reject, catatan,
  validator, waktu.

File tidak disimpan langsung sebagai BLOB di tabel. Path acak, MIME/ukuran yang
diizinkan, checksum, otorisasi download, dan nama asli disimpan terpisah.

#### Monitoring dan BA

- `shift_recap_snapshots`: operational shift, versi, generated/closed by,
  waktu, ringkasan JSON atau referensi export, checksum.
- `vendor_reconciliations`: nomor BA, Vendor, period start/end, status, versi,
  pembuat, reviewer Vendor, finalizer, waktu masing-masing.
- `vendor_reconciliation_lines`: BA, item/vendor item/lokasi, opening, IN, OUT,
  adjustment, system closing, physical qty nullable, variance.
- `vendor_reconciliation_responses`: BA/line nullable, keputusan Vendor,
  catatan selisih, actor, timestamp.
- `vendor_reconciliation_documents`: BA, versi, tipe Excel/PDF/lampiran, path,
  checksum, pembuat.

Satu Vendor hanya boleh mempunyai satu BA aktif untuk periode yang sama. Revisi
setelah finalisasi membuat versi baru; versi final lama tidak ditimpa.

## 11. Sumber kebenaran dan aturan posting

### 11.1 Satu pintu perubahan stok

Stok hanya berubah melalui `StockPostingService` atau nama service setara.
Sumber transaksi yang diizinkan:

- aktivasi saldo awal;
- Incoming Refill;
- Data IN manual;
- Data OUT;
- adjustment yang disetujui;
- reversal dari transaksi posted.

Refill request, pengiriman Vendor, MR draft, PR, validasi, PO, GRGI, upload, dan
BA tidak mengubah stok.

### 11.2 Transaksi atomik dan concurrency

Setiap posting dilakukan dalam satu transaksi database:

1. validasi status dan otorisasi sumber;
2. kunci row sumber dan `stock_balances` terkait (`SELECT ... FOR UPDATE`);
3. pastikan idempotency key dan source-to-stock-transaction belum pernah dipakai;
4. validasi bucket ownership serta saldo tersedia;
5. buat stock transaction dan ledger entries;
6. buat/kurangi lot serta allocations;
7. perbarui proyeksi saldo;
8. tautkan transaksi stok ke dokumen sumber;
9. tulis audit dan commit.

Unique constraint menjadi perlindungan terakhir terhadap request ganda.

Tanggal kejadian fisik yang lebih lama tidak menyebabkan replay saldo historis.
Validasi ketersediaan, larangan saldo negatif, FIFO, dan pemilihan lot selalu
menggunakan keadaan pada saat posting. Karena itu OUT yang diposting hari Rabu
tidak dapat diperlakukan seolah-olah telah memakai stok hari Senin; tanggal
Senin hanya tersimpan sebagai referensi fisik dan transaksi tetap masuk shift
posting hari Rabu.

### 11.3 Immutability, reversal, dan adjustment

- Draft boleh diubah oleh role berwenang.
- Transaksi posted tidak dapat diedit atau dihapus.
- Kesalahan transaksi posted diperbaiki dengan reversal penuh yang membuat
  ledger berlawanan dan menautkan transaksi asal.
- Perubahan kuantitas karena selisih fisik menggunakan adjustment dengan alasan
  dan approval Admin, bukan mengubah ledger lama.
- Reversal tidak boleh membuat saldo atau lot menjadi tidak konsisten. Jika stok
  IN sudah dipakai oleh OUT, sistem menolak reversal langsung dan meminta
  rangkaian koreksi yang valid.
- Reversal penerimaan mengembalikan outstanding refill hanya jika request masih
  dapat dibuka. Request yang sudah ditutup memerlukan tindakan Admin untuk
  membuka kembali dengan alasan dan audit.
- Reversal Data OUT mengurangi issued quantity MR sehingga sisa MR kembali
  tersedia. Referensi Reservasi/PR/PO/GRGI yang sudah ada tidak dihapus; seluruh
  referensi ditandai `REQUIRES_REVIEW` atau `VOID` sesuai keputusan Admin.
- Reversal Data OUT yang sudah mempunyai PO/GRGI hanya dapat dilakukan Admin
  dengan alasan. Sistem tidak mencoba membatalkan dokumen SAP secara otomatis.

## 12. Saldo awal dan cut-off

1. Admin membuat periode dan memilih tanggal/waktu cut-off.
2. Admin memasukkan saldo melalui form atau XLSX preview.
3. Sistem memvalidasi ownership, Vendor, lokasi, duplikasi bucket, quantity, dan
   master aktif.
4. Preview tidak mengubah stok.
5. Sebelum aktivasi, batch dapat diperbaiki atau diganti.
6. Saat aktivasi, sistem memposting opening ledger dan opening lots secara
   atomik, lalu mengunci batch dan periode.
7. Setelah aktif, saldo awal tidak dapat ditimpa. Koreksi memakai adjustment
   bertanggal saat koreksi dengan alasan yang merujuk cut-off.
8. Warehouse dapat melihat saldo awal, tetapi aktivasi dan koreksi hanya Admin.

Aplikasi tidak menerima transaksi operasional sebelum satu periode aktif.

## 13. Vendor Refill dan Incoming Refill

### 13.1 Saran refill

Perhitungan dilakukan per `vendor_item`:

`suggested_qty = max(0, target_refill - current_vendor_stock - outstanding_refill_qty)`

- Target berasal dari `items.minimum_stock` dan berlaku untuk setiap Vendor
  aktif yang memasok barang itu.
- Target dapat diubah Admin pada Master Barang.
- Target 0 tidak masuk generate otomatis.
- Warehouse tetap dapat membuat request manual untuk target 0.
- Suggested quantity dapat diedit Warehouse sebelum request disubmit.
- Snapshot target, stok, outstanding, dan suggested disimpan agar histori dapat
  menjelaskan mengapa jumlah tersebut diajukan.

### 13.2 Status utama

`DRAFT → SUBMITTED → VENDOR_REVIEW → READY_TO_SHIP/REVISION_REQUESTED → SHIPPED → PARTIALLY_RECEIVED → RECEIVED/CLOSED`

- Vendor dapat menerima qty atau mengusulkan revisi disertai alasan.
- Admin menyetujui atau menolak revisi; Warehouse dapat melihat dan follow-up.
- Vendor mengisi surat jalan/resi, ekspedisi, dan detail shipment.
- Warehouse mencatat penerimaan aktual. Setiap receipt memposting Data IN tepat
  sekali.
- Penerimaan parsial menyisakan outstanding dan memungkinkan Follow Up.
- Barang belum diterima tidak menambah stok.
- Sisa outstanding hanya dapat ditutup Admin dengan alasan; qty ditutup masuk
  `cancelled_qty`, bukan `received_qty`.
- Receipt tidak boleh melebihi qty shipment/approved yang tersisa tanpa proses
  koreksi berotorisasi.

### 13.3 Data IN manual

Warehouse dapat mencatat kedatangan urgent tanpa VRV. Data wajib memuat alasan,
DO/surat jalan bila tersedia, penerima, item, ownership, Vendor untuk
CONSIGNMENT, lokasi, qty, dan waktu. Jalur ini menggunakan posting service yang
sama, bukan meniru logika saldo di controller lain.

## 14. MR dan Data OUT

### 14.1 Pembuatan MR

Karena requester belum memiliki akun aplikasi, Warehouse mencatat permintaan
dari proses luar sistem. Header menyimpan nama requester, departemen, referensi,
dan dokumen/catatan. MR dapat memuat STOCK dan CONSIGNMENT sekaligus; requester
tidak perlu menentukan Vendor.

### 14.2 Alokasi dan pengeluaran

- Sistem merekomendasikan stok masuk tertua yang masih tersedia.
- Untuk CONSIGNMENT, rekomendasi memilih Vendor–lokasi dan lot tertua.
- Warehouse boleh memilih Vendor/lokasi lain sebelum posting.
- Dalam bucket yang dipilih, lot dikonsumsi FIFO kecuali Admin membuat koreksi
  beralasan.
- Data OUT dapat parsial dan jumlah kumulatif tidak boleh melebihi sisa MR.
- Posting ditolak jika saldo bucket tidak cukup; stok negatif tidak diizinkan.
- Pengeluaran urgent boleh diposting sebelum dokumen SAP lengkap dan diberi
  status `DOCUMENT_OUTSTANDING`.
- Hanya Data OUT mengurangi saldo. Melengkapi PR/PO kemudian tidak mengurangi
  saldo lagi.

## 15. Procurement: Reservasi, PR, validasi, PO, GRGI, dan dokumen

Alur dokumen untuk baris CONSIGNMENT:

`MR/Data OUT → Reservasi atau WO → PR → validasi Admin → PO → GRGI/dokumen lengkap`

Ketentuan:

- Pada akhir shift, Warehouse dapat mengekspor baris outstanding dengan row key
  UUID, MR, Vendor, PN, nama, qty, dan kolom isian Reservasi/PR.
- Row key dibuat per `material_issue_line` CONSIGNMENT, sehingga pemenuhan MR
  parsial atau alokasi ke Vendor berbeda tidak tercampur.
- Satu row key dapat mempunyai beberapa baris Reservasi, PR, PO, atau GRGI bila
  SAP memecah quantity. Relasi dan quantity per tahap disimpan sebagai child
  rows, bukan beberapa nomor yang digabung dalam satu kolom teks.
- Import Reservasi/PR hanya memperbarui kolom yang dituju berdasarkan row key;
  PN atau urutan Excel bukan identity.
- Admin memvalidasi tiap baris sebagai Valid, Revisi, atau Reject dengan catatan.
- Baris Revisi dapat diekspor khusus, diperbaiki, dan diimpor ulang sebagai
  versi baru.
- Setelah PR valid, baris masuk antrean PO. PO dan GRGI dapat diimpor melalui
  template bertipe khusus.
- Upload ulang tidak menghapus histori; nilai aktif diperbarui dengan audit dan
  versi import.
- Satu file tidak boleh diterapkan dua kali tanpa terdeteksi oleh row key,
  checksum, dan applied timestamp.
- Semua dokumen memiliki akses download terotorisasi. Vendor hanya dapat
  mengakses dokumen miliknya yang memang ditandai dapat dilihat Vendor.

Dokumen yang belum lengkap terlihat sebagai outstanding, tetapi tidak
membatalkan Data OUT yang sah.

## 16. Monitoring, rekap shift, dan Stock Sistem

### 16.1 Rekap transaksi

Rekap adalah pembacaan ledger dan dokumen dengan filter:

- periode/shift;
- Data IN/Data OUT/adjustment/reversal;
- item/PN;
- ownership;
- Vendor;
- lokasi;
- nomor transaksi, DO, MR, PR, PO, atau GRGI;
- status kelengkapan dokumen.

Urutan default terbaru dahulu. Setiap baris dapat membuka detail sumber dan
jejak audit. Export memakai filter yang sama dan diproses server-side.

### 16.2 Rekap per shift 12 jam

Pada penutupan shift, sistem menghasilkan ringkasan:

- opening shift;
- total IN;
- total OUT;
- adjustment/reversal;
- closing shift;
- VRV dan incoming yang masih outstanding;
- Data OUT dengan dokumen outstanding;
- posting terlambat atau kegagalan yang perlu ditindaklanjuti.

Snapshot penutupan diberi versi dan checksum. Koreksi setelah shift ditutup
muncul pada shift postingnya dengan tanggal kejadian fisik sebagai referensi;
snapshot lama tidak ditimpa.

### 16.3 Stock Sistem

Halaman Stock Sistem membaca `stock_balances` dan menyediakan drill-down ke
ledger/lots. CONSIGNMENT ditampilkan per Vendor–item–lokasi; STOCK per
item–lokasi. Total lintas Vendor boleh menjadi ringkasan tambahan, tetapi tidak
menggantikan rincian kepemilikan.

## 17. BA Reconcile Vendor

- Periode standar bulanan, berdasarkan tanggal mulai dan akhir yang dipilih.
- Keanggotaan periode menggunakan `posted_at`. Transaksi fisik bulan Oktober
  yang baru diposting November masuk BA November dan diberi penanda terlambat;
  BA Oktober yang sudah ditutup tidak berubah diam-diam.
- Dibuat per Vendor; hanya transaksi CONSIGNMENT Vendor tersebut yang masuk.
- Rumus per bucket:

  `opening + Data IN - Data OUT ± adjustment/reversal = system closing`

- BA menampilkan item, lokasi, opening, IN, OUT, adjustment, system closing,
  physical quantity bila diisi, variance, serta referensi PR/PO relevan.
- Alur status:

  `DRAFT → VENDOR_REVIEW → AGREED` atau `DISPUTED → RESOLVED → FINAL`

- Vendor hanya melihat BA sendiri dan dapat `Setuju` atau `Ajukan Selisih`
  dengan catatan.
- Warehouse meneliti selisih dan bukti fisik/dokumen.
- Koreksi saldo dilakukan melalui adjustment Admin, bukan mengedit angka BA.
- Admin meregenerasi versi dan memfinalisasi BA.
- BA final dikunci. Revisi berikutnya membuat versi baru dengan tautan ke versi
  sebelumnya.
- Export Excel dan PDF menggunakan data versi BA yang sama.

## 18. Matriks otorisasi ringkas

| Aksi | Admin | Warehouse | Vendor |
|---|---:|---:|---:|
| Kelola Master dan user | Ya | Lihat | Tidak, kecuali data Vendor sendiri yang disediakan |
| Input/view opening | Kelola/aktifkan | Lihat | Tidak |
| Refill request | Kelola | Buat/submit/follow-up | Lihat milik sendiri/respons |
| Revisi qty Vendor | Putuskan | Lihat/follow-up | Ajukan |
| Incoming/Data IN | Koreksi/lihat | Buat/post | Lihat milik sendiri |
| MR/Data OUT | Koreksi/lihat | Buat/post | Lihat dampak stok sendiri bila diizinkan |
| PR/PO/GRGI | Kelola/validasi | Import/lihat sesuai tugas | Lihat dokumen yang dibagikan |
| Adjustment/reversal | Buat/setujui/post | Ajukan/lihat | Tidak |
| Rekap/Stock Sistem | Semua | Operasional | Hanya Vendor sendiri |
| BA | Buat/finalisasi | Periksa | Review milik sendiri |

Policy detail harus diuji untuk index, show, export, download, dan mutation.

Vendor hanya melihat pergerakan item, quantity, lokasi, waktu, nomor referensi
yang relevan, dan status dokumen untuk Vendor sendiri. Vendor tidak melihat nama
requester, departemen, isi MR campuran, alokasi Vendor lain, audit internal, atau
lampiran internal. Admin menentukan per lampiran apakah salinan tersebut dapat
dibagikan kepada Vendor; default-nya tidak dibagikan. Jika satu file memuat data
beberapa Vendor, sistem tidak pernah membagikan file sumber itu kepada Vendor
dan harus menghasilkan tampilan/export terfilter.

## 19. Status, validasi, dan kesalahan

- Status menggunakan enum PHP dan constraint/check yang kompatibel dengan DB.
- Transisi status dilakukan melalui action eksplisit; update massal status dari
  request tidak diperbolehkan.
- Pesan validasi menunjukkan baris/item bermasalah dan tidak mengandalkan warna.
- Conflict karena saldo/status berubah sejak halaman dibuka menghasilkan respons
  409 dengan instruksi memuat ulang, bukan posting sebagian.
- File import dipreview sebelum apply. Apply batch bersifat atomik per aturan
  bisnis; baris gagal tidak boleh diam-diam dianggap berhasil.
- Aksi submit/post memakai tombol processing dan server-side idempotency key.

## 20. UI dan navigasi

Implementasi mengikuti `DESIGN.md` dan komponen proyek yang tersedia. Menu yang
dibuat hanya yang memiliki route dan backend nyata:

- Transaksi: Data IN, MR/Data OUT.
- Pengadaan: Reservasi & PR, Validasi, PO/GRGI, Dokumen MR.
- Monitoring: Rekap Shift/Transaksi, BA Reconcile, Stock Sistem.
- Vendor & Refill: Vendor Refill, Incoming Refill.
- Master & Tools: Master Barang/Kategori/Vendor/Satuan/Lokasi/Import, User
  Management.

Menu Rotable, Tyre, Print QR, dan Stock Opname penuh tidak ditampilkan sebagai
fitur aktif. Sidebar disaring berdasarkan role, tetapi backend tetap memeriksa
policy. Tabel responsif, form memiliki label/error, status tidak dibedakan hanya
dengan warna, dan aksi posted menampilkan peringatan dampak.

## 21. Urutan pembangunan

Urutan menjaga agar setiap halaman transaksi berdiri di atas fondasi yang sudah
teruji:

1. Role tiga jenis, relasi user–Vendor, User Management, policy/scope.
2. Jadwal shift, periode, form/import saldo awal, aktivasi cut-off.
3. Ledger, balance projection, lot, query Stock Sistem.
4. Posting service, idempotensi, audit, adjustment, reversal.
5. Vendor Refill, revisi, shipment, Incoming Refill, Data IN manual.
6. MR, alokasi FIFO, Data OUT parsial, larangan saldo negatif.
7. Procurement Excel/document workflow dan status outstanding.
8. Rekap shift, export, BA Vendor, dispute, versioning, Excel/PDF.

Setiap tahap memakai migration aditif, test-first untuk perilaku yang dapat
diuji, dan tidak menjalankan operasi database destruktif. Modul existing dan
perubahan pengguna yang tidak terkait harus dipertahankan.

## 22. Strategi pengujian

### 22.1 Feature dan unit test

- matriks akses tiga role, termasuk kebocoran lintas Vendor pada index/detail/
  export/download;
- validasi user Vendor wajib mempunyai vendor_id;
- aktivasi saldo awal sekali saja dan lock setelah aktif;
- formula refill termasuk target 0 dan outstanding;
- receipt parsial dan close outstanding;
- posting request ulang menghasilkan satu ledger transaction;
- dua posting concurrent tidak membuat saldo negatif;
- ownership/bucket tidak dapat tercampur;
- FIFO recommendation serta override Vendor/lokasi;
- Data OUT parsial dan kumulatif;
- reversal/adjustment serta larangan edit posted;
- PR/PO tidak mengubah stok;
- import Excel row-key/idempotency/revision;
- shift lintas tengah malam dan perubahan jadwal future-only;
- perhitungan BA serta isolasi Vendor/version lock.

### 22.2 Database test

- UUID untuk seluruh tabel baru;
- FK, unique constraints, decimal precision, dan delete restrictions;
- MySQL test untuk row lock, check/trigger yang digunakan, dan migration
  forward/rollback yang aman pada database tes terpisah.

### 22.3 Browser test

Alur minimum per role:

- Admin mengelola user, mengaktifkan opening, memvalidasi dokumen, dan
  memfinalisasi BA.
- Warehouse membuat refill, menerima parsial, follow-up, Data IN urgent,
  membuat MR, dan memposting Data OUT.
- Vendor mengajukan revisi shipment serta menyetujui/mengajukan selisih BA.
- Responsif pada 360, 768, 1280, dan 1920 px untuk halaman kritis.

### 22.4 Verifikasi setiap tahap

Jalankan minimal test target, seluruh suite PHP, formatter, build frontend, dan
browser suite yang relevan. Klaim selesai harus menyebut hasil aktual. Migrasi
database utama baru dijalankan setelah migration diuji; tidak ada deploy
production tanpa instruksi eksplisit.

## 23. Kriteria penerimaan akhir

1. Tiga role berfungsi dan Vendor tidak dapat membaca data Vendor lain.
2. Saldo awal dapat dipreview, diaktifkan sekali, dan dikoreksi hanya melalui
   adjustment.
3. Stock Sistem dapat dijelaskan dari ledger dan lot.
4. Duplicate submit tidak menggandakan stok dan concurrent OUT tidak membuat
   saldo negatif.
5. Incoming parsial menambah hanya qty diterima dan menyimpan outstanding.
6. Data OUT mengurangi bucket pemilik yang benar serta mendukung MR campuran.
7. PR/PO/GRGI dan upload dapat direvisi dengan audit tanpa memengaruhi saldo.
8. Rekap shift 12 jam, rekap transaksi, dan BA bulanan menggunakan sumber data
   yang sama serta dapat diekspor.
9. Transaksi posted dan BA final tidak dapat ditimpa atau dihapus.
10. Semua tabel baru memakai UUID dan seluruh migrasi/test lulus pada lingkungan
    yang ditentukan.

## 24. Keputusan implementasi yang diserahkan kepada builder

Builder boleh menentukan nama class, pembagian controller/action/query, struktur
komponen Vue, serta strategi caching query selama tidak mengubah aturan bisnis,
constraint, otorisasi, dan sumber kebenaran di atas. Pilihan library PDF/XLSX
harus mengutamakan dependensi yang sudah tersedia; dependency baru hanya dipakai
jika benar-benar diperlukan dan dicatat dalam rencana.

## 25. Hasil pemeriksaan builder

Pemeriksaan independen menyoroti lima area yang telah ditutup dalam desain:

1. Pengguna menetapkan transaksi terlambat mengikuti periode `posted_at`, bukan
   mengubah rekap/BA lama.
2. Berdasarkan keputusan tersebut, pemeriksaan saldo dan FIFO menggunakan
   keadaan saat posting; tidak ada replay histori berdasarkan tanggal fisik.
3. Granularitas procurement ditetapkan per issued consignment line dan mendukung
   split quantity ke beberapa baris dokumen SAP.
4. Reversal memulihkan kuantitas operasional tetapi mempertahankan serta
   menandai dokumen procurement untuk review/void; histori tidak dihapus.
5. Visibilitas Vendor memakai prinsip minimum disclosure dan selalu terfilter
   per Vendor; lampiran internal tidak dibagikan secara default.

