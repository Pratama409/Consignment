# Consignment Control Center — UI Design

## 1. Tujuan dan sumber visual
Gunakan dokumen ini bersama skill frontend-design sebagai acuan UI proyek Laravel + Inertia + Vue. Referensi adalah screenshot dashboard Warehouse System yang diberikan pengguna. Pertahankan ciri utama: sidebar navy, menu aktif biru–cyan, header gradient, kartu terang dengan sudut membulat, ikon sederhana, dan tabel administrasi.
Nilai warna dan ukuran berikut adalah pendekatan desain dari screenshot, bukan hasil pengukuran piksel. Jangan menyalin tab browser, address bar, atau banner Google Apps Script.

## 2. Ruang lingkup
Tahap pertama hanya Master Data: Barang, Vendor, Satuan, Lokasi, dan Import Master PN. Screenshot menjadi referensi visual, bukan persetujuan menambah PR, PO, nilai stok, ITO, notifikasi, dark mode, atau auto-update. Tampilkan fitur tersebut hanya setelah dirancang dan diimplementasikan. Jangan menampilkan angka contoh sebagai data produksi.

## 3. Token visual
| Token | Nilai | Pemakaian |
|---|---|---|
| sidebar-bg | #0B1220 | Sidebar utama |
| sidebar-top | #142B3B | Bagian identitas sidebar |
| page-bg | #EEF3FA | Latar halaman |
| surface | #FFFFFF | Tabel, form, modal, kartu |
| text-primary | #0F172A | Judul dan isi penting |
| text-secondary | #64748B | Label tambahan |
| sidebar-text | #A9BCD2 | Menu belum aktif |
| border | #E2E8F0 | Pemisah dan batas input |
| primary | #2563EB | Tombol utama, tautan, fokus |
| cyan | #06B6D4 | Aksen gradient |
| violet | #A78BFA | Ujung aksen garis kartu |
| success | #15803D | Aktif dan berhasil |
| warning | #B45309 | Perlu ditinjau |
| danger | #DC2626 | Error dan tindakan berisiko |

