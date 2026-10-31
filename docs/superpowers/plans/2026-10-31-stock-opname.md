# Stock Opname Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin creates a Stock Opname session, Warehouse counts blind, differences freeze their bucket and resolve through the existing Koreksi Stok, and a printable report is produced.

**Architecture:** Two new tables (`stock_opname_sessions`, `stock_opname_lines`) reference `stock_buckets`; difference and BALANCE/SHORT/OVER are derived. Freeze is derived from open difference lines and enforced inside `PostStock` and `ReverseStockTransaction` after the balance lock. Resolution reuses `CreateStockAdjustment` / `ReviewStockAdjustment` / `PostStockAdjustment`; the movement log is derived from `stock_ledger_entries`.

**Tech Stack:** Laravel 13, Inertia 3, Vue 3 (`<script setup>`), MySQL + SQLite, PHPUnit, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-31-stock-opname-design.md`

## Global Constraints

- Both engines green: SQLite `:memory:` (`php artisan test`) and MySQL `consignment_testing` (`DB_CONNECTION=mysql DB_DATABASE=consignment_testing`). Concurrency tests run alone with `STOCK_POSTING_MYSQL_ISOLATED=1`.
- UUID primary keys (`HasUuids`), quantities are `decimal(18,4)` strings, arithmetic with `bcmath` (scale 4).
- 3NF: item, Vendor and location are read through `stock_buckets`; no repeated parent facts (`SchemaNormalizationTest` must stay green).
- Roles: admin creates / approves / recounts / closes / cancels / prints; Warehouse counts and submits; Vendor gets 403 everywhere.
- Warehouse responses (props and body) never contain `system_quantity` or a difference while the session is `COUNTING`.
- Every state change calls `WriteAuditLog::handle(User, string $action, Model, array $before, array $after, ?string $reason)`.
- UI copy in Indonesian; screens follow `modern-soft-ui` and existing components (`PageBanner`, `StatusBadge`, `surface-card`, `data-table`). Never run `migrate:fresh` against `consignment` without approval.
- Branch: `feature/stock-opname`. Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

1. A posting racing a count submit (MySQL): the posting must either land before the snapshot or be rejected, never after the freeze (Task 6).
2. Warehouse must not learn system quantity through any prop, URL, export, or the report route (Task 3, Task 5).
3. A rejected resolving adjustment must leave the bucket frozen and recountable, not silently unfrozen (Task 4).
4. Cancelling or completing a session must unfreeze exactly its own buckets, and a bucket in two open sessions stays frozen until both clear (Task 2).
5. Reversing a transaction on a frozen bucket must be refused, and only the line's own adjustment may post to it (Task 2).

---

### Task 1: Schema, enums and models

**Files:**
- Create: `database/migrations/2026_10_31_000100_create_stock_opname_tables.php`
- Create: `app/Enums/StockOpnameStatus.php`, `app/Enums/StockOpnameScope.php`
- Create: `app/Models/StockOpnameSession.php`, `app/Models/StockOpnameLine.php`
- Test: `tests/Feature/StockOpnameSchemaTest.php`

**Interfaces:**
- Produces: `StockOpnameStatus` (`Counting='COUNTING'`, `Counted='COUNTED'`, `Resolving='RESOLVING'`, `Completed='COMPLETED'`, `Cancelled='CANCELLED'`; `isOpen(): bool` true for the first three). `StockOpnameScope` (`Full='FULL'`, `Partial='PARTIAL'`, `Cycle='CYCLE'`).
- `StockOpnameSession`: columns `session_no` (unique string 40), `scope_type`, `cycle_month` (string 7, nullable), `status`, `created_by`, `counted_by` (nullable), `approved_by` (nullable), `submitted_at`, `approved_at`, `completed_at`, `note` (nullable); relations `lines()`, `creator()`, `counter()`, `approver()`.
- `StockOpnameLine`: `stock_opname_session_id`, `stock_bucket_id`, `counted_quantity` (nullable), `system_quantity` (nullable), `counted_by` (nullable), `counted_at` (nullable), `stock_adjustment_id` (nullable); unique `(stock_opname_session_id, stock_bucket_id)`; relations `session()`, `bucket()`, `adjustment()`, `counter()`; method `difference(): ?string` (bcsub counted - system, null until both set); `state(): ?string` (`BALANCE` / `SHORT` / `OVER` / null).

- [ ] **Step 1: Write failing tests** — a bucket cannot appear twice in one session (unique violation); `session_no` is unique; `difference()` returns `'-100.0000'` for counted 200 / system 300 and `state()` is `SHORT`; counted 5 / system 3 gives `OVER`; equal gives `BALANCE`; null until snapshot.
- [ ] **Step 2: Run** `php artisan test --filter=StockOpnameSchemaTest` — Expected: FAIL (tables/classes missing).
- [ ] **Step 3: Implement** the migration (foreign keys to `users`, `stock_buckets`, `stock_adjustments`; `down()` drops lines then sessions), enums and models. Follow `2026_10_29_002400_create_stock_adjustments_table.php` for style.
- [ ] **Step 4: Run** the new test plus `--filter=SchemaNormalizationTest` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: add stock opname tables and models`.

