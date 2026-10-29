# Refill, Incoming Refill, and Data IN Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Vendor Refill recommendations, Vendor response and shipment, partial Incoming Refill with outstanding follow-up/close, and urgent manual Data IN without duplicate stock posting.

**Architecture:** Refill documents own their workflow state; only receipt and manual-receipt actions call the shared inventory posting interface. Vendor isolation is enforced in policies and query scopes, while quantity snapshots preserve why each refill was requested.

**Tech Stack:** PHP 8.3, Laravel 13, Inertia 3, Vue 3, PhpSpreadsheet 5.10 where export is needed, PHPUnit 12, Tailwind CSS 4, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-29-consignment-operations-design.md`

## Global Constraints

- Execute only after `2026-10-29-identity-inventory-foundation.md` is complete and verified.
- Use UUIDs and `DECIMAL(18,4)`; retain posted history and source snapshots.
- Target refill is editable Master Data; target 0 is excluded from automatic suggestions but may be manually requested.
- Receipt, not request/shipment, is the only Refill event that changes stock.
- Vendor access is always scoped by authenticated `vendor_id` on the backend.
- No QR, barcode, serialized tracking, or direct SAP integration.

## Review Focus

- Repeated suggestion generation must subtract all submitted/shipped/partially received outstanding quantities and never create duplicate automatic lines; pin in Task 2.
- A Vendor must not revise or ship another Vendor's request even with a forged UUID; pin in Task 3.
- Two retries of the same receipt must create one stock transaction and one received-quantity increment; pin in Task 4.
- Partial receipt must leave exact outstanding per line and closing it must increase cancelled, never received; pin in Task 4.
- Manual Data IN for CONSIGNMENT without a Vendor or for STOCK with a Vendor must fail atomically; pin in Task 5.

---

### Task 1: Refill workflow schema and state model

**Files:**
- Create: `database/migrations/2026_10_29_003000_create_refill_tables.php`
- Create: `app/Enums/RefillRequestStatus.php`
- Create: `app/Enums/RefillRevisionStatus.php`
- Create: `app/Enums/RefillShipmentStatus.php`
- Create: `app/Models/RefillRequest.php`
- Create: `app/Models/RefillRequestLine.php`
- Create: `app/Models/RefillRevision.php`
- Create: `app/Models/RefillShipment.php`
- Create: `app/Models/RefillShipmentLine.php`
- Create: `app/Models/RefillReceipt.php`
- Create: `app/Models/RefillReceiptLine.php`
- Test: `tests/Feature/RefillSchemaTest.php`

**Interfaces:**
- Produces: status enums and model relations used by all Refill actions.
- Produces: line helpers `outstandingQuantity(): string` and `isComplete(): bool`, calculated from approved, received, and cancelled quantities.

- [ ] **Step 1: Write failing schema/state tests**

  Assert UUIDs, unique business numbers, positive quantities, one Vendor per request, one line per vendor item per request, immutable snapshot fields, and allowed status transitions. Assert `approved - received - cancelled` returns exact four-decimal outstanding.

- [ ] **Step 2: Run and observe missing-schema failure**

  Run: `php artisan test --compact tests/Feature/RefillSchemaTest.php`

  Expected: FAIL because Refill tables/classes do not exist.

- [ ] **Step 3: Implement migration, enums, models, and transition guards**

  Do not expose generic status mass assignment; transitions are methods/actions in later tasks. Add restrictive FKs to preserve source history.

- [ ] **Step 4: Rerun schema tests**

  Run: `php artisan test --compact tests/Feature/RefillSchemaTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add database/migrations/2026_10_29_003000_create_refill_tables.php app/Enums/RefillRequestStatus.php app/Enums/RefillRevisionStatus.php app/Enums/RefillShipmentStatus.php app/Models/RefillRequest.php app/Models/RefillRequestLine.php app/Models/RefillRevision.php app/Models/RefillShipment.php app/Models/RefillShipmentLine.php app/Models/RefillReceipt.php app/Models/RefillReceiptLine.php tests/Feature/RefillSchemaTest.php && git commit -m "feat: add refill workflow schema"`

### Task 2: Refill recommendation and request creation

**Files:**
- Create: `app/Queries/RefillSuggestionQuery.php`
- Create: `app/Actions/Refill/CreateRefillRequest.php`
- Create: `app/Support/BusinessNumberGenerator.php`
- Create: `app/Http/Controllers/Refill/RefillRequestController.php`
- Create: `app/Http/Requests/Refill/StoreRefillRequest.php`
- Create: `app/Policies/RefillRequestPolicy.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/RefillSuggestionTest.php`
- Test: `tests/Feature/RefillRequestTest.php`

**Interfaces:**
- Produces: `RefillSuggestionQuery::forVendor(Vendor $vendor): Collection<RefillSuggestion>` where `suggested = max(0, target - current balance - outstanding)`.
- Produces: `CreateRefillRequest::handle(Vendor $vendor, array $lines, User $warehouse): RefillRequest`.
- Produces: collision-safe business number format `VRV-{VENDOR_CODE}-{YYYYMMDD}-{SEQUENCE}`.

- [ ] **Step 1: Write failing formula and creation tests**

  Cover stock below/equal/above target, target 0 exclusion, manual target-0 request, multiple Vendor targets calculated independently, outstanding statuses included/excluded, editable requested qty, stale suggestion revalidation, and repeat generation without duplicate automatic lines.

- [ ] **Step 2: Run and confirm formula/action failure**

  Run: `php artisan test --compact tests/Feature/RefillSuggestionTest.php tests/Feature/RefillRequestTest.php`

  Expected: FAIL with missing query/action/routes.

- [ ] **Step 3: Implement query, number generator, action, request, policy, and endpoints**

  Persist target/stock/outstanding/suggested snapshots at submit time. Warehouse and Admin may create; Vendor is read-only until submitted to that Vendor.

- [ ] **Step 4: Rerun targeted tests**

  Run: `php artisan test --compact tests/Feature/RefillSuggestionTest.php tests/Feature/RefillRequestTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Queries/RefillSuggestionQuery.php app/Actions/Refill/CreateRefillRequest.php app/Support/BusinessNumberGenerator.php app/Http/Controllers/Refill app/Http/Requests/Refill app/Policies/RefillRequestPolicy.php routes/web.php tests/Feature/RefillSuggestionTest.php tests/Feature/RefillRequestTest.php && git commit -m "feat: add refill recommendations"`

### Task 3: Vendor revision review and shipment

**Files:**
- Create: `app/Actions/Refill/ProposeRefillRevision.php`
- Create: `app/Actions/Refill/ReviewRefillRevision.php`
- Create: `app/Actions/Refill/SubmitRefillShipment.php`
- Create: `app/Http/Controllers/Refill/VendorRefillController.php`
- Create: `app/Http/Requests/Refill/ProposeRevisionRequest.php`
- Create: `app/Http/Requests/Refill/StoreShipmentRequest.php`
- Test: `tests/Feature/VendorRefillWorkflowTest.php`

**Interfaces:**
- Consumes: Task 1 models, Task 2 policy and business number generator.
- Produces: `ProposeRefillRevision::handle(RefillRequestLine $line, string $quantity, string $reason, User $vendorUser): RefillRevision`.
- Produces: `ReviewRefillRevision::handle(RefillRevision $revision, bool $accept, string $note, User $admin): RefillRequestLine`.
- Produces: `SubmitRefillShipment::handle(RefillRequest $request, array $data, User $vendorUser): RefillShipment`.

- [ ] **Step 1: Write failing Vendor isolation and transition tests**

  Assert only the owning Vendor can view/respond/ship; forged request and nested line IDs receive 404/403; revision requires reason and cannot exceed valid precision; only Admin accepts/rejects; accepted revision changes approved quantity while preserving prior values; shipment requires delivery reference, courier, date, and positive line quantities.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/VendorRefillWorkflowTest.php`

  Expected: FAIL because actions/endpoints do not exist.