Header gradient: linear-gradient(110deg, #0B1B30 0%, #1D4ED8 60%, #06B6D4 100%).
Menu aktif: linear-gradient(90deg, #2563EB, #06B6D4).
Aksen atas kartu: linear-gradient(90deg, #3B82F6, #22D3EE, #A78BFA), tinggi 3px.
Bayangan kartu: 0 8px 24px rgba(15,23,42,.05).
Gradient digunakan pada header, menu aktif, dan garis aksen; isi tabel dan form tetap tenang dengan permukaan solid.

## 4. Tipografi dan jarak
- Gunakan font sans yang sudah tersedia; pilihan awal Inter dengan fallback system-ui, sans-serif. Jangan menambahkan font eksternal sebagai syarat aplikasi berjalan.
- Judul header dashboard: 32–40px, weight 800; mobile 26–30px.
- Judul halaman Master Data: 28–32px, weight 750–800.
- Judul kartu: 16–18px, weight 700.
- Teks tabel/form: 14px, line-height 1.5; label 13px, weight 600.
- Label sidebar: 12–13px, weight 700; judul kelompok boleh uppercase.
- Nilai ringkasan: 30–36px, weight 800; angka memakai tabular-nums.
- Skala jarak: 4, 8, 12, 16, 24, 32px.
- Padding halaman desktop 32px, tablet 24px, mobile 16px.
- Radius kartu 24px; header 28px; input/tombol 12px; badge pill 999px.

## 5. Kerangka aplikasi
### Sidebar
- Lebar desktop 260–280px, tinggi viewport, posisi tetap.
- Bagian atas: WAREHOUSE SYSTEM dalam cyan lembut, dengan pemisah tipis.
- Navigasi: Dashboard; Master Data dengan anak Barang, Vendor, Satuan, Lokasi, Import Master PN.
- Jangan membuat route mati untuk modul masa depan; cukup dokumentasikan rencananya.
- Menu aktif berbentuk kapsul membulat, teks putih, ikon dengan latar putih transparan.
- Menu lain memakai teks muted; hover memperjelas latar tanpa gerakan besar.
- Pakai satu pustaka ikon yang sudah terpasang; bila belum ada, gunakan SVG sederhana secara konsisten. Jangan menambah beberapa pustaka ikon.
- Bagian bawah berisi identitas dan role dari user terautentikasi serta logout. Notifikasi hanya muncul bila fiturnya tersedia.

### Area utama
- Latar abu-biru lembut; tanpa blur dekoratif di belakang teks.
- Header gradient menampilkan eyebrow CONSIGNMENT CONTROL CENTER, judul halaman, dan deskripsi singkat.
- Header Master Data tinggi sekitar 140–170px; dashboard boleh 180–200px.
- Gunakan maksimal satu lingkaran dekoratif transparan, tersembunyi dari accessibility tree dan tidak menangkap klik.
- Metadata user atau waktu hanya ditampilkan jika datanya tersedia; jangan memasang label auto-update tanpa mekanisme nyata.
- Tombol header putih transparan dengan border putih; hindari aksi berlebihan.

## 6. Pola halaman Master Data
Urutan isi: header, ringkasan opsional, toolbar, tabel, pagination.
Ringkasan hanya untuk informasi yang membantu: Total, Aktif, Nonaktif. Boleh dihilangkan pada master kecil. Gunakan statistik dari server dengan cakupan yang jelas, bukan jumlah baris pada satu halaman pagination.
Toolbar: pencarian di kiri; filter status dan tombol Tambah di kanan. Mobile ditumpuk.

### Master Barang
Kolom: Kode, Part Number, Nama/Deskripsi, Jenis, Satuan, Minimum, Jumlah Vendor, Status, Aksi.
Pertahankan kode/PN dalam satu baris. Nama barang boleh membungkus; jangan memotong informasi penting tanpa akses detail.
Form memuat part number, nama, ownership, satuan, tracking, minimum, status. Kode dibuat sistem.
Untuk CONSIGNMENT tampilkan daftar pasangan Vendor–Lokasi dengan tombol Tambah Vendor dan error per baris. Untuk STOCK sembunyikan bagian Vendor. Lokasi STOCK mengikuti keputusan skema final; jangan menetapkan struktur database melalui desain UI.
Gunakan halaman form terpisah atau panel lebar bila daftar Vendor membuat modal terlalu panjang.

### Master Vendor
Kolom: Kode Vendor, Nama Vendor, Status, Aksi.
Form: kode, nama, status. Jangan menambahkan WAREHOUSE sebagai Vendor.

### Master Satuan
Kolom: Kode, Nama Satuan, Status, Aksi.
Form compact: kode, nama, status. Contoh PC/Piece hanya placeholder, bukan record otomatis.

### Master Lokasi
Kolom: Kode Lokasi, Keterangan, Status, Aksi.
Form: kode, keterangan, status. Tampilkan UNASSIGNED sebagai lokasi sistem; perilaku pengunciannya mengikuti aturan backend final.

### Import Master PN
Alur: Unggah → Validasi dan preview → Konfirmasi → Hasil.
Tampilkan nomor baris sumber, data terbaca, status validasi, dan pesan masalah. Error tidak dibedakan berdasarkan warna saja.
Tombol Import hanya aktif setelah validasi sesuai kebijakan import final. Jangan melakukan import ketika file baru dipilih.

## 7. Tabel
- Container putih dengan radius 24px dan border tipis.
- Header tabel navy #111827, teks putih; tinggi sekitar 42px.
- Baris tinggi minimal 48px, padding horizontal 16px, pemisah #E2E8F0, hover #F8FAFC.
- Nama rata kiri, angka rata kanan, status menggunakan badge berlabel.
- Header gelap dipertahankan sebagai ciri screenshot; jangan membuat semua baris gelap.
- Aksi Edit dan Nonaktifkan tersedia sesuai otorisasi. Sediakan Aktifkan untuk record nonaktif bila backend mendukung.
- Pagination mencantumkan rentang hasil, total, dan kontrol sebelumnya/berikutnya.
- Pada layar sempit gunakan scroll horizontal lokal pada tabel, bukan seluruh halaman.

## 8. Form, tombol, dan modal
- Input minimal tinggi 42px; label selalu terlihat; placeholder bukan pengganti label.
- Fokus: outline/ring biru yang jelas. Error: border merah dan teks penjelasan dekat input.
- Tombol utama biru solid, teks putih; sekunder putih dengan border; aksi risiko memakai merah.
- State tombol: default, hover, focus, disabled, processing. Saat submit tampilkan Menyimpan… dan cegah submit ganda.
- Modal: overlay navy transparan, permukaan putih, radius 24px, lebar sesuai isi, max-height 90dvh dan body dapat discroll.
- Footer: Batal lalu satu aksi utama. Modal mendukung keyboard, fokus terperangkap, Escape ketika aman, dan pengembalian fokus ke pemicu.
- Penonaktifan menampilkan nama record dan dampak yang benar. Gunakan kata Nonaktifkan, bukan Hapus jika data dipertahankan.
- Setelah validasi gagal, pertahankan input pengguna. Setelah sukses, tampilkan flash/feedback yang tersedia.

## 9. Badge semantik
| Arti | Latar / teks |
|---|---|
| Aktif / berhasil | #DCFCE7 / #166534 |
| Nonaktif | #F1F5F9 / #475569 |
| CONSIGNMENT | #E0E7FF / #3730A3 |
| STOCK | #DBEAFE / #1E40AF |
| Perlu ditinjau | #FEF3C7 / #92400E |
| Tidak valid / gagal | #FEE2E2 / #991B1B |
Jenis kepemilikan dan status aktif adalah dua badge berbeda. Jangan menyamakan STOCK dengan indikator berhasil.

## 10. Responsif dan aksesibilitas
- Desktop >=1280px: sidebar penuh; isi mengikuti sisa lebar.
- Tablet 768–1279px: sidebar dapat ditutup; ringkasan 2–3 kolom.
- Mobile <768px: sidebar drawer dengan overlay dan tombol menu; ringkasan 1–2 kolom; form satu kolom; header mengikuti tinggi isi.
- Dashboard mendatang boleh mengikuti enam kartu screenshot hanya jika lebar mencukupi. Jangan memaksa enam kolom di laptop sempit.
- Uji layout pada lebar 360, 768, 1280, dan 1920px.
- Pastikan kontras teks normal minimal 4.5:1; jangan mengandalkan cyan terang untuk teks kecil di atas putih.
- Tombol ikon wajib accessible name; ikon dekoratif aria-hidden.
- Gunakan struktur heading, label form, dan table header semantik. Target sentuh sekitar 44px. Hormati prefers-reduced-motion.
- Empty state: Belum ada data satuan dengan aksi tambah bila berwenang. Bedakan dari hasil pencarian kosong dan kegagalan pemuatan.

## 11. Pedoman implementasi Codex
1. Baca dokumen ini, frontend-design, dan dependensi aktual proyek.
2. Periksa layout, komponen starter kit, dan konvensi direktori yang tersedia; gunakan kembali yang sesuai.
3. Implementasikan hanya modul yang diminta. Mulai dari Master Satuan sebagai contoh visual jika diminta implementasi.
4. Gunakan Laravel web routes, Inertia props, useForm, dan pagination server. Otorisasi tetap di backend.
5. Simpan token bersama dalam sistem styling proyek; jangan menyalin nilai terpisah pada setiap halaman.
6. Kandidat komponen bersama: AppSidebar, PageBanner, SummaryCard, StatusBadge, TableToolbar, Pagination, FormField, ConfirmDialog. Buat bertahap sesuai penggunaan nyata.
7. Gunakan data backend yang nyata; untuk prototipe, tandai data contoh secara jelas.
8. Verifikasi build serta tampilan dan interaksi sesuai fasilitas yang tersedia. Jangan mengklaim pengujian browser bila belum dilakukan.

## 12. Kriteria penerimaan visual
- Sidebar navy dan menu aktif gradient biru–cyan menyerupai referensi.
- Header gradient, kartu terang membulat, dan tabel header navy konsisten.
- Teks, angka panjang, dan nominal tidak terpotong atau membungkus secara janggal seperti nominal pada screenshot.
- Form dan tabel nyaman dibaca, tanpa overflow halaman.
- Navigasi keyboard, error, kosong, processing, dan disabled dapat dipahami.
- Tidak ada modul, statistik, atau kemampuan yang diklaim sudah tersedia padahal belum dibuat.
