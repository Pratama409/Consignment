# Analisis video web, transkrip meeting, dan proyek Consignment

Tanggal analisis: 29 Oktober 2026, WITA.

Kesimpulan: struktur inti Master Data yang sudah dibuat sesuai keputusan terbaru
pengguna. Proyek saat ini menyelesaikan tahap Master Data; cakupan sistem dalam
meeting jauh lebih luas. Tambahan yang perlu ditinjau sebelum menutup rancangan
Master Data adalah kategori barang dan penelusuran Vendor/Lokasi. Sebelum
transaksi dibangun, aturan refill, pencatatan stok per Vendor, saldo awal,
otorisasi operasional, dan hubungan dokumen SAP perlu ditetapkan.

## 1. Sumber, metode, dan batas kesimpulan

Sumber yang dibandingkan:

1. [Video web buatan pengguna](<D:/Bootcamp/Dicoding-Belajar Dasar Pemrogranman Web/Rec/2026-10-27 20-24-29.mp4>).
   Durasi terukur 07:42,592; resolusi 1920 × 1078. Video dibuat terpisah dari
   meeting. Timestamp video tidak dipasangkan dengan timestamp transkrip.
2. [Transkrip meeting](<C:/Users/ACER/Downloads/Telegram Desktop/Transkrip_Meeting_Gabungan_Timestamp_Diperbaiki.txt>).
   Rentang audio gabungan menurut dokumen 02:48:47. Timestamp dialog merupakan
   penyelarasan otomatis teks yang sudah diringkas; gunakan rentang pembahasan
   sebagai acuan, bukan presisi kata per detik.
3. Rancangan awal yang ditempel pengguna, keputusan lanjutan dalam percakapan,
   [DESIGN.md](../DESIGN.md), dan dokumentasi proyek.
4. Kode aplikasi: routes, model, policy, action penyimpanan, query daftar,
   importer/exporter, dan skema UUID. Metadata database aplikasi dibaca untuk
   memastikan 17 tabel memiliki PK CHAR(36).

Video ditelaah secara visual melalui 93 frame berjarak lima detik, pembesaran
bagian penting, serta 13 frame tambahan pada bagian Master Part/Tools. Pengambilan
frame menunggu frame video selesai dipresentasikan, bukan hanya posisi seek.
Manifest mencatat waktu yang diminta dan mediaTime frame. Analisis audio meeting
menggunakan transkrip yang diberikan; audio video tidak ditranskripsikan ulang.

Keberadaan tombol pada rekaman membuktikan tampilan tombol itu. Rekaman tidak
membuktikan validasi backend, hasil simpan, keamanan akses, atau semua cabang
proses bekerja. Tab Stock Opname dan tombol Master Dept/Unit terlihat, tetapi
rekaman tidak menjelaskan seluruh isi form maupun perilakunya.

Urutan otoritas untuk penilaian: keputusan terbaru pengguna → kesepakatan
meeting yang jelas → rancangan tahap pertama → perilaku yang tampak di video.
Usulan pembicara, percakapan mentoring, dan instruksi di dokumen diperlakukan
sebagai bahan analisis. Contohnya, saran gaya login dalam mentoring tidak
menggantikan DESIGN.md atau menjadi perintah implementasi baru.

## 2. Peta tampilan video dan kondisi proyek

Waktu berikut adalah kisaran navigasi yang terlihat, bukan batas scene presisi.

