# Schema Normalization to 3NF, WO/Backcharge, and Demo Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove derivable and transitively dependent columns from the operational schema (3NF), add Work Order number and Backcharge flag to Data OUT, and leave the app demo-ready with seeded dummy data.

**Architecture:** A new `stock_buckets` table (one row per item-or-vendor-item × location) becomes the single owner of bucket identity; ledger, balance, lot and opening-line rows reference `stock_bucket_id`. Line tables stop repeating item/location/quantity facts that a parent row already holds. Unmerged migrations are edited in place (data is dummy); posting still takes `StockPostingLineData`, so callers of `PostStockIn`/`PostStockOut` do not change.

**Tech Stack:** PHP 8.3, Laravel 13, Inertia 3, Vue 3, MySQL (InnoDB) + SQLite, PHPUnit 12, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-31-schema-normalization-3nf-design.md`

## Global Constraints

- Work only in `D:\Project\Consignment\.worktrees\consignment-operations` (branch `feature/consignment-operations`).
- Edit migrations `2026_10_29_002200`, `002300`, `002400`, `2026_10_30_000200`, `000300_create_manual_receipt_tables`, `000300_create_procurement_tables`, `000400` in place; add no forward "fix" migrations.
- Tests run only on SQLite `:memory:` and MySQL `consignment_testing` (enforced by `tests/TestCase.php`). Never touch database `consignment` except Task 8 step 4, and only after explicit user approval in that moment.
- Every task ends green on both engines: `php artisan test --compact` and `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml --no-progress`.
- Ledger stays immutable; balances never negative; quantity range guards (≤ `9999999999.9999`, four decimals) and idempotency keys unchanged; both MySQL and SQLite triggers kept.
- All primary and foreign keys are UUID, FK `restrictOnDelete`.
- Vendor scope must hold in backend query, detail, export and download for every new join path.
- Commit steps run on the feature branch only after the user has confirmed execution; commit message ends with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Kept by decision (comment each in its migration): `operational_shifts` code/name/window, `stock_transactions.operational_shift_id`, `vendor_reconciliation_lines` quantities, `material_requests.applicant_*`, `vendors.pic_name`, `vendor_refill_receipts.vendor_refill_id` (receipt's `vendor_refill_shipment_id` is nullable, so the refill cannot always be derived), `vendor_refill_receipts.vendor_do_number` (a receipt may differ from the shipment's `vendor_document_no`).

## Review Focus

- Two concurrent first postings into the same new bucket: one bucket row, both postings succeed or retry idempotently (MySQL concurrency test).
- A consignment bucket at a location that is no longer the Vendor's default (default edited later): still posts, reverses, and appears in System Stock.
- Reversal after the `vendor_items.location_id` default changed: reverses into the original bucket.
- A Vendor user requesting another Vendor's balance, bucket detail, reconciliation or procurement row by direct URL: denied, no data leaked.
- Re-importing the same PR/PO number across many requirements: one `procurement_references` row, active lines replaced, no duplicates.
- Data OUT with blank, over-length or whitespace-only WO number and non-boolean backcharge input: rejected with a field error, never a 500.

---

### Task 1: Baseline commit and schema guard test

**Files:**
- Modify (commit as-is): `app/Http/Controllers/DashboardController.php`, `app/Services/Reconciliation/ReconciliationPdfExporter.php`, `tests/Browser/master-data.spec.js`, `tests/Browser/user-management.spec.js`
- Create: `tests/Feature/SchemaNormalizationTest.php`

**Interfaces:**
- Produces: `SchemaNormalizationTest::test_redundant_column_is_absent(string $table, string $column)` and `::test_required_column_is_present(string $table, string $column)`, both data-provider driven, so each later task turns its own rows green.

- [ ] **Step 1: Commit the four pending files** with message `fix: scope vendor procurement dashboard via vendor item and refresh stale browser selectors`.
- [ ] **Step 2: Write the failing test.** Data providers (absent): `stock_ledger_entries` → `item_id, vendor_item_id, vendor_id, location_id, bucket_key`; `stock_balances`, `stock_lots`, `opening_balance_lines` → `item_id, vendor_item_id, location_id, bucket_key`; `procurement_requirements` → `vendor_item_id, item_id, location_id, required_quantity`; `vendor_reconciliation_lines` → `item_id`; `procurement_document_lines` → `document_no`. Providers (present): `stock_bucket_id` on the four ledger-family tables, `procurement_reference_id` on `procurement_document_lines`, `work_order_no` on `material_issues`, `is_backcharge` on `material_issue_lines`. Also `test_table_exists` for `stock_buckets`, `procurement_references`. Use `Schema::hasColumn` / `Schema::hasTable`.
- [ ] **Step 3: Run** `php artisan test --compact --filter=SchemaNormalizationTest`. Expected: FAIL on every row.
- [ ] **Step 4: Commit** the test with message `test: add schema normalization guard`.

### Task 2: `stock_buckets` schema, guards, model, resolver

**Files:**
- Modify: `database/migrations/2026_10_29_002200_create_inventory_ledger_tables.php`
- Create: `app/Models/StockBucket.php`, `app/Actions/Inventory/ResolveStockBucket.php`, `tests/Feature/StockBucketSchemaTest.php`, `tests/Support/InteractsWithStockBuckets.php`
- Delete (at end of Task 3, once unused): `app/Actions/Inventory/BuildStockBucket.php`

**Interfaces:**
- Produces: table `stock_buckets(id uuid pk, item_id uuid null, vendor_item_id uuid null, location_id uuid, timestamps)`, `unique(item_id, location_id)`, `unique(vendor_item_id, location_id)`.
- Produces: `StockBucket` (HasUuids; fillable `item_id, vendor_item_id, location_id`) with relations `item()`, `vendorItem()`, `location()`, `balance(): HasOne`; `resolvedItem(): ?Item` (vendorItem's item, else item); `vendorId(): ?string`; local scope `scopeWhereItem(Builder $query, Closure $constraint): Builder` (`whereHas('item', $c)` OR `whereHas('vendorItem.item', $c)`).
- Produces: `ResolveStockBucket::handle(Item $item, ?VendorItem $vendorItem, Location $location): StockBucket` — same validation and messages as old `BuildStockBucket` (active item/location, STOCK needs active `StockItemLocation`, CONSIGNMENT needs active vendor item and Vendor, location equals `vendorItem->location_id` or a bucket row already exists), then `firstOrCreate` the row.
- Produces: `InteractsWithStockBuckets::bucketFor(Item $item, ?VendorItem $vendorItem, Location $location): StockBucket` — raw `firstOrCreate` without business validation, for tests that assert trigger rejections.

- [ ] **Step 1: Write failing tests** in `StockBucketSchemaTest`: `test_bucket_requires_exactly_one_of_item_or_vendor_item` (both null and both set rejected), `test_stock_bucket_item_must_be_stock_ownership`, `test_consignment_bucket_vendor_item_must_be_consignment`, `test_duplicate_item_location_rejected`, `test_duplicate_vendor_item_location_rejected`, `test_bucket_identity_update_and_delete_rejected`, `test_resolver_creates_once_and_returns_same_row`, `test_resolver_rejects_inactive_item_and_foreign_location` (messages as in old action).
- [ ] **Step 2: Run** `php artisan test --compact --filter=StockBucketSchemaTest` and the MySQL equivalent. Expected: FAIL (table missing).
- [ ] **Step 3: Create `stock_buckets` at the top of `up()` and add its guards** (shape/ownership BEFORE INSERT/UPDATE; identity-immutable BEFORE UPDATE; BEFORE DELETE reject), reusing the file's `guard()` helper; add `stock_buckets` to `down()`.
- [ ] **Step 4: Implement `StockBucket` and `ResolveStockBucket`**; port the rules from `BuildStockBucket` (read it first).
- [ ] **Step 5: Run both engines for `StockBucketSchemaTest`.** Expected: PASS. The rest of the suite is red until Task 3; do not run it yet.
- [ ] **Step 6: Commit** `feat: add stock bucket identity table`.

### Task 3: Ledger family cutover to `stock_bucket_id`

**Files:**
- Modify: `database/migrations/2026_10_29_002200_...` (ledger entries, balances, lots), `2026_10_29_002300_create_opening_balance_tables.php` (lines), `app/Models/{StockLedgerEntry,StockBalance,StockLot,OpeningBalanceLine,VendorItem}.php`, `app/Actions/Inventory/{PostStock,ReverseStockTransaction}.php`, `app/Actions/OpeningBalance/{SaveOpeningBalanceBatch,ActivateOpeningBalance}.php`, `app/Queries/{SystemStockQuery,TransactionRecapQuery,VendorReconciliationSourceQuery}.php`, `app/Actions/Monitoring/GenerateShiftRecap.php`, `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/Monitoring/SystemStockController.php`, Vue pages under `resources/js/Pages` that read bucket fields
- Test: update every existing test that builds or reads these rows (list below)

**Interfaces:**
- Consumes: Task 2 `StockBucket`, `ResolveStockBucket`, `InteractsWithStockBuckets`.
- Produces: `stock_ledger_entries`, `stock_balances`, `stock_lots`, `opening_balance_lines` each have `stock_bucket_id` (FK `stock_buckets`, restrict) and no item/vendor_item/location/bucket_key/vendor_id columns. `stock_balances` has `unique(stock_bucket_id)`; ledger index `(stock_bucket_id, created_at)`; lots index `(stock_bucket_id, received_at, id)`. Models gain `bucket(): BelongsTo`; `item()/vendorItem()/location()/vendor()` relations are removed from ledger models in favor of `bucket.…`. The old bucket/vendor-snapshot triggers are dropped; precision, sign, immutability and REPLACE-protection triggers stay.
- `PostStock::handle(StockPostingData, StockDirection, ?InventoryPeriod): StockTransaction` signature unchanged.

- [ ] **Step 1: Update tests first.** Replace direct `item_id/vendor_item_id/location_id/bucket_key` construction with `bucketFor(...)` + `stock_bucket_id` in: `InventoryLedgerSchemaTest`, `StockPostingTest` (line 155 uses a bucket-key lookup → look up via bucket), `VendorRefillReceiptTest` (line 87), `VendorRefillWorkflowTest` (lines 47, 55), `MaterialIssueWorkflowTest` (line 63 → `StockBalance::whereHas('bucket', fn ($q) => $q->where('vendor_item_id', $relation->id))`), `MySqlStockConcurrencyTest`, `UuidInfrastructureTest`. Add to `StockPostingTest`: `test_first_posting_creates_exactly_one_bucket`, `test_consignment_posting_at_non_default_location_with_existing_bucket_succeeds`, `test_reversal_uses_original_bucket_after_default_location_changed`. Add to `MySqlStockConcurrencyTest`: `test_concurrent_first_postings_create_one_bucket`. Add to `SystemStockPageTest` (find the existing monitoring page test file; create `tests/Feature/SystemStockVendorScopeTest.php` if none): `test_vendor_cannot_open_other_vendor_bucket_detail`.
- [ ] **Step 2: Run** `php artisan test --compact --filter="InventoryLedgerSchema|StockPosting|SchemaNormalizationTest"`. Expected: FAIL.
- [ ] **Step 3: Edit the two migrations** (columns, indexes, trigger removal per Interfaces). Remove `bucketColumns()`/`$validBucket` bucket-key logic; keep one `integrityGuards()`.
- [ ] **Step 4: Update models and `PostStock`/`ReverseStockTransaction`.** In `PostStock` replace the bucket array with `$bucket = $this->buckets->handle($item, $vendorItem, $location)` (inject `ResolveStockBucket`), lock/create the balance by `stock_bucket_id`, create ledger entries and lots with `stock_bucket_id` only, and allocate lots by `stock_bucket_id`. The inventory period lock still serializes first-time bucket creation. In `ReverseStockTransaction` copy `stock_bucket_id` from the original entry.
- [ ] **Step 5: Update opening balance actions**, duplicate-bucket detection keyed by bucket id; `opening_balance_lines` get `unique(opening_balance_batch_id, stock_bucket_id)`.
- [ ] **Step 6: Update readers.** Run `grep -rnE "bucket_key|->item_id|->vendor_item_id|->location_id|->vendor_id|'item'|'vendorItem'|'location'" app resources/js` limited to the files above; every hit on a ledger-family row must go through `bucket`. Vendor filters become `whereHas('bucket.vendorItem', fn ($q) => $q->where('vendor_id', …))`; item filters use `StockBucket::whereItem`. `VendorReconciliationSourceQuery` groups by `stock_bucket_id` and eager-loads `bucket.vendorItem`; its returned array keys stay identical. Order `SystemStockQuery` by the item code via join, not `bucket_key`. Delete `BuildStockBucket`.
- [ ] **Step 7: Run both full suites and Pint.** Expected: all green (SQLite 244+ passed, MySQL OK).
- [ ] **Step 8: Run** `npm run build` and `npm run test:browser`. Expected: build OK, all specs pass.
- [ ] **Step 9: Commit** `refactor: reference stock buckets from ledger, balances, lots and opening lines`.

### Task 4: Line tables stop repeating parent facts

**Files:**
- Modify: `database/migrations/2026_10_30_000300_create_procurement_tables.php` (requirements), `2026_10_30_000400_create_monitoring_reconciliation_tables.php` (reconciliation lines), `2026_10_30_000300_create_manual_receipt_tables.php`, `2026_10_29_002400_create_stock_adjustments_table.php`, models `ProcurementRequirement`, `VendorReconciliationLine`, `ManualReceiptLine`, `StockAdjustment`, actions `CreateProcurementRequirement`, `SaveProcurementReference`, `PostManualReceipt`, `CreateStockAdjustment`, `PostStockAdjustment`, controllers `ProcurementController`, `StockCorrectionController`, reconciliation generator/exports/PDF that read `item_id`, and affected Vue props
- Test: `ProcurementWorkflowTest`, `MaterialIssueWorkflowTest`, `ManualDataInTest`, `StockCorrectionTest`, `MonitoringSchemaTest`, reconciliation tests, `SchemaNormalizationTest`

**Interfaces:**
- Produces: `procurement_requirements(id, row_key unique, material_issue_line_id unique, status, timestamps)` — `vendor_item_id`, `item_id`, `location_id`, `required_quantity` removed. `ProcurementRequirement::issueLine(): BelongsTo`, `vendorItem(): HasOneThrough` (via `MaterialIssueLine`, so the dashboard's `whereHas('vendorItem', …)` keeps working), `requiredQuantity(): int` returning the issue line's `issued_quantity`, `item(): Item` through `issueLine.requestLine.item`.
- Produces: `vendor_reconciliation_lines.item_id` removed; item via `vendorItem.item`.
- Produces: on `manual_receipt_lines` and `stock_adjustments`, `item_id` is NULL whenever `vendor_item_id` is set and required when it is not (guard trigger, same helper style as Task 2). Both models get `resolvedItem(): ?Item` (vendorItem's item, else item) and every reader/poster uses it.

- [ ] **Step 1: Write failing tests:** `ProcurementWorkflowTest::test_required_quantity_comes_from_issue_line`, `::test_vendor_cannot_open_other_vendor_requirement`, `ManualDataInTest::test_consignment_receipt_line_rejects_item_id_and_stock_line_requires_it`, `StockCorrectionTest::test_adjustment_item_resolves_from_vendor_item_for_consignment`; update fixtures that set the removed columns (e.g. `ProcurementWorkflowTest` line 64, `StockCorrectionTest::adjustmentData`).
- [ ] **Step 2: Run** the filtered tests on SQLite. Expected: FAIL.
- [ ] **Step 3: Edit migrations, models, actions, controllers** per Interfaces. `SaveProcurementReference` compares against `$locked->requiredQuantity()`. Keep `CreateProcurementRequirement` creating only `row_key`, `material_issue_line_id`, `status`.
- [ ] **Step 4: Fix readers:** `grep -rnE "required_quantity|->item_id|item_id" app/Http app/Actions/Reconciliation app/Services resources/js/Pages/{Procurement,Monitoring,Inventory,Receipts}` — each hit on these four models uses the new accessors.
- [ ] **Step 5: Run both full suites, Pint, `npm run build`.** Expected: all green; `SchemaNormalizationTest` rows for these tables pass.
- [ ] **Step 6: Commit** `refactor: derive item, location and required quantity from parent rows`.

### Task 5: Procurement reference headers

**Files:**
- Modify: `database/migrations/2026_10_30_000300_create_procurement_tables.php`, `app/Models/ProcurementDocumentLine.php`, `app/Actions/Procurement/{SaveProcurementReference,ApplyProcurementImport}.php`, `app/Services/Procurement/WorkbookReader.php` and exporters/Vue that read `document_no`
- Create: `app/Models/ProcurementReference.php`
- Test: `tests/Feature/ProcurementWorkflowTest.php`

**Interfaces:**
- Produces: `procurement_references(id uuid pk, stage enum RESERVATION|PR|PO|GRGI, document_no string(100), timestamps)`, `unique(stage, document_no)`. `procurement_document_lines.document_no` replaced by `procurement_reference_id` (FK, restrict); existing unique `proc_doc_line_version_unique` and all other columns unchanged.
- Produces: `ProcurementReference` (HasUuids, fillable `stage, document_no`, `lines(): HasMany`); `ProcurementDocumentLine::reference(): BelongsTo` plus accessor `document_no` (read-only, from the reference) so exporters and Vue props keep their key names.
- `SaveProcurementReference::handle(ProcurementRequirement, string $stage, array $lines): ProcurementRequirement` signature unchanged; it `firstOrCreate`s the header per line. No stored GRGI→PO parent: coverage is already bounded by the same requirement's active PO quantity.

- [ ] **Step 1: Write failing tests:** `test_same_document_number_across_requirements_reuses_one_header`, `test_reupload_replaces_active_lines_without_new_headers_for_unchanged_numbers`, `test_document_number_accessor_matches_exports`.
- [ ] **Step 2: Run** filtered tests. Expected: FAIL.
- [ ] **Step 3: Edit migration, models and actions** per Interfaces.
- [ ] **Step 4: Run both full suites, Pint, build, browser.** Expected: all green.
- [ ] **Step 5: Commit** `refactor: store procurement document numbers once in reference headers`.

### Task 6: Work Order number and Backcharge flag on Data OUT

**Files:**
- Modify: `database/migrations/2026_10_30_000200_create_material_request_tables.php`, `app/Models/{MaterialIssue,MaterialIssueLine}.php`, `app/Actions/MaterialRequests/CreateMaterialIssue.php`, `app/Http/Controllers/MaterialRequestController.php` (`issue()` validation, `show()` props), `resources/js/Pages/MaterialRequests/Show.vue`, monitoring transaction recap query/export that lists issues
- Test: `tests/Feature/MaterialIssueWorkflowTest.php`, `tests/Browser/` spec for the Data OUT form if one exists (else add `tests/Browser/data-out.spec.js` reusing `tests/Browser/prepare.php` fixtures)

**Interfaces:**
- Produces: `material_issues.work_order_no` string(50) nullable; `material_issue_lines.is_backcharge` boolean default false (not part of the unique `mr_issue_line_bucket_unique`).
- Produces: `CreateMaterialIssue::handle(MaterialRequest $request, User $warehouse, string $receiver, CarbonImmutable $issuedAt, array $lines, string $idempotencyKey, ?string $workOrderNo = null): MaterialIssue`; each `$lines` item accepts optional `is_backcharge: bool`. `workOrderNo` is trimmed; empty string becomes null.
- Request validation: `work_order_no` `nullable|string|max:50`; `lines.*.is_backcharge` `nullable|boolean`.

- [ ] **Step 1: Write failing tests:** `test_issue_stores_trimmed_work_order_and_backcharge_flags`, `test_blank_work_order_is_stored_as_null`, `test_over_length_work_order_is_rejected_with_field_error`, `test_non_boolean_backcharge_is_rejected`, `test_work_order_and_backcharge_appear_in_issue_detail_and_recap_export`.
- [ ] **Step 2: Run** filtered tests. Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces; the form gets a "Nomor WO" input and a per-line "Backcharge" checkbox using the existing form components and labels in Indonesian; detail and recap show both.
- [ ] **Step 4: Run both full suites, Pint, build and browser.** Expected: all green.
- [ ] **Step 5: Commit** `feat: record work order number and backcharge flag on Data OUT`.

### Task 7: Demo data seeder

**Files:**
- Create: `database/seeders/DemoDataSeeder.php`, `tests/Feature/DemoDataSeederTest.php`
- Modify: `database/seeders/DatabaseSeeder.php` (call it only when `app()->environment('local')`)

**Interfaces:**
- Produces: `DemoDataSeeder::run(): void` — aborts when `app()->isProduction()`; creates Admin, Warehouse and Vendor users (documented demo passwords in the seeder docblock), two Vendors with PIC, units, locations, categories, Stock and Consignment items with vendor pairs, a DRAFT→ACTIVE opening balance through `SaveOpeningBalanceBatch` and `ActivateOpeningBalance`, one Vendor Refill with a partial receipt through `CreateVendorRefill`/`CreateRefillShipment`/`PostRefillReceipt`, and one approved Material Request issued with a WO number and a Backcharge line through `CreateMaterialIssue`. It uses actions, never raw inserts, so ledger invariants hold.

- [ ] **Step 1: Write failing test:** `test_seeder_creates_consistent_demo_data` (balances equal the sum of ledger entries per bucket, a procurement requirement exists for the consignment issue line, `work_order_no` set) and `test_seeder_refuses_in_production`.
- [ ] **Step 2: Run** `php artisan test --compact --filter=DemoDataSeederTest`. Expected: FAIL.
- [ ] **Step 3: Implement the seeder**; reuse `MasterDataSeeder` for base master data where it already covers it.
- [ ] **Step 4: Run both full suites and Pint.** Expected: green.
- [ ] **Step 5: Commit** `feat: add demo data seeder`.

### Task 8: Final verification, bug sweep, review, database reset

- [ ] **Step 1: Full gate on the worktree:** `php artisan test --compact`; `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml --no-progress`; `php vendor/bin/pint --test`; `npm run build`; `npm run test:browser`. Expected: every command green; record the actual counts.
- [ ] **Step 2: Bug sweep.** `grep -rnE "bucket_key|BuildStockBucket" app database tests resources` must return nothing. `grep` for each removed column from `SchemaNormalizationTest` across `app resources/js` and read every remaining hit. Run `php artisan route:list --except-vendor` and open each list/detail/export endpoint once as Admin, Warehouse and Vendor against the seeded database in a scratch MySQL schema (not `consignment`); fix any 500 by root-causing it first.
- [ ] **Step 3: Review.** Run `/code-review high` on `git diff main...HEAD` limited to this plan's commits, and confirm the eight requested parts and three roles against `docs/superpowers/specs/2026-10-29-consignment-operations-design.md`. Fix findings, rerun Step 1.
- [ ] **Step 4: Reset the application database — only after the user explicitly approves in that message.** Back up `consignment` with `mysqldump`, then run `php artisan migrate:fresh --seed --seeder=DemoDataSeeder` in the worktree with `.env` pointing at `consignment`. Expected: migrations all `DONE`, seeder completes, login works for the three demo roles.
- [ ] **Step 5: Update docs.** Edit `docs/master-data-database.md` (stock bucket model, removed columns, new fields) and `README.md` (demo seeding command, demo accounts); commit `docs: describe normalized schema and demo seeding`.
