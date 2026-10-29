# Monitoring, Shift Recap, Stock System, and BA Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide auditable transaction history, 12-hour shift closing, Vendor-separated system stock, and versioned monthly BA reconciliation with Vendor agreement/dispute and Excel/PDF export.

**Architecture:** Monitoring reads the shared immutable ledger and procurement projections rather than maintaining independent stock totals. Shift snapshots and BA versions freeze report outputs by checksum while late postings remain in their actual posting period.

**Tech Stack:** PHP 8.3, Laravel 13, Inertia 3, Vue 3, PhpSpreadsheet 5.10, a compatible maintained PDF renderer added only in the PDF task, PHPUnit 12, Tailwind CSS 4, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-29-consignment-operations-design.md`

## Global Constraints

- Execute after the inventory foundation; BA requires Refill/Data IN, Data OUT, and procurement references to be complete enough for fixtures.
- Shift recap uses `posted_at` and WITA; physical event dates are reference fields only.
- BA is monthly per Vendor and uses `opening + IN - OUT ± adjustment/reversal = closing`.
- Final snapshots/BA versions are immutable; later corrections create new postings and versions.
- Vendor sees only its own stock, transactions, BA, and explicitly shared documents.
- Full Stock Opname remains out of scope; physical quantity is optional Admin input for BA.

## Review Focus

- A transaction posted at 18:00 must belong to night shift and a 02:00 posting to the prior-date night instance; pin in Task 2.
- Late October physical dates posted in November must remain in November shift/BA and be visibly marked late; pin in Tasks 1 and 4.
- Totals across pages/exports must reflect all filtered records, not only the current page; pin in Task 1.
- BA for Vendor A must exclude STOCK and every Vendor B movement even when item and location are identical; pin in Task 4.
- Repeated finalization/export must preserve the exact version/checksum and never rewrite a previous final artifact; pin in Tasks 5 and 6.

---

### Task 1: Transaction recap query, filters, and Excel export

**Files:**
- Create: `app/Queries/TransactionRecapQuery.php`
- Create: `app/Services/Monitoring/TransactionRecapExporter.php`
- Create: `app/Http/Controllers/Monitoring/TransactionRecapController.php`
- Create: `app/Http/Requests/Monitoring/TransactionRecapRequest.php`
- Create: `app/Policies/MonitoringPolicy.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TransactionRecapTest.php`

**Interfaces:**
- Produces: `TransactionRecapQuery::build(User $viewer, array $filters): Builder` and `summaries(User $viewer, array $filters): array`.
- Produces: server-side XLSX export using the exact same normalized filters.

- [ ] **Step 1: Write failing recap tests**

  Assert newest-first ledger rows with source number, IN/OUT/adjustment/reversal, item, ownership, Vendor, location, DO/MR/PR/PO/GRGI, posting shift, physical date, late flag, and document status; test every filter combination, full-result totals across pagination, Vendor scoping, formula-safe XLSX cells, and late October physical/November posting membership.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/TransactionRecapTest.php`

  Expected: FAIL with missing query/controller/exporter.

- [ ] **Step 3: Implement normalized query, policy, route, and exporter**

  Build one base query method used by index, summaries, and export. Avoid joins that duplicate ledger rows; use subqueries/eager loading for document references.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/TransactionRecapTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Queries/TransactionRecapQuery.php app/Services/Monitoring/TransactionRecapExporter.php app/Http/Controllers/Monitoring/TransactionRecapController.php app/Http/Requests/Monitoring/TransactionRecapRequest.php app/Policies/MonitoringPolicy.php routes/web.php tests/Feature/TransactionRecapTest.php && git commit -m "feat: add transaction recap"`

### Task 2: Versioned 12-hour shift closing

**Files:**
- Create: `database/migrations/2026_10_29_006000_create_shift_recap_snapshots_table.php`
- Create: `app/Models/ShiftRecapSnapshot.php`
- Create: `app/Actions/Monitoring/CloseOperationalShift.php`
- Create: `app/Actions/Monitoring/GenerateShiftRecap.php`
- Create: `app/Http/Controllers/Monitoring/ShiftRecapController.php`
- Test: `tests/Feature/ShiftRecapTest.php`

**Interfaces:**
- Produces: `GenerateShiftRecap::handle(OperationalShift $shift): ShiftRecapData`.
- Produces: `CloseOperationalShift::handle(OperationalShift $shift, User $actor): ShiftRecapSnapshot`.

- [ ] **Step 1: Write failing boundary/snapshot tests**

  Assert 06:00/18:00 boundaries, night cross-midnight membership, opening + IN - OUT ± correction = closing, outstanding refill/document counts, late-posting list, Admin/Warehouse close permission, one close version per action, stable checksum, and no mutation of old snapshot after later correction.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/ShiftRecapTest.php`

  Expected: FAIL because snapshot/action/controller do not exist.