| Video | Tampilan yang terlihat | Kondisi proyek sekarang |
|---|---|---|
| 00:00–00:38 | Dashboard, nilai stok, low stock, PR/PO pending, ITO, umur komponen | Belum dibuat; sesuai batas tahap pertama di DESIGN.md |
| 00:38–01:08 | Data IN, Vendor, DO/surat jalan, penerima, daftar material | Belum ada transaksi penerimaan |
| 01:08–01:34 | Data OUT, shift, departemen/unit, WO/order, PIC, pengambil | Belum ada transaksi pengeluaran |
| 01:34–02:04 | Input PR dan validasi dokumen PR | Belum dibuat |
| 02:04–02:40 | Input PO, preview PR pending, tombol/notice ME5A | Belum dibuat; importer Master PN tidak menangani dokumen ini |
| 02:40–02:55 | Upload dokumen MR dan riwayat upload | Belum dibuat; unggah lampiran berbeda dari request MR digital |
| 02:55–03:38 | Rekap IN/OUT, PR/PO, pencarian dan export | Belum ada histori transaksi |
| 03:38–04:10 | BA Rekonsiliasi, periode, Vendor, approval, tabel hasil | Belum dibuat |
| 04:10–04:45 | Stock Sistem dan tab Form Stock Opname | Belum ada saldo/SO/penyesuaian stok |
| 04:45–06:00 | Tyre, histori rotable per unit, damage core | Ditunda; belum ada instance serial atau siklus repair |
| 06:00–06:45 | Vendor Refill, STOCK/MIN/SUGGEST, qty kirim, histori | Baru target refill pada Master Barang yang tersedia |
| 06:45–07:20 | Incoming Refill, partial/full, outstanding, Follow Up/Close | Belum dibuat |
| Sekitar 07:20–07:28 dan akhir video | Master Part, Vendor/Kategori/Lokasi, import Excel/CSV, export CSV | Barang, Vendor/PIC, Satuan, Lokasi, import/export XLSX tersedia; kategori dan filter relasi belum tersedia |
| Sekitar 07:28–07:34 | Print barcode dan preview label | Ditunda sesuai keputusan pengguna |
| Sekitar 07:34–07:38 | User Management dengan role dan Vendor | Login admin/user tersedia; manajemen akun dan role operasional belum dibuat |

Tidak hadirnya transaksi dalam proyek sekarang merupakan pekerjaan tahap lanjut,
bukan bukti bahwa struktur Master Data salah. Dashboard tetap dapat menjadi
bagian produk akhir setelah sumber perhitungannya tersedia. Menghapus placeholder
Dashboard pada tahap Master Data tidak menghapus kebutuhan dashboard jangka panjang.

## 3. Keputusan yang sudah konsisten

| Pokok | Dasar sumber | Implementasi/penilaian |
|---|---|---|
| Vendor merupakan master tersendiri | Meeting sekitar 02:43 dan instruksi terbaru | vendors sudah terpisah; PIC sederhana disimpan pada pic_name |
| Lokasi merupakan master tersendiri | Rancangan dan instruksi terbaru | locations sudah terpisah; UNASSIGNED menjadi lokasi sementara sistem |
| Satu barang dapat mempunyai beberapa Vendor | Meeting 00:15–00:19; dikonfirmasi pengguna | vendor_items menghubungkan item_id, vendor_id, location_id; pasangan barang–Vendor unik |
| STOCK milik internal; CONSIGNMENT milik Vendor | Meeting 01:39–01:46 dan klarifikasi pengguna | Pemisahan ownership benar; STOCK tidak mempunyai vendor_items |
| PN dapat sama | Meeting 01:46 dan 02:43 | PN tidak unik; UUID dan item_code menjaga identitas barang |
| Minimum merupakan target refill dan dapat diubah | Klarifikasi pengguna setelah pembahasan min–max | minimum_stock per barang, nonnegatif, empat desimal; sama untuk seluruh Vendor barang tersebut |
| Ownership dikunci setelah dibuat | Keputusan lanjutan pengguna | Diperiksa model dan guard database |
| UUID berlaku pada seluruh tabel | Instruksi terbaru, termasuk tabel Laravel | Metadata aktual menunjukkan 17 tabel dan 17 PK CHAR(36); FK ID menggunakan UUID |
| QR/serial dapat ditunda | Keputusan lanjutan pengguna | SaveItem menerima QUANTITY; skema menyediakan SERIALIZED sebagai opsi yang belum diaktifkan pada alur simpan |
| Hanya admin mengubah Master Data | Jawaban pengguna sebelumnya | Policy membatasi perubahan pada admin; user membaca |

