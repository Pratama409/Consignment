# Vendor Refill Design

## Intent

Membangun alur Vendor Refill sampai Incoming Refill untuk stok CONSIGNMENT: Admin meminta pengisian, Vendor mengusulkan revisi atau mengirim, Warehouse menerima secara parsial, dan hanya penerimaan yang diposting yang menambah stok.

## Roles

- Admin membuat refill, meninjau revisi Vendor, follow up, dan menutup sisa dengan alasan wajib.
- Vendor hanya melihat refill miliknya, mengusulkan quantity yang mampu dikirim, dan mengisi informasi pengiriman.
- Warehouse melihat refill yang dikirim dan membuat receipt berdasarkan quantity fisik yang diterima.

## Business rules

- Suggest = max(0, target refill - stok tersedia - outstanding pada refill terbuka).
- Target refill adalah `items.minimum_stock`; tidak ada maximum stock.
- Hanya item CONSIGNMENT aktif dengan relasi `vendor_items` aktif yang dapat diminta.
- Satu refill mempunyai banyak baris item dan dapat mempunyai banyak shipment serta receipt.
- Revisi Vendor tidak mengubah quantity permintaan asli dan tidak menghapus outstanding.
- Vendor tidak boleh menandai shipment dari revisi yang belum disetujui.
- Receipt parsial menambah stok hanya sebesar quantity aktual.
- Satu receipt diposting tepat sekali melalui `PostStockIn` bertipe `REFILL_RECEIPT`.
- Refill selesai ketika seluruh requested quantity diterima; Admin dapat menutup sisa dengan alasan sehingga status menjadi `CLOSED_PARTIAL`.
- Record posted/approved tidak diedit destruktif; perubahan penting mempunyai aktor dan waktu.

## Data design and 3NF

- `vendor_refills`: satu fakta request/header (nomor, Vendor, status, pembuat, waktu, catatan, penutupan).
- `vendor_refill_lines`: satu fakta item yang diminta pada refill; unique refill+item.
- `vendor_refill_revisions`: setiap usulan quantity/alasan/review sebagai riwayat terpisah; tidak menimpa requested quantity.
- `vendor_refill_shipments`: satu fakta pengiriman Vendor (DO/resi, ekspedisi, waktu kirim/perkiraan tiba).
- `vendor_refill_shipment_lines`: quantity per line yang dikirim; tidak disimpan sebagai kolom berulang pada shipment.
- `vendor_refill_receipts`: satu fakta penerimaan Warehouse dan referensi transaksi stok hasil posting.
- `vendor_refill_receipt_lines`: quantity aktual dan lokasi per refill line.

Seluruh PK/FK memakai UUID. Nama Vendor, barang, dan lokasi tidak diduplikasi; transaksi menyimpan FK. Nilai turunan seperti outstanding, total diterima, dan status progress diturunkan dari line/shipment/receipt, bukan disimpan pada beberapa tabel. Snapshot hanya dipakai bila identitas historis memang harus dipertahankan.

## Status

- Refill: `DRAFT`, `REQUESTED`, `REVISION_PENDING`, `APPROVED`, `SHIPPING`, `PARTIAL`, `COMPLETED`, `CLOSED_PARTIAL`, `CANCELLED`.
- Revision: `PENDING`, `APPROVED`, `REJECTED`.
- Shipment: `DISPATCHED`, `PARTIALLY_RECEIVED`, `RECEIVED`.
- Receipt: `DRAFT`, `POSTED`.

## UI

- Admin: daftar refill, low-stock suggestion, form request, detail/review revision/follow-up/close.
- Vendor: refill miliknya, form revisi, form shipment.
- Warehouse: incoming list, receipt form, riwayat penerimaan parsial.
- Semua halaman menampilkan requested, shipped, received, outstanding, status, dan tindakan berikutnya dengan bahasa operasional.

## Deferred

- WhatsApp/email, harga/invoice/pembayaran, integrasi SAP langsung, QR/barcode, rotable/tyre.