- [ ] **Step 3: Implement aggregate data object and closing transaction**

  Serialize normalized decimal strings and sorted row identifiers before hashing. Closing marks the operational shift CLOSED but does not prevent current postings; late posting is assigned to the current open shift by the foundation rule.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/ShiftRecapTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add database/migrations/2026_10_29_006000_create_shift_recap_snapshots_table.php app/Models/ShiftRecapSnapshot.php app/Actions/Monitoring app/Http/Controllers/Monitoring/ShiftRecapController.php tests/Feature/ShiftRecapTest.php && git commit -m "feat: add shift recap closing"`

### Task 3: Stock System query and drill-down

**Files:**
- Create: `app/Queries/SystemStockQuery.php`
- Create: `app/Http/Controllers/Monitoring/SystemStockController.php`
- Create: `app/Http/Requests/Monitoring/SystemStockRequest.php`
- Create: `resources/js/Pages/Monitoring/SystemStock/Index.vue`
- Create: `resources/js/Pages/Monitoring/SystemStock/Show.vue`
- Test: `tests/Feature/SystemStockTest.php`

**Interfaces:**
- Produces: `SystemStockQuery::build(User $viewer, array $filters): Builder` with CONSIGNMENT rows per Vendor–item–location and STOCK rows per item–location.
- Produces: bucket drill-down to ledger and remaining lots.

- [ ] **Step 1: Write failing Stock System tests**

  Assert Vendor-separated rows, optional aggregate totals without loss of ownership detail, search/filter/pagination, ledger/lot drill-down sums to balance, Vendor isolation, inactive master history visibility, and no data mutation from viewing/exporting.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/SystemStockTest.php`

  Expected: FAIL with missing query/controller/pages.

- [ ] **Step 3: Implement backend and Inertia pages**

  Display decimal quantities consistently and distinguish ownership badge from status. Provide links to source transactions only when the viewer is authorized for the source.

- [ ] **Step 4: Verify feature and frontend build**

  Run: `php artisan test --compact tests/Feature/SystemStockTest.php && npm run build`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Queries/SystemStockQuery.php app/Http/Controllers/Monitoring/SystemStockController.php app/Http/Requests/Monitoring/SystemStockRequest.php resources/js/Pages/Monitoring/SystemStock tests/Feature/SystemStockTest.php && git commit -m "feat: add system stock monitoring"`

### Task 4: Monthly Vendor BA calculation and version schema

**Files:**
- Create: `database/migrations/2026_10_29_006100_create_vendor_reconciliation_tables.php`
- Create: `app/Enums/VendorReconciliationStatus.php`
- Create: `app/Models/VendorReconciliation.php`
- Create: `app/Models/VendorReconciliationLine.php`
- Create: `app/Models/VendorReconciliationResponse.php`
- Create: `app/Models/VendorReconciliationDocument.php`
- Create: `app/Actions/Reconciliation/GenerateVendorReconciliation.php`
- Create: `app/Queries/VendorReconciliationSourceQuery.php`
- Test: `tests/Feature/VendorReconciliationCalculationTest.php`

**Interfaces:**
- Produces: `VendorReconciliationSourceQuery::forPeriod(Vendor $vendor, CarbonImmutable $start, CarbonImmutable $end): Collection<ReconciliationSourceRow>` based on `posted_at`.
- Produces: `GenerateVendorReconciliation::handle(Vendor $vendor, CarbonImmutable $start, CarbonImmutable $end, User $admin): VendorReconciliation`.

- [ ] **Step 1: Write failing calculation/isolation tests**

  Assert UUIDs and unique active Vendor/period; opening, IN, OUT, adjustment/reversal, closing formula; Vendor A excludes STOCK/Vendor B; November contains late October physical event posted in November; optional physical qty calculates variance; generation creates a new draft version without overwriting prior final.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/VendorReconciliationCalculationTest.php`

  Expected: FAIL because BA schema/query/action do not exist.