### Task 2: Freeze service and posting hooks

**Files:**
- Create: `app/Actions/Inventory/StockOpnameFreeze.php`
- Modify: `app/Actions/Inventory/PostStock.php` (after the bucket balance row is locked, before the quantity check), `app/Actions/Inventory/ReverseStockTransaction.php` (after the balance lock at the loop that locks balances)
- Test: `tests/Feature/StockOpnameFreezeTest.php`

**Interfaces:**
- Consumes: Task 1 models.
- Produces: `StockOpnameFreeze::assertNotFrozen(string $stockBucketId, ?string $allowedAdjustmentId = null): void` throws `ValidationException` (key `stock`, message "Barang sedang dibekukan oleh Stock Opname <session_no> sampai selisih diselesaikan.") when the bucket has a frozen line. `frozenBucketIds(): Collection<string>` for listing. A line is frozen when its session `status->isOpen()`, `counted_quantity` and `system_quantity` are set, `difference() != 0`, and its adjustment is not `POSTED`. `PostStock` passes `$data->sourceId` as `$allowedAdjustmentId` only when `$data->type === StockTransactionType::Adjustment`; the line owning that adjustment is exempt.

- [ ] **Step 1: Write failing tests** — a SHORT line (open session) makes `PostStockOut`/`PostStockIn` on that bucket throw; a BALANCE line and a line without `system_quantity` do not freeze; posting the line's own approved adjustment succeeds; a different adjustment to the same bucket is refused; `ReverseStockTransaction` on a transaction touching the frozen bucket is refused; a `COMPLETED` or `CANCELLED` session does not freeze; a bucket frozen by two open sessions stays frozen after one is cancelled.
- [ ] **Step 2: Run** `--filter=StockOpnameFreezeTest` — Expected: FAIL.
- [ ] **Step 3: Implement** the service (single query joining lines and sessions, left join adjustments) and call it from both hooks, after the balance lock so a racing count submit is serialized.
- [ ] **Step 4: Run** `--filter=StockOpnameFreezeTest` then the full suite — Expected: PASS, no regressions in `StockPostingTest`/`StockCorrectionTest`.
- [ ] **Step 5: Commit** `feat: freeze stock buckets with an open opname difference`.

### Task 3: Session lifecycle actions (create, count, submit)

**Files:**
- Create: `app/Actions/Inventory/CreateStockOpnameSession.php`, `RecordOpnameCount.php`, `SubmitOpnameCount.php`
- Test: `tests/Feature/StockOpnameCountTest.php`

**Interfaces:**
- Consumes: Tasks 1-2.
- Produces:
  - `CreateStockOpnameSession::handle(array $data, User $admin): StockOpnameSession` — `$data`: `scope_type`, `cycle_month` (required for `CYCLE`, format `Y-m`), `note`, `bucket_ids` (required for `PARTIAL`/`CYCLE`; ignored for `FULL`, which takes every bucket with `quantity > 0`). Number format `SO-YYYYMMDD-NNN` (per-day sequence). Zero buckets is a validation error. Admin only. Status `COUNTING`.
  - `RecordOpnameCount::handle(StockOpnameLine $line, string $quantity, User $warehouse): StockOpnameLine` — Warehouse only, session `COUNTING`, quantity `>= 0` with at most 4 decimals; sets `counted_quantity`, `counted_by`, `counted_at`.
  - `SubmitOpnameCount::handle(StockOpnameSession $session, User $warehouse): StockOpnameSession` — every line counted; in one transaction lock each bucket's `StockBalance` row (`lockForUpdate`), set `system_quantity` only on lines where it is null, set `counted_by`/`submitted_at`, status `COUNTED`.