Keterangan penting: contoh warna trashbag pada sekitar 01:46 dan 02:43 disebut
berbeda. Ini contoh penjelasan, sehingga kepemilikan tidak boleh ditentukan
otomatis dari kata “hitam”, “bening”, atau PN. Gunakan atribut barang yang
diverifikasi. Pernyataan minimum “by PN” juga perlu dibaca sebagai barang yang
sama: karena PN dapat duplikat, kebijakan disimpan berdasarkan identitas item.

## 4. Hal yang perlu diperbaiki atau dipastikan pada Master Data

### A. Kategori barang berbeda dari kepemilikan

Video sekitar 07:20 dan 07:40 memperlihatkan kategori FILTER, BATTERY, GET;
sekitar 07:25 juga terlihat nilai kategori panjang yang menyerupai deskripsi
barang. Variasi ini perlu diperiksa dan dibersihkan, bukan langsung dianggap
daftar kategori yang sudah baku.

Proyek baru memiliki ownership_type. Belum ada kategori produk terpisah pada
model, form, atau template import. STOCK/CONSIGNMENT menjawab siapa pemiliknya;
FILTER/BATTERY/GET menjawab kelompok barang; QUANTITY/SERIALIZED menjawab cara
pelacakan. Ketiganya tidak boleh menggunakan satu kolom yang sama.

Usulan untuk diputuskan: jika kategori produk dibutuhkan pada versi ini,
tambahkan master categories dengan PK UUID, code unik, name, is_active, dan FK
items.category_id. Untuk data lama yang belum terpetakan, sediakan kondisi belum
dikategorikan yang jelas. Nilai kategori dan pemetaannya harus diverifikasi
dahulu. Bukti kategori di video belum cukup untuk menetapkan klasifikasi rotable
atau cara pelacakan setiap barang secara otomatis.

### B. Penelusuran Vendor dan lokasi pada daftar Barang belum setara referensi

Video Master Part memiliki pencarian Vendor/material/deskripsi/lokasi serta
filter Vendor/Kategori/Lokasi. Query sekarang mencari item_code, part_number,
item_name dan memfilter ownership/status. Daftar hanya menampilkan jumlah Vendor;
nama Vendor dan lokasi tersedia di detail barang.

Usulan: tambah filter Vendor dan lokasi, pencarian relasi yang sesuai, dan
ringkasan Vendor/lokasi atau akses detail yang mudah. Gunakan whereHas/exists
atau query setara agar barang dengan beberapa Vendor tidak muncul ganda dan
pagination/ringkasan tetap benar. Label filter harus jelas apakah memakai relasi
aktif saja atau termasuk riwayat.

Bukti kode: [ItemListQuery](../app/Queries/ItemListQuery.php),
[ItemIndexRequest](../app/Http/Requests/MasterData/ItemIndexRequest.php),
[ItemController](../app/Http/Controllers/MasterData/ItemController.php), dan
[halaman Barang](../resources/js/Pages/MasterData/Items/Index.vue).

### C. Master Dept/Unit dan identitas orang diperlukan pada tahap operasional

Meeting 01:00–01:02, 01:13–01:34, dan 02:14 menyebut NRP, nama, jabatan,
departemen, WO/unit, serta approval. Video memperlihatkan tombol Master Dept/Unit.
Saat ini users memuat identitas login umum dan role admin/user; profil NRP,
departemen, jabatan dan rantai persetujuan belum tersedia.

Usulan tahap request/SO: tentukan sumber identitas karyawan dan master departemen.
SS6 yang disebut dalam meeting masih memerlukan akses dan bentuk integrasi yang
jelas. Jangan menganggap integrasi tersebut sudah tersedia. “Unit” alat seperti
DT/HD juga perlu dibedakan dari units saat ini yang merupakan satuan PC/KG/SET.
PIC Vendor tetap cukup satu setting nama selama keputusan itu belum berubah.

### D. Format import perlu dibedakan

Video menampilkan Excel/CSV, sementara aplikasi sekarang menerima XLSX. Ini
perbedaan cakupan yang harus dinyatakan, bukan janji dukungan CSV yang belum ada.
[WorkbookReader](../app/Services/MasterData/WorkbookReader.php) memiliki daftar
kolom yang didukung; CATEGORY dan harga belum termasuk. Kolom di luar daftar
tidak dipetakan. Jika format referensi akan dipakai, tampilkan peringatan kolom
yang tidak didukung atau perluas pemetaannya sebelum import.