- [ ] **Step 3: Implement schema, models, source query, and generator**

  Persist decimal snapshots and the exact source cutoff/posting timestamp. Link each version to `supersedes_id` where applicable.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/VendorReconciliationCalculationTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add database/migrations/2026_10_29_006100_create_vendor_reconciliation_tables.php app/Enums/VendorReconciliationStatus.php app/Models/VendorReconciliation.php app/Models/VendorReconciliationLine.php app/Models/VendorReconciliationResponse.php app/Models/VendorReconciliationDocument.php app/Actions/Reconciliation/GenerateVendorReconciliation.php app/Queries/VendorReconciliationSourceQuery.php tests/Feature/VendorReconciliationCalculationTest.php && git commit -m "feat: calculate vendor reconciliation"`

### Task 5: Vendor review, dispute resolution, adjustment link, and finalization

**Files:**
- Create: `app/Actions/Reconciliation/SubmitVendorResponse.php`
- Create: `app/Actions/Reconciliation/UpdatePhysicalQuantity.php`
- Create: `app/Actions/Reconciliation/ResolveVendorDispute.php`
- Create: `app/Actions/Reconciliation/FinalizeVendorReconciliation.php`
- Create: `app/Http/Controllers/Monitoring/VendorReconciliationController.php`
- Create: `app/Http/Requests/Reconciliation/VendorResponseRequest.php`
- Create: `app/Policies/VendorReconciliationPolicy.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/VendorReconciliationWorkflowTest.php`

**Interfaces:**
- Produces: `SubmitVendorResponse::handle(VendorReconciliation $ba, bool $agree, string $note, User $vendorUser): VendorReconciliationResponse`.
- Produces: `UpdatePhysicalQuantity::handle(VendorReconciliationLine $line, string $quantity, User $admin): VendorReconciliationLine` for draft versions only.
- Produces: `ResolveVendorDispute::handle(VendorReconciliation $ba, ?StockAdjustment $adjustment, string $note, User $admin): VendorReconciliation`.
- Produces: `FinalizeVendorReconciliation::handle(VendorReconciliation $ba, User $admin): VendorReconciliation`.

- [ ] **Step 1: Write failing workflow tests**

  Assert owning Vendor alone can agree/dispute; dispute requires note; Warehouse can inspect but not finalize/adjust; only Admin may enter nonnegative four-decimal physical quantity on a draft; Admin links a posted adjustment or records no-stock-change resolution; recalculation creates a new version; finalization rejects unresolved variance/dispute, freezes version/checksum, and is idempotent.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/VendorReconciliationWorkflowTest.php`

  Expected: FAIL with missing actions/controller/policy.

- [ ] **Step 3: Implement actions, transitions, policy, and routes**

  Never edit a reconciliation line to hide a stock difference. Any stock correction must already exist as a posted foundation adjustment and appear in the regenerated version.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/VendorReconciliationWorkflowTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Actions/Reconciliation app/Http/Controllers/Monitoring/VendorReconciliationController.php app/Http/Requests/Reconciliation app/Policies/VendorReconciliationPolicy.php routes/web.php tests/Feature/VendorReconciliationWorkflowTest.php && git commit -m "feat: add vendor reconciliation workflow"`

### Task 6: Excel/PDF artifacts and monitoring UI

**Files:**
- Create: `app/Services/Reconciliation/ReconciliationExcelExporter.php`
- Create: `app/Services/Reconciliation/ReconciliationPdfExporter.php`
- Create: `resources/views/reconciliation/pdf.blade.php`
- Create: `resources/js/Pages/Monitoring/Transactions/Index.vue`
- Create: `resources/js/Pages/Monitoring/Shifts/Index.vue`
- Create: `resources/js/Pages/Monitoring/Shifts/Show.vue`
- Create: `resources/js/Pages/Monitoring/Reconciliations/Index.vue`
- Create: `resources/js/Pages/Monitoring/Reconciliations/Show.vue`
- Modify: `resources/js/Components/AppSidebar.vue`
- Modify: `composer.json`
- Modify: `composer.lock`
- Test: `tests/Feature/ReconciliationExportTest.php`
- Test: `tests/Browser/monitoring-reconciliation.spec.js`

**Interfaces:**
- Consumes: final BA version and shift/transaction queries.
- Produces: `ReconciliationExcelExporter::export(VendorReconciliation $ba): string` and `ReconciliationPdfExporter::export(VendorReconciliation $ba): string`, each returning a private artifact path recorded with checksum.

- [ ] **Step 1: Add failing artifact and browser tests**

  Assert Excel/PDF use the same frozen version/totals, repeated export returns the same immutable artifact/checksum, formula-like text is escaped, Vendor download is scoped, final BA cannot be changed, role-aware pages show shift recap/transactions/BA, and mobile views do not overflow the page.

- [ ] **Step 2: Run and confirm expected failures**

  Run: `php artisan test --compact tests/Feature/ReconciliationExportTest.php`

  Run: `npx playwright test tests/Browser/monitoring-reconciliation.spec.js`

  Expected: FAIL because exporters/pages are absent.

- [ ] **Step 3: Add one maintained Laravel-compatible PDF dependency and implement exporters**

  Prefer a maintained DOMPDF adapter compatible with PHP 8.3/Laravel 13; record the selected locked version in the implementation commit. Render from a dedicated Blade view with local assets only. Store artifacts privately and authorize download.

- [ ] **Step 4: Implement monitoring pages and run the complete gate**

  Run: `php artisan test --compact`

  Run: `php vendor/bin/pint --test`

  Run: `npm run build`

  Run: `npm run test:browser`

  Run: `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml`

  Expected: all PASS; report exact observed counts and any skipped environment-specific checks.

- [ ] **Step 5: Commit**

  Run: `git add app/Services/Reconciliation resources/views/reconciliation resources/js/Pages/Monitoring resources/js/Components/AppSidebar.vue composer.json composer.lock tests/Feature/ReconciliationExportTest.php tests/Browser/monitoring-reconciliation.spec.js && git commit -m "feat: add monitoring and reconciliation exports"`