- [ ] **Step 3: Implement actions and controllers with row locking**

  Revalidate request status inside each transaction. Never load a Vendor route through an unscoped model binding query.

- [ ] **Step 4: Rerun targeted tests**

  Run: `php artisan test --compact tests/Feature/VendorRefillWorkflowTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Actions/Refill app/Http/Controllers/Refill/VendorRefillController.php app/Http/Requests/Refill tests/Feature/VendorRefillWorkflowTest.php && git commit -m "feat: add vendor refill response"`

### Task 4: Partial Incoming Refill, follow-up, close, and stock posting

**Files:**
- Create: `app/Actions/Refill/ReceiveRefillShipment.php`
- Create: `app/Actions/Refill/CloseRefillOutstanding.php`
- Create: `app/Actions/Refill/FollowUpRefill.php`
- Create: `app/Listeners/Refill/RestoreReceiptAfterReversal.php`
- Create: `app/Http/Controllers/Refill/IncomingRefillController.php`
- Create: `app/Http/Requests/Refill/ReceiveRefillRequest.php`
- Test: `tests/Feature/IncomingRefillTest.php`

**Interfaces:**
- Consumes: Foundation `PostStockIn::handle()` and `StockTransactionReversed`.
- Produces: `ReceiveRefillShipment::handle(RefillShipment $shipment, array $data, User $warehouse, string $idempotencyKey): RefillReceipt`.
- Produces: `CloseRefillOutstanding::handle(RefillRequest $request, array $lineReasons, User $admin): RefillRequest`.

