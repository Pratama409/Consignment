# Material Request and Data Out Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans task-by-task with TDD.

**Goal:** Menyediakan MR digital publik sampai Data Out parsial yang aman dan dapat diaudit.

**Architecture:** Aggregate MR ternormalisasi 3NF memisahkan request, revision, approval, dan actual issue. Data Out Consignment memakai `PostStockOut`; STOCK hanya mencatat fulfillment dan referensi SAP.

**Tech Stack:** Laravel 13, PHP 8.4, Vue 3, Inertia, MySQL/SQLite.

**Spec:** `docs/superpowers/specs/2026-10-30-material-request-design.md`

## Global Constraints

- UUID dan minimal 3NF untuk seluruh tabel.
- Tidak ada stock decrement saat request/prepare/ready; hanya issue POSTED Consignment.
- Public status tidak membuka stok, Vendor, dokumen, atau audit internal.
- Posted history immutable/idempotent.

## Review Focus

- Guessing kode MR tidak boleh membuka permintaan tanpa verifier.
- Revision/issue bersamaan tidak boleh over-issue.
- Approval lama tidak boleh berlaku pada baris yang direvisi.
- MR campuran hanya memposting saldo Consignment.
- Partial issue/retry tidak boleh menggandakan stok keluar.

### Task 1: Normalized MR schema and domain models

- [ ] RED schema/constraint tests.
- [ ] Create departments, requests, lines, revisions, approvals, issues, and issue-lines with UUID/FK/check constraints.
- [ ] GREEN focused tests and commit.

### Task 2: Public request, tracking, revision, and approval workflow

- [ ] RED workflow/security tests.
- [ ] Implement locked actions and public controllers.
- [ ] GREEN focused tests and commit.

### Task 3: Warehouse preparation and partial Data Out

- [ ] RED readiness/issue/idempotency/mixed ownership tests.
- [ ] Implement preparation and atomic Consignment issue posting.
- [ ] GREEN focused tests and commit.

### Task 4: Role-aware and public Vue flows

- [ ] RED route/authorization tests.
- [ ] Implement public request/status and Admin/Warehouse queue/detail pages.
- [ ] GREEN, build, browser smoke, commit, final review.