Import Master PN, import saldo awal, import Reservasi/PR, import PO, dan import
GRGI mempunyai tujuan serta aturan pencocokan berbeda. Template Master PN tidak
dapat dianggap menangani seluruh kebutuhan Excel dalam meeting.

## 5. Aturan refill yang belum boleh disalin langsung dari video

Meeting sekitar 00:08 menggambarkan minimum sebagai sasaran pengisian. Sekitar
00:11 ada contoh minimum 5 dan maksimum 10. Pengguna kemudian menetapkan minimum
sebagai target refill. Rancangan tahap ini mengikuti klarifikasi pengguna dan
belum membutuhkan kolom maximum_stock tanpa keputusan baru.

Pada video sekitar 06:40 terlihat contoh berikut:

| Stok terlihat | MIN | SUGGEST terlihat | Penilaian menurut minimum = target |
|---|---|---|---|
| 0 | 30 | 30 | Sesuai kekurangan menuju target |
| 1 | 10 | 9 | Sesuai |
| 4 | 6 | 2 | Sesuai |
| 10 | 10 | 1 | Perlu dikoreksi/dipastikan: target sudah tercapai |

Usulan rumus dasar untuk modul mendatang:

`saran_refill = max(0, target_refill - stok_vendor_tercatat_di_warehouse)`

Dengan aturan ini, stok 10 dan target 10 menghasilkan 0. Meeting sekitar
00:24–00:25 membolehkan saran awal 1 saat minimum 0, tetapi konteksnya permintaan
yang akan dibuat. Ini belum menjadi persetujuan bahwa semua item minimum 0 atau
semua item tepat pada target harus selalu otomatis dibuatkan refill 1.

Yang perlu ditetapkan: apakah target 0 berarti tidak masuk Generate Low Stock,
dan saran 1 hanya dipakai ketika admin sengaja menambah permintaan manual.
Saran berikutnya juga perlu mempertimbangkan permintaan/pengiriman yang masih
outstanding agar klik Generate berulang tidak menimbulkan pemesanan ganda.
Qty kirim tetap dapat disesuaikan oleh petugas berwenang; perubahan Vendor yang
memerlukan persetujuan mengikuti alur revisi, bukan mengubah target master.

Ini analisis perilaku tampilan. Kode rumus web lama tidak diberikan, sehingga
penyebab SUGGEST 1 tidak dapat dipastikan dari video. Proyek baru belum memiliki
perhitungan saran refill; temuan ini merupakan kebutuhan sebelum implementasi.

## 6. Dasar integritas untuk transaksi selanjutnya

### Saldo consignment harus terpisah per Vendor

Meeting 00:18–00:19 menyetujui target yang sama pada Vendor A dan B, masing-masing
memenuhi targetnya. Saldo yang dimaksud adalah barang milik Vendor yang dititipkan
di warehouse, bukan jumlah persediaan di gudang luar milik Vendor.

vendor_items sudah memberi identitas yang tepat. Pergerakan consignment kelak
perlu merujuk vendor_item_id dan lokasi aktual transaksi. Saldo per item saja
akan mencampur milik Vendor A dan B. STOCK internal tetap terpisah dari saldo
consignment dan dari laporan Vendor.

Lokasi di vendor_items adalah lokasi utama/default. Karena master dapat diedit,
transaksi historis perlu menyimpan location_id dan informasi dokumen pada saat
kejadian; perubahan lokasi utama tidak boleh memindahkan histori secara surut.
Saldo dan qty IN/OUT tidak ditambahkan sebagai atribut Master Barang.

### Saldo awal dan cut-off harus jelas

Meeting 01:08–01:13 menyebut arsip manual dan kesulitan cut-off BA sejak transaksi
lama. Import daftar PN tidak menghasilkan saldo awal atau histori stok.
Sebelum transaksi berjalan, tetapkan tanggal cut-off dan saldo awal yang disahkan
per barang–Vendor–lokasi, atau migrasikan histori yang sudah direkonsiliasi.
Tanpa itu, rumus saldo dan BA dapat salah walaupun seluruh formulir berfungsi.