- [ ] **Step 1: Write failing tests** — admin creates `FULL` (only positive-balance buckets) and `PARTIAL`; Warehouse cannot create; `CYCLE` without `cycle_month` fails; Warehouse records counts, admin and Vendor cannot; negative or 5-decimal quantity rejected; submit with an uncounted line fails; submit snapshots the current balance into `system_quantity` and a later posting does not change it; every action writes an audit row.
- [ ] **Step 2: Run** `--filter=StockOpnameCountTest` — Expected: FAIL.
- [ ] **Step 3: Implement** the three actions.
- [ ] **Step 4: Run** `--filter=StockOpnameCountTest` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: create, count and submit stock opname sessions`.

### Task 4: Resolution actions (approve, recount, close, cancel)

**Files:**
- Create: `app/Actions/Inventory/ApproveStockOpname.php`, `ReopenOpnameLine.php`, `CloseStockOpname.php`, `CancelStockOpname.php`
- Create: `app/Actions/Inventory/OpnameMovementLog.php`
- Test: `tests/Feature/StockOpnameResolutionTest.php`

**Interfaces:**
- Consumes: Tasks 1-3, `CreateStockAdjustment::handle(array, User): StockAdjustment`.
- Produces:
  - `ApproveStockOpname::handle(StockOpnameSession $session, User $admin): StockOpnameSession` — requires `COUNTED`; for every line with a non-zero difference and no active adjustment, create a draft adjustment through `CreateStockAdjustment` (`item_id` / `vendor_item_id` / `location_id` read from the bucket, direction `IN` for OVER and `OUT` for SHORT, quantity the absolute difference, reason `Selisih Stock Opname <session_no>`) and store `stock_adjustment_id`; set `approved_by`/`approved_at`; status `RESOLVING`, or `COMPLETED` straight away when there is no difference.
  - `ReopenOpnameLine::handle(StockOpnameLine $line, User $admin): StockOpnameLine` — only when the line's adjustment is `REJECTED` and the session is `RESOLVING`; clears count, snapshot and adjustment link, session back to `COUNTING`; the bucket stays frozen only while another difference remains (the cleared line has no snapshot, so it is unfrozen until recounted — recorded as a ruling).
  - `CloseStockOpname::handle(StockOpnameSession $session, User $admin): StockOpnameSession` — requires `RESOLVING` and every difference line's adjustment `POSTED`; status `COMPLETED`, `completed_at`.
  - `CancelStockOpname::handle(StockOpnameSession $session, User $admin, string $reason): StockOpnameSession` — allowed in `COUNTING`/`COUNTED` only; reason required.
  - `OpnameMovementLog::forLine(StockOpnameLine $line): Collection` — ledger entries on the line's bucket whose transaction `posted_at` is after `counted_at`, excluding the session's own adjustment transactions; each row has `transaction_no`, `type`, `quantity`, `posted_at`.

- [ ] **Step 1: Write failing tests** — approving a SHORT 100 line creates a `DRAFT` adjustment `OUT 100.0000` with the stated reason and the session is `RESOLVING`; an OVER line creates `IN`; no differences completes the session immediately; Warehouse cannot approve; the full path (approve, review, post the adjustment, close) leaves the bucket balance equal to the counted quantity and unfrozen; close before the adjustment is posted fails; a rejected adjustment leaves the line recountable via `ReopenOpnameLine` and a re-submit with a new count works; cancel with and without reason; the movement log lists a posting made after `counted_at` and omits the session's own adjustment.
- [ ] **Step 2: Run** `--filter=StockOpnameResolutionTest` — Expected: FAIL.
- [ ] **Step 3: Implement** the actions and the log query.
- [ ] **Step 4: Run** `--filter=StockOpnameResolutionTest` plus the full suite — Expected: PASS.
- [ ] **Step 5: Commit** `feat: approve, recount, close and cancel stock opname`.

### Task 5: Routes, policy, controller and screens

**Files:**
- Create: `app/Policies/StockOpnamePolicy.php`, `app/Http/Controllers/Inventory/StockOpnameController.php`
- Modify: `routes/web.php` (after the corrections routes), `app/Http/Middleware/HandleInertiaRequests.php` (route `stockOpnameIndex`, label `'stock-opname' => 'Stock Opname'`), `resources/js/Components/AppSidebar.vue` (entry under "Master & Tools", internal only)
- Create: `resources/js/Pages/Inventory/Opname/Index.vue`, `Create.vue`, `Show.vue`
- Test: `tests/Feature/StockOpnameWebTest.php`

**Interfaces:**
- Consumes: Tasks 1-4 actions.
- Produces routes (names `stock-opname.*`): `GET /inventory/opname` index (non-Vendor), `GET /inventory/opname/create` and `POST /inventory/opname` (admin), `GET /inventory/opname/{session}` show, `PUT /inventory/opname/{session}/lines/{line}/count` and `POST .../submit` (Warehouse), `POST .../approve`, `POST .../close`, `POST .../cancel`, `POST .../lines/{line}/recount` (admin). `whereUuid` on every parameter.
- `Show` props by role: Warehouse during `COUNTING` receives lines with `id`, `item`, `vendor`, `location`, `counted_quantity` only. Admin receives `system_quantity`, `difference`, `state`, adjustment status, the movement log per line, and the action URLs.

- [ ] **Step 1: Write failing tests** — Vendor gets 403 on every route; Warehouse show during `COUNTING` has no `system_quantity` or `difference` key in the Inertia props and the response body does not contain the seeded system quantity string; Warehouse gets 403 on create/approve/close/cancel/recount; admin gets 403 on the count and submit routes; admin show exposes system quantity, difference and state; the sidebar prop route exists for internal roles only.
- [ ] **Step 2: Run** `--filter=StockOpnameWebTest` — Expected: FAIL.
- [ ] **Step 3: Implement** policy, controller (thin: authorize, call the action, redirect with `success`) and the three pages using existing components; Warehouse screen is a count form with a mobile-friendly numeric input per line; admin screen shows the difference table with state badges, the movement log, and the approve/recount/close/cancel actions. Verify desktop, tablet and mobile in the browser.
- [ ] **Step 4: Run** `--filter=StockOpnameWebTest`, `npm run build`, and `vendor/bin/pint --test` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: add stock opname screens`.

