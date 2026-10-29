# Material Request and Data OUT Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow Warehouse to record external requester details, create mixed STOCK/CONSIGNMENT MRs, allocate available stock by oldest receipt, and post partial Data OUT without negative stock.

**Architecture:** MR records requested demand independently from physical issues. Each `material_issue_line` captures the actual ownership bucket selected at posting and delegates stock mutation to `PostStockOut`; later procurement uses that immutable issue line as its row-key source.

**Tech Stack:** PHP 8.3, Laravel 13, Inertia 3, Vue 3, MySQL row locking, PHPUnit 12, Tailwind CSS 4, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-29-consignment-operations-design.md`

## Global Constraints

- Execute after the inventory foundation; Refill is optional but useful for end-to-end fixture setup.
- MR may contain STOCK and CONSIGNMENT in one request; requester does not select Vendor.
- Data OUT is the only event in this module that reduces stock.
- No negative balance; every issue remains traceable to item, Vendor when applicable, location, lots, actor, and shift.
- Posted Data OUT is immutable; reversal uses the foundation action and restores MR issued quantity.
- Procurement documents may be outstanding and must not block an authorized urgent issue.

## Review Focus

- A mixed MR must never assign a Vendor to STOCK or omit Vendor from an issued CONSIGNMENT line; pin in Task 3.
- Repeated partial issue must never exceed requested quantity even under concurrent submissions; pin in Task 3/MySQL test.
- FIFO recommendation must ignore depleted lots, inactive placements, and other Vendors while still allowing Warehouse override; pin in Task 2.
- A failed line in a multi-line issue must roll back all ledger, allocation, and MR counters; pin in Task 3.
- Reversing an issue with procurement references must restore MR quantity and mark documents for review without deletion; pin in Task 4 and the procurement plan.

---

### Task 1: MR and issue schema with explicit state transitions

**Files:**
- Create: `database/migrations/2026_10_29_004000_create_material_request_tables.php`
- Create: `app/Enums/MaterialRequestStatus.php`
- Create: `app/Enums/MaterialIssueStatus.php`
- Create: `app/Models/MaterialRequest.php`
- Create: `app/Models/MaterialRequestLine.php`
- Create: `app/Models/MaterialIssue.php`
- Create: `app/Models/MaterialIssueLine.php`
- Test: `tests/Feature/MaterialRequestSchemaTest.php`

**Interfaces:**
- Produces: MR and issue relations with `remainingQuantity(): string` on request lines.
- Produces: immutable issue-line identity later consumed by `CreateProcurementRequirement`.

- [ ] **Step 1: Write failing schema/state tests**

  Assert UUIDs, unique MR/OUT numbers, positive requested/issued quantities, requester/department/reference fields, one issue line linked to one MR line and one ownership bucket, exact remaining calculation, and restricted deletion after issue.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/MaterialRequestSchemaTest.php`

  Expected: FAIL because schema/models are absent.

- [ ] **Step 3: Implement migration, enums, models, and guards**

  Keep `vendor_item_id` nullable only for STOCK and validate the invariant again in the issue action. Store `documents_outstanding` on each consignment issue line, default true until procurement completion.

- [ ] **Step 4: Rerun schema tests**

  Run: `php artisan test --compact tests/Feature/MaterialRequestSchemaTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add database/migrations/2026_10_29_004000_create_material_request_tables.php app/Enums/MaterialRequestStatus.php app/Enums/MaterialIssueStatus.php app/Models/MaterialRequest.php app/Models/MaterialRequestLine.php app/Models/MaterialIssue.php app/Models/MaterialIssueLine.php tests/Feature/MaterialRequestSchemaTest.php && git commit -m "feat: add material request schema"`

### Task 2: MR creation and FIFO allocation recommendations