### Incoming parsial dan penutupan outstanding

Meeting 00:31–00:33 dan video sekitar 06:50–07:15 menunjukkan penerimaan parsial.
Satu refill mempunyai banyak baris; setiap baris dapat diterima beberapa kali.
Penerimaan harus membuat stok masuk tepat sekali dalam transaksi yang sama.
Klik ulang tidak boleh menambah saldo dua kali. Barang belum diterima tidak
menambah stok. Close outstanding memerlukan alasan/otorisasi dan tidak boleh
menyamakan sisa yang dibatalkan dengan barang yang benar-benar diterima.

### Role operasional belum dapat diwakili hanya oleh admin/user

Meeting 00:28, 01:47 dan 02:13–02:15 menyebut Warehouse, GL, Vendor, requester,
serta validasi oleh Inventory. User Management pada video juga memuat role dan
pengaitan Vendor. Kebutuhan ini belum diimplementasikan oleh role tahap pertama.

[MasterDataPolicy](../app/Policies/MasterDataPolicy.php) mengizinkan baca untuk
seluruh pengguna login dan membatasi perubahan pada admin. Ini sesuai akses
internal yang disepakati untuk tahap sekarang. Sebelum akun Vendor dibuat,
otorisasi harus membatasi data berdasarkan Vendor pada backend, termasuk query,
detail, export, dan dokumen. Menambah label role Vendor saja tidak cukup.
Hubungan akun–Vendor serta hak requester/approver perlu dirancang dahulu.

### Histori dan audit tidak mengikuti tombol Delete secara harfiah

Rekap pada video sekitar 03:20 memperlihatkan Edit/Delete. Transaksi yang sudah
diposting sebaiknya dikoreksi melalui pembatalan/reversal atau revisi tercatat,
bukan dihapus tanpa jejak. Master tetap dinonaktifkan sesuai keputusan sekarang.
Rekam pelaku, waktu, alasan, dan perubahan sebelum/sesudah untuk approval,
revisi refill, penerimaan, pengeluaran, serta perubahan referensi dokumen.

## 7. Alur produk akhir yang terbaca dari meeting

Alur berikut merupakan peta kebutuhan, bukan seluruhnya fitur yang sudah ada.

**Pasokan Vendor:**
Refill Request → Vendor menyiapkan/mengusulkan revisi → persetujuan revisi bila
ada → pengiriman → penerimaan warehouse penuh/parsial → posting Stock IN.
Data IN manual tetap dibutuhkan untuk kasus mendesak tanpa request, sesuai
meeting 00:33. Kedua jalur penerimaan memakai aturan posting stok yang sama.

**Permintaan internal:**
User memilih barang dan qty → nomor request internal → approval departemen →
Warehouse menyiapkan → Ready to Pick Up → serah terima/pengeluaran. Perubahan
setelah ready tetap terkait request yang sama, dengan revision event, item yang
berubah ditandai dan pemberitahuan ke warehouse; pembahasan 01:35–01:38 belum
merinci seluruh aturan status, pembatalan, maupun pengeluaran parsial.

User dapat meminta STOCK dan CONSIGNMENT dalam satu request. Warehouse mengolah
jenis kepemilikannya dari master. Transkrip 01:43–01:45 menyatakan rekap
consignment hanya memuat consignment; STOCK mengikuti proses SAP internal.
Jangan mewajibkan user memilih Vendor atau memahami klasifikasi kepemilikan.

**Dokumentasi SAP sesudah/pada pemakaian consignment:**
Request/pengeluaran → pengaitan reservasi atau WO → Reservasi & PR melalui
template/manual → validasi Inventory dan revisi → PO → referensi GRGI → BA.
Meeting 01:50–01:51 menjelaskan PR/PO consignment sebagai kelengkapan dokumen
atas barang yang sudah dititipkan/dipakai. Mengisi PR/PO tidak otomatis menjadi
kejadian penerimaan stok fisik baru.