### Task 6: Report and concurrency

**Files:**
- Create: `resources/js/Pages/Inventory/Opname/Report.vue`
- Modify: `StockOpnameController` (`report`), `routes/web.php` (`GET /inventory/opname/{session}/report`, admin only, name `stock-opname.report`)
- Modify: `tests/Feature/MySqlStockConcurrencyTest.php`, `tests/Support/stock-posting-worker.php` only if the existing worker cannot drive the new case
- Test: `tests/Feature/StockOpnameReportTest.php`

**Interfaces:**
- Consumes: Tasks 1-5, `users.position`.
- Produces: a printable page with print styles: session number, scope, creator / counter / approver with name and `position`, each line (item, vendor, location, system, actual, difference, state), and the movement log. Warehouse and Vendor get 403.

- [ ] **Step 1: Write failing tests** — admin report contains the session number, counter and approver names and positions, a SHORT line with its difference, and a post-count movement; Warehouse and Vendor get 403. MySQL (isolated): a posting racing `SubmitOpnameCount` on the same bucket either lands before the snapshot (and the snapshot includes it) or is rejected as frozen; it never changes the balance after the freeze.
- [ ] **Step 2: Run** `--filter=StockOpnameReportTest`; then the concurrency test alone with `STOCK_POSTING_MYSQL_ISOLATED=1` — Expected: FAIL.
- [ ] **Step 3: Implement** the report action and page.
- [ ] **Step 4: Run** both — Expected: PASS.
- [ ] **Step 5: Commit** `feat: add stock opname report and race coverage`.

### Task 7: Demo data, docs and browser check

**Files:**
- Modify: `database/seeders/DemoDataSeeder.php` (one `COUNTING` session through the actions, no counts entered), `docs/master-data-database.md`, `README.md`, `tests/Feature/DemoRouteSmokeTest.php` (the new pages for the three roles)
- Create: `tests/Browser/stock-opname.spec.js`
- Test: the existing `DemoDataSeederTest` plus the new spec

**Interfaces:**
- Consumes: Tasks 1-6.

- [ ] **Step 1: Write failing tests** — the seeder creates exactly one open session and stays idempotent; the smoke test visits the new pages for admin and Warehouse and gets 403 for Vendor; the Playwright spec drives: admin creates a session, Warehouse counts a short quantity and submits, admin approves, the bucket is blocked on Data OUT, admin approves and posts the Koreksi Stok, closes the session, and the report renders.
- [ ] **Step 2: Run** the three — Expected: FAIL.
- [ ] **Step 3: Implement** the seeder step, docs (tables, roles, freeze rule, decisions from the spec), and README demo notes.
- [ ] **Step 4: Run** the full suite on SQLite and MySQL, the isolated concurrency tests, `npm run build`, Pint, and `npx playwright test` — Expected: all green.
- [ ] **Step 5: Commit** `docs: describe stock opname and seed a demo session`.
