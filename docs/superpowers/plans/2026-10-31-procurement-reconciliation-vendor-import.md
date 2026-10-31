# Procurement, Reconciliation, and Vendor Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Correct the approved Procurement/BA workflows and add safe Excel preview-confirm import for Vendor/PIC.

**Architecture:** Enforce workflow transitions in domain actions, expose explicit BA abilities from the controller, and keep whole-unit display semantics. Vendor imports use a UUID batch with parsed preview rows followed by one transactional confirmation.

**Tech Stack:** Laravel 13, Vue 3, Inertia, PhpSpreadsheet, PHPUnit, MySQL/SQLite.

**Spec:** `docs/superpowers/specs/2026-10-29-consignment-operations-design.md` and `docs/superpowers/specs/2026-10-31-vendor-pic-excel-import-design.md`

## Global Constraints

- UUID primary keys; additive migrations only.
- Quantities are whole units; null physical quantity is distinct from zero.
- Admin-only Vendor import with preview and idempotent confirmation.

## Review Focus

- PO/GRGI uploaded out of order must be rejected.
- A revised PR invalidates the prior validation.
- Vendor import duplicate normalized names and formulas must block confirmation.
- Vendor users must not see internal documents or other Vendor data.
- BA actions and controls must match role and status.

---

### Task 1: Procurement and recap correctness

**Files:** `app/Actions/Procurement/*`, `app/Queries/TransactionRecapQuery.php`, `resources/js/Pages/Procurement/Index.vue`, Procurement/monitoring tests.

- [ ] Add failing workflow and signed-quantity tests.
- [ ] Enforce PR validation, PO after VALID, GRGI after PO, and COMPLETE after full GRGI.
- [ ] Map/display `covered_quantity` and run focused tests.
- [ ] Commit.

### Task 2: Reconciliation UI contract

**Files:** `VendorReconciliationController.php`, reconciliation Vue pages and tests.

- [ ] Add failing tests for ability props and terminal statuses.
- [ ] Expose `can_edit_physical`, `can_respond`, `can_resolve`, `can_finalize`.
- [ ] Show whole quantities, distinguish null, display form errors, and use `decision` history.
- [ ] Build and commit.

### Task 3: Vendor/PIC Excel preview import

**Files:** additive migration/models/actions/controller/routes/Vue/tests.

- [ ] Add failing import tests for create/update, dash-to-null, duplicate/formula rejection, and one-time confirmation.
- [ ] Implement UUID batch/rows, parser, preview, transactional confirm, routes and Admin UI.
- [ ] Run focused tests, full suite, build, review, commit and push.