ID request internal, nomor reservasi SAP, dan nomor WO merupakan identitas
berbeda. Request departemen tanpa akses SAP tetap dapat dibuat sebelum tim
Warehouse melengkapi referensi. Saat tepatnya reservasi/WO wajib tersedia perlu
ditentukan per alur, tidak disamaratakan untuk semua requester.

**Kontrol stok:**
Saldo dan histori pergerakan → Stock Opname → pencatatan aktual/selisih →
persetujuan/penyelesaian selisih → penyesuaian terposting dan pembukaan lock.
Meeting sekitar 00:36 mengusulkan blind count, sedangkan sekitar 00:47 menyebut
Warehouse melihat qty sistem. Hak lihat pada form hitung perlu diputuskan.
Ruang lingkup dan durasi freeze juga perlu jelas; jangan menutup selisih dengan
pengeluaran tanpa audit setelah SO.

**Reporting:**
Rekap transaksi dan BA membutuhkan saldo awal, periode, Vendor, kejadian stok,
serta referensi dokumen yang valid. Dashboard kemudian menghitung indikator
dari data tersebut. Nilai rupiah/ITO/umur komponen memerlukan sumber harga,
metode perhitungan, dan histori yang belum tersedia pada Master Data sekarang.

**QR, rotable, tyre:**
Meeting 02:21 menyebut rotable/tyre menyusul, walaupun menunya sudah terlihat
pada video yang dibuat terpisah. Keputusan pengguna menunda QR/serial tetap
berlaku. Barcode pada bin untuk barang kuantitas berbeda dari identitas unik
setiap unit barang serialized. PK UUID item bukan otomatis nomor serial fisik.
Siklus C3 → kirim Vendor → repair C2 memerlukan identitas unit dan histori repair
tersendiri ketika tahap itu dimulai.

## 8. Dokumen SAP dan upload: perbedaan yang perlu disepakati

Video sekitar 02:05 menampilkan Upload ME5A pada proses PO. Meeting menyebut
ZMMR01 sekitar 01:28 dan ME2N sekitar 02:03, selain template reservasi/PR/PO
dari web. Keberadaan nama file/layar tersebut tidak berarti formatnya sama.
Perlu contoh export aktual dan kolom kuncinya sebelum parser dibuat. Tidak ada
bukti integrasi SAP langsung atau sinkronisasi real-time pada proyek sekarang.

Meeting 02:06 meminta export detail lengkap, sedangkan upload hanya mengubah
kolom reservasi/PR yang dituju. Meeting 02:09 membolehkan koreksi nomor PO lewat
upload ulang. Usulan teknisnya:

- Sertakan ID baris internal UUID dalam template web. PN, nomor WO, atau nomor
  PR saja belum cukup menjadi kunci tunggal seluruh baris transaksi.
- Pertahankan atribut barang, Vendor, dan qty saat update referensi dokumen.
- Jangan mengasumsikan hubungan request–reservasi–PR–PO selalu satu banding satu.
- Pisahkan tanggal terbit dokumen dari waktu file diunggah; pertahankan histori
  perubahan dan hasil validasi/revisi.
- Upload ulang harus memperbarui baris yang sesuai tanpa menggandakan dokumen
  atau mengulangi pergerakan stok.

## 9. Urutan lanjutan yang disarankan

| Tahap | Hasil yang perlu ada sebelum dilanjutkan |
|---|---|
| 1. Penutupan Master Data | Putuskan kategori produk, kebersihan nilai kategori, filter/search Vendor–Lokasi, dan format import yang benar-benar didukung |
| 2. Dasar transaksi | Tentukan saldo awal/cut-off, buku pergerakan consignment per Vendor/lokasi, aturan posting dan koreksi, serta akses operasional |
| 3. Pasokan | Vendor Refill, revisi/pengiriman Vendor, Incoming parsial, Data IN manual, outstanding dan follow-up |
| 4. Permintaan/pemakaian | MR digital, departemen/petugas/approval, persiapan, pickup, revisi, Data OUT, referensi STOCK ke SAP |
| 5. Kontrol dan dokumen | Stock Opname/lock/adjustment, Reservasi–PR–validasi–PO–GRGI, rekap dan BA dengan periode yang jelas |
| 6. Dashboard dan modul lanjut | KPI dengan definisi/sumber data nyata; QR, serialized, tyre, rotable/core sesuai prioritas baru |