**Files:**
- Create: `app/Actions/MaterialRequest/CreateMaterialRequest.php`
- Create: `app/Queries/IssueAllocationQuery.php`
- Create: `app/Http/Controllers/Transactions/MaterialRequestController.php`
- Create: `app/Http/Requests/Transactions/StoreMaterialRequest.php`
- Create: `app/Policies/MaterialRequestPolicy.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MaterialRequestTest.php`
- Test: `tests/Feature/IssueAllocationQueryTest.php`

**Interfaces:**
- Produces: `CreateMaterialRequest::handle(array $data, User $warehouse): MaterialRequest`.
- Produces: `IssueAllocationQuery::recommend(MaterialRequestLine $line, string $quantity): Collection<AllocationRecommendation>` ordered by oldest available lot, grouped into item–Vendor–location choices.

- [ ] **Step 1: Write failing MR and recommendation tests**

  Assert Warehouse/Admin can create mixed MR; requester name and department are required; inactive/serialized items fail; recommendation uses oldest positive lots, separates Vendors, excludes depleted/inactive buckets, and returns insufficient remainder explicitly instead of inventing stock.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/MaterialRequestTest.php tests/Feature/IssueAllocationQueryTest.php`

  Expected: FAIL with missing actions/query/routes.

- [ ] **Step 3: Implement creation action, policy, endpoint, and recommendation query**

  Use generated `MR-{YYYYMMDD}-{SEQUENCE}` and lock only during final issue, not while displaying recommendations.

- [ ] **Step 4: Rerun targeted tests**

  Run: `php artisan test --compact tests/Feature/MaterialRequestTest.php tests/Feature/IssueAllocationQueryTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Actions/MaterialRequest/CreateMaterialRequest.php app/Queries/IssueAllocationQuery.php app/Http/Controllers/Transactions/MaterialRequestController.php app/Http/Requests/Transactions/StoreMaterialRequest.php app/Policies/MaterialRequestPolicy.php routes/web.php tests/Feature/MaterialRequestTest.php tests/Feature/IssueAllocationQueryTest.php && git commit -m "feat: add material request creation"`

### Task 3: Partial and atomic Data OUT posting

**Files:**
- Create: `app/Data/MaterialIssueLineData.php`
- Create: `app/Actions/MaterialRequest/PostMaterialIssue.php`
- Create: `app/Events/MaterialIssuePosted.php`
- Create: `app/Http/Controllers/Transactions/DataOutController.php`
- Create: `app/Http/Requests/Transactions/StoreDataOutRequest.php`
- Create: `app/Policies/MaterialIssuePolicy.php`
- Test: `tests/Feature/DataOutTest.php`
- Test: `tests/Feature/MySqlDataOutConcurrencyTest.php`

**Interfaces:**
- Consumes: foundation `PostStockOut::handle()` and Task 2 allocation recommendations.
- Produces: `MaterialIssueLineData(requestLineId, vendorItemId, locationId, quantity)`.
- Produces: `PostMaterialIssue::handle(MaterialRequest $request, array $lineData, array $header, User $warehouse, string $idempotencyKey): MaterialIssue`.
- Produces: after-commit `MaterialIssuePosted` event for Procurement.

- [ ] **Step 1: Write failing Data OUT tests**

  Assert partial issue increments issued quantity once; Warehouse may override recommended Vendor/location before posting; STOCK/CONSIGNMENT bucket invariants hold; insufficient stock/request remainder fails; a failing multi-line issue rolls back all changes; duplicate key is idempotent; urgent issue posts with document-outstanding flag.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/DataOutTest.php`

  Expected: FAIL because posting action/controller do not exist.

- [ ] **Step 3: Implement issue action with locked MR lines and stock posting**

  Generate `OUT-{YYYYMMDD}-{SEQUENCE}`. Re-fetch and lock MR lines, revalidate remaining quantity and current balance, then create the issue and shared OUT transaction in one database transaction.

- [ ] **Step 4: Verify normal and concurrent behavior**

  Run: `php artisan test --compact tests/Feature/DataOutTest.php`

  Run: `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml --filter MySqlDataOutConcurrencyTest`

  Expected: PASS; concurrent requests cannot over-issue MR or create negative stock.

