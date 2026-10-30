# Material Request and Data Out Design

## Intent

Digitalisasi MR publik sampai persiapan, approval Admin sementara, Ready to Pick Up, serah terima parsial, dan Data Out Consignment. Barang STOCK tetap dicatat sebagai permintaan/penyerahan operasional tetapi saldo utamanya berada di SAP.

## Rules

- Pemohon dapat membuat MR tanpa akun; status publik memerlukan kode MR dan verifikasi NRP/telepon.
- Satu MR dapat berisi STOCK dan CONSIGNMENT; ownership berasal dari Master Barang.
- Warehouse boleh menyiapkan sebelum approval, tetapi tidak boleh menyerahkan sebelum approval Admin.
- Status kesiapan dan penyerahan disimpan per baris.
- Revisi sebelum penyerahan disimpan sebagai riwayat; baris yang berubah memerlukan approval ulang, baris tidak berubah tetap siap.
- Data Out Consignment diposting saat quantity fisik diserahkan. STOCK tidak mengubah saldo web.
- Penyerahan parsial diperbolehkan; quantity total tidak boleh melebihi permintaan efektif.
- Posted issue immutable dan retry idempotent.

## 3NF

- `departments`: identitas departemen.
- `material_requests`: fakta permintaan/pemohon/header dan token status ter-hash.
- `material_request_lines`: satu barang dan quantity awal per permintaan.
- `material_request_revisions`: satu peristiwa revisi header.
- `material_request_revision_lines`: perubahan per baris tanpa menimpa permintaan asli.
- `material_request_approvals`: keputusan approval dan aktor.
- `material_issues`: satu peristiwa serah terima aktual.
- `material_issue_lines`: quantity aktual per request line serta bucket Consignment bila relevan.

Nilai requested efektif, ready, issued, dan remaining diturunkan dari detail/revisi/issue. Semua PK/FK UUID.

## Status

- MR: `SUBMITTED`, `PREPARING`, `PARTIALLY_READY`, `READY`, `PARTIALLY_ISSUED`, `COMPLETED`, `CANCELLED`.
- Line: `PENDING`, `PREPARING`, `READY`, `PARTIALLY_ISSUED`, `ISSUED`, `CANCELLED`.
- Approval: `PENDING`, `APPROVED`, `REJECTED`, `SUPERSEDED`.
- Issue: `DRAFT`, `POSTED`.
