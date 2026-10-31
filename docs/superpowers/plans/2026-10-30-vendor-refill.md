# Vendor Refill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menyediakan Vendor Refill, revisi Vendor, shipment, dan Incoming Refill parsial yang memposting stok Consignment tepat sekali.

**Architecture:** Workflow dipisah menjadi aggregate request, revision, shipment, dan receipt yang ternormalisasi 3NF. Action service mengunci aggregate dalam transaksi database; receipt memakai mesin `PostStockIn` yang sudah ada.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL/SQLite tests, Vue 3 Composition API, Inertia.js.

**Spec:** `docs/superpowers/specs/2026-10-30-vendor-refill-design.md`

## Global Constraints

- Semua tabel dan foreign key memakai UUID.
- Database minimal 3NF; jangan menduplikasi fakta atau menyimpan nilai turunan di beberapa tabel.
- Hanya receipt POSTED yang menambah stok.
- Preserve role, authorization, ledger, audit, dan master-data behavior yang ada.
- Tidak melakukan reset/drop database aplikasi.

## Review Focus

- Dua submit receipt yang sama tidak boleh menggandakan stok.
- Total receipt tidak boleh melampaui requested quantity yang belum ditutup.
- Vendor A tidak boleh melihat/mengubah refill Vendor B.
- Revisi pending tidak boleh dipakai untuk shipment.
- Perubahan lokasi default tidak boleh menghilangkan kemampuan receipt ke bucket historis yang valid.

---

### Task 1: Normalized refill schema and domain models

**Files:** migration, seven focused models, and `tests/Feature/VendorRefillSchemaTest.php`.

**Interfaces:** Produces normalized relationships and constraints consumed by Tasks 2–4.

- [ ] Write schema tests for UUID PK/FK, unique refill+item, positive quantities, lifecycle values, and restrict deletes.
- [ ] Run focused schema test and verify RED because tables do not exist.
- [ ] Implement migration and focused models/relationships.
- [ ] Run focused schema test and verify GREEN.
- [ ] Commit `feat: add normalized vendor refill schema`.

### Task 2: Request, suggestion, revision, and closure workflow

**Files:** `app/Actions/Refills/*`, `app/Services/Refills/RefillSuggestion.php`, and `tests/Feature/VendorRefillWorkflowTest.php`.

**Interfaces:** Consumes Task 1 models; produces locked transactional workflow methods.

- [ ] Write failing tests for suggestion subtracting open outstanding, active Consignment validation, Vendor scoping, revision history, approval/rejection, and close reason.
- [ ] Run focused tests and verify RED.
- [ ] Implement minimal transactional actions with `lockForUpdate` and validation exceptions.
- [ ] Run focused tests and verify GREEN.
- [ ] Commit `feat: add vendor refill request workflow`.

### Task 3: Shipment and partial receipt posting

**Files:** `app/Actions/Refills/CreateRefillShipment.php`, `app/Actions/Refills/PostRefillReceipt.php`, and `tests/Feature/VendorRefillReceiptTest.php`.

**Interfaces:** Consumes Task 1 models, Task 2 workflow, and `PostStockIn`; produces idempotent receipt posting.

- [ ] Write failing tests for approved shipment, pending-revision rejection, partial receipts, over-receipt rejection, repeated submission, ledger/balance/lot equality, and auto-completion.
- [ ] Run focused tests and verify RED.
- [ ] Implement shipment and atomic receipt actions using `REFILL_RECEIPT`.
- [ ] Run focused tests and verify GREEN.
- [ ] Commit `feat: post partial incoming refills`.

### Task 4: Role-aware routes, controllers, and Vue pages

**Files:** refill/incoming controllers, `resources/js/Pages/Refills/*`, routes, sidebar, shared props, and `tests/Feature/VendorRefillPageTest.php`.

**Interfaces:** Consumes all workflow actions; produces Admin, Vendor, and Warehouse UI flows.

- [ ] Write failing page/authorization tests for all three roles and validation/error visibility.
- [ ] Run focused tests and verify RED.
- [ ] Implement controllers, routes, navigation, and responsive pages using existing UI primitives.
- [ ] Run focused page tests and verify GREEN.
- [ ] Run frontend build, browser smoke checks, and PHP suite.
- [ ] Commit `feat: add vendor refill user experience`.