- [ ] **Step 5: Commit**

  Run: `git add app/Data/MaterialIssueLineData.php app/Actions/MaterialRequest/PostMaterialIssue.php app/Events/MaterialIssuePosted.php app/Http/Controllers/Transactions/DataOutController.php app/Http/Requests/Transactions/StoreDataOutRequest.php app/Policies/MaterialIssuePolicy.php tests/Feature/DataOutTest.php tests/Feature/MySqlDataOutConcurrencyTest.php && git commit -m "feat: add partial data out posting"`

### Task 4: Data OUT reversal integration

**Files:**
- Create: `app/Listeners/MaterialRequest/RestoreIssueAfterReversal.php`
- Create: `app/Events/MaterialIssueReversed.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/DataOutReversalTest.php`

**Interfaces:**
- Consumes: foundation `StockTransactionReversed` event.
- Produces: after-commit listener that marks the issue reversed, decrements MR issued quantity, reopens MR status when appropriate, and emits `MaterialIssueReversed` for Procurement.

- [ ] **Step 1: Write failing reversal tests**

  Assert reversal restores request remainder and lot/balance exactly once; duplicate event handling is idempotent; partially fulfilled MR returns to PARTIAL, fully reversed MR returns to OPEN; the original issue remains readable and immutable.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/DataOutReversalTest.php`

  Expected: FAIL with missing listener.

- [ ] **Step 3: Implement and register the listener**

  Use a processed marker or issue status guard; do not delete issue lines. Dispatch downstream event only after the restoration transaction commits.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/DataOutReversalTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Listeners/MaterialRequest/RestoreIssueAfterReversal.php app/Events/MaterialIssueReversed.php app/Providers/AppServiceProvider.php tests/Feature/DataOutReversalTest.php && git commit -m "feat: restore material request on reversal"`

### Task 5: MR and Data OUT Inertia workflows

**Files:**
- Create: `resources/js/Pages/Transactions/MaterialRequests/Index.vue`
- Create: `resources/js/Pages/Transactions/MaterialRequests/Create.vue`
- Create: `resources/js/Pages/Transactions/MaterialRequests/Show.vue`
- Create: `resources/js/Pages/Transactions/DataOut/Create.vue`
- Create: `resources/js/Pages/Transactions/DataOut/Show.vue`
- Modify: `resources/js/Components/AppSidebar.vue`
- Test: `tests/Browser/material-request-data-out.spec.js`

**Interfaces:**
- Consumes: Tasks 2–4 endpoints and props.
- Produces: role-aware MR and Data OUT screens.

- [ ] **Step 1: Write failing browser flow**

  Cover Warehouse creating mixed MR, seeing Vendor-free requester form, accepting FIFO recommendation, overriding Vendor/location, posting partial issue, seeing remaining quantity and document-outstanding status, rejecting insufficient qty, and preventing duplicate submit.

- [ ] **Step 2: Run and confirm missing-page failure**

  Run: `npx playwright test tests/Browser/material-request-data-out.spec.js`

  Expected: FAIL because pages do not exist.

- [ ] **Step 3: Implement pages and role-aware navigation**

  Use server-supplied available balances and recommendation IDs, but always revalidate on submit. Show ownership and Vendor as separate labels; never ask requester to choose Vendor.

- [ ] **Step 4: Run module verification**

  Run: `php artisan test --compact tests/Feature/MaterialRequestSchemaTest.php tests/Feature/MaterialRequestTest.php tests/Feature/IssueAllocationQueryTest.php tests/Feature/DataOutTest.php tests/Feature/DataOutReversalTest.php && npm run build && npx playwright test tests/Browser/material-request-data-out.spec.js`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add resources/js/Pages/Transactions/MaterialRequests resources/js/Pages/Transactions/DataOut resources/js/Components/AppSidebar.vue tests/Browser/material-request-data-out.spec.js && git commit -m "feat: add material request and data out screens"`