Tahap pasokan dan permintaan dapat dikembangkan bertahap setelah dasar posting
stok sama-sama tersedia. Urutan ini merupakan usulan dependensi pengerjaan,
bukan klaim bahwa semua tambahan tersebut sudah disetujui untuk dibuat sekarang.

Keputusan yang paling perlu dibahas selanjutnya:

1. Apakah kategori produk masuk penyelesaian Master Data sekarang, dan apa
   daftar kategori baku serta sumber pemetaannya?
2. Untuk target 0, apakah Generate Low Stock mengabaikannya dan nilai awal 1
   hanya muncul pada permintaan manual? Bagaimana memperhitungkan outstanding?
3. Dari mana saldo awal yang disahkan dan tanggal cut-off transaksi/BA diambil?
4. Bagaimana hak Warehouse/GL/Vendor/requester, rantai approval, dan sumber NRP?
5. Apakah hitung SO bersifat blind, dan kapan lock transaksi mulai/berakhir?
6. File SAP apa yang benar-benar digunakan, serta kapan referensi SAP wajib ada?

## 10. Bukti visual dan kode

Frame/manifest berada di storage privat proyek. Beberapa bukti utama:

- [Refill, sekitar 06:40](../storage/app/private/reviews/2026-10-29-video-web/frames/frame-0400.25.jpg): stok 10/MIN 10/SUGGEST 1 dan qty yang dapat diubah.
- [Incoming, sekitar 07:00](../storage/app/private/reviews/2026-10-29-video-web/frames/frame-0420.25.jpg): partial, outstanding, Follow Up/Close.
- [Master Part, sekitar 07:20](../storage/app/private/reviews/2026-10-29-video-web/frames/frame-0440.25.jpg): filter Vendor/Kategori/Lokasi.
- [Master Part, sekitar 07:25](../storage/app/private/reviews/2026-10-29-video-web/frames/frame-0445.25.jpg): kategori yang berisi nama panjang menyerupai deskripsi.
- [Master Part, sekitar 07:40](../storage/app/private/reviews/2026-10-29-video-web/frames/frame-0460.25.jpg): FILTER/BATTERY/GET.
- [PO, sekitar 02:05](../storage/app/private/reviews/2026-10-29-video-web/frames/frame-0125.25.jpg): label ME5A dan input PO.
- [Manifest frame](../storage/app/private/reviews/2026-10-29-video-web/manifest.json).

Bukti implementasi:

- [Routes aktual](../routes/web.php): autentikasi dan Master Data; belum ada route transaksi operasional.
- [SaveItem](../app/Actions/MasterData/SaveItem.php): aturan STOCK/CONSIGNMENT, minimum, QUANTITY, FK UUID dan transaksi simpan.
- [Item](../app/Models/Item.php), [VendorItem](../app/Models/VendorItem.php), [Vendor](../app/Models/Vendor.php), [Location](../app/Models/Location.php): identitas dan relasi master.
- [UserRole](../app/Enums/UserRole.php) dan [MasterDataPolicy](../app/Policies/MasterDataPolicy.php): cakupan role tahap sekarang.
- [WorkbookReader](../app/Services/MasterData/WorkbookReader.php), [ImportPlanner](../app/Services/MasterData/ImportPlanner.php), [WorkbookExporter](../app/Services/MasterData/WorkbookExporter.php): import/export Master Data dan pencocokan kode.
- [Skema UUID](../app/Support/UuidTableUpgrade.php): pemisahan tabel, PK/FK UUID dan field yang tersedia.

Analisis ini menambahkan dokumen dan artefak pembacaan video. Tidak ada fitur,
schema, nilai master, atau transaksi aplikasi yang diubah dalam pekerjaan ini.
Tidak ada klaim uji backend web lama. Pemeriksaan proyek pada pekerjaan ini
bersifat telaah kode, dokumen, bukti visual dan baca metadata database.