- [ ] **Step 1: Write failing receipt and outstanding tests**

  Assert partial receipt posts only actual quantity, multiple receipts accumulate, request stays partial, retry is idempotent, over-receipt fails atomically, follow-up records actor/time, only Admin closes remaining with per-line reason, close increments cancelled not received, and receipt reversal restores outstanding or requires audited reopen if closed.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/IncomingRefillTest.php`

  Expected: FAIL with missing actions/routes.

- [ ] **Step 3: Implement incoming actions and reversal listener**

  Use one DB transaction around locked refill lines and shared stock posting. Store one unique `stock_transaction_id` on each receipt and dispatch follow-up notification as an after-commit application event only; external messaging is out of scope.

- [ ] **Step 4: Rerun targeted tests**

  Run: `php artisan test --compact tests/Feature/IncomingRefillTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Actions/Refill app/Listeners/Refill app/Http/Controllers/Refill/IncomingRefillController.php app/Http/Requests/Refill/ReceiveRefillRequest.php tests/Feature/IncomingRefillTest.php && git commit -m "feat: add partial incoming refill"`

### Task 5: Urgent manual Data IN

**Files:**
- Create: `database/migrations/2026_10_29_003100_create_manual_receipt_tables.php`
- Create: `app/Models/ManualReceipt.php`
- Create: `app/Models/ManualReceiptLine.php`
- Create: `app/Actions/Inventory/CreateManualReceipt.php`
- Create: `app/Http/Controllers/Transactions/DataInController.php`
- Create: `app/Http/Requests/Transactions/StoreDataInRequest.php`
- Create: `app/Policies/ManualReceiptPolicy.php`
- Test: `tests/Feature/ManualDataInTest.php`

**Interfaces:**
- Consumes: Foundation `PostStockIn::handle()`.
- Produces: `CreateManualReceipt::handle(array $data, User $warehouse, string $idempotencyKey): ManualReceipt`.

- [ ] **Step 1: Write failing manual-receipt tests**

  Assert urgent reason and receiver are required; DO may be nullable but preserved; STOCK forbids Vendor and requires active stock location; CONSIGNMENT requires active vendor-item/location; mixed valid/invalid lines roll back; repeat submit posts once; Vendor cannot create; reversal retains the source and marks it reversed.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/ManualDataInTest.php`

  Expected: FAIL with absent tables/action.

- [ ] **Step 3: Implement schema, action, request, policy, and controller**

  Use a single header that may contain multiple lines but do not mix Vendors in one CONSIGNMENT manual receipt; split them in the UI before submission.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/ManualDataInTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add database/migrations/2026_10_29_003100_create_manual_receipt_tables.php app/Models/ManualReceipt.php app/Models/ManualReceiptLine.php app/Actions/Inventory/CreateManualReceipt.php app/Http/Controllers/Transactions/DataInController.php app/Http/Requests/Transactions/StoreDataInRequest.php app/Policies/ManualReceiptPolicy.php tests/Feature/ManualDataInTest.php && git commit -m "feat: add urgent manual data in"`

### Task 6: Refill and Data IN Inertia workflows

**Files:**
- Create: `resources/js/Pages/Refill/Index.vue`
- Create: `resources/js/Pages/Refill/Create.vue`
- Create: `resources/js/Pages/Refill/Show.vue`
- Create: `resources/js/Pages/IncomingRefill/Index.vue`
- Create: `resources/js/Pages/IncomingRefill/Show.vue`
- Create: `resources/js/Pages/Transactions/DataIn/Index.vue`
- Create: `resources/js/Pages/Transactions/DataIn/Create.vue`
- Modify: `resources/js/Components/AppSidebar.vue`
- Test: `tests/Browser/refill-data-in.spec.js`

**Interfaces:**
- Consumes: Tasks 2–5 routes and server props.
- Produces: role-aware Refill, Incoming Refill, and Data IN screens matching `DESIGN.md`.

- [ ] **Step 1: Write failing browser scenarios**

  Cover Warehouse creating an adjusted suggestion, Vendor revision and shipment, Admin approval, Warehouse partial receipt/follow-up, Admin close, urgent Data IN, role-specific navigation, processing-state duplicate protection, and 360 px table/form usability.

- [ ] **Step 2: Run the browser test and confirm missing-page failure**

  Run: `npx playwright test tests/Browser/refill-data-in.spec.js`

  Expected: FAIL because pages/navigation are absent.

- [ ] **Step 3: Implement reusable workflow pages**

  Reuse existing layout/components; create a small `QuantityField.vue` only if three pages need identical four-decimal parsing/error behavior. Do not display buttons the backend denies.

- [ ] **Step 4: Run full module checks**

  Run: `php artisan test --compact tests/Feature/RefillSchemaTest.php tests/Feature/RefillSuggestionTest.php tests/Feature/RefillRequestTest.php tests/Feature/VendorRefillWorkflowTest.php tests/Feature/IncomingRefillTest.php tests/Feature/ManualDataInTest.php && npm run build && npx playwright test tests/Browser/refill-data-in.spec.js`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add resources/js/Pages/Refill resources/js/Pages/IncomingRefill resources/js/Pages/Transactions/DataIn resources/js/Components/AppSidebar.vue tests/Browser/refill-data-in.spec.js && git commit -m "feat: add refill and data in workflows"`

