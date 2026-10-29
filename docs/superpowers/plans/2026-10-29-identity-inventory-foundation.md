# Identity and Inventory Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the three operational roles, Vendor-scoped users, configurable 12-hour shifts, opening balances, immutable stock ledger, idempotent posting, adjustments, reversals, and audit foundation used by all later modules.

**Architecture:** Keep the existing Laravel modular monolith and add focused Identity, Shifts, Inventory, and Audit actions. `stock_ledger_entries` is the source of truth; `stock_balances` and `stock_lots` are transactionally maintained projections. All schema changes are additive UUID migrations and all stock mutations pass through explicit posting actions.

**Tech Stack:** PHP 8.3, Laravel 13, Inertia 3, Vue 3 Composition API, MySQL/SQLite tests, PHPUnit 12, Tailwind CSS 4, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-29-consignment-operations-design.md`

## Global Constraints

- Preserve all unrelated user work in the dirty worktree; do not reset or overwrite it.
- Use UUID for every new PK and FK, `DECIMAL(18,4)` for quantities, and `Asia/Makassar` for business-time presentation.
- Keep business document numbers as unique strings; never use them as primary keys.
- Posted inventory records are immutable; corrections use reversal or adjustment.
- No destructive database operation, main-database migration, or production deployment without explicit user instruction.
- Follow `DESIGN.md`; do not add QR, serialized tracking, rotable, tyre, full Stock Opname, or requester/approver roles.
- Use TDD: observe the targeted test fail for the expected reason before implementation.

## Review Focus

- A Vendor user with a forged Vendor UUID must receive 403/404 and never see another Vendor in index, detail, export, or shared Inertia props; pin in Task 2.
- A transaction posted twice with the same idempotency key must create one stock transaction and one balance change; pin in Task 5.
- Two concurrent OUT operations against the same final quantity must allow only one and never produce a negative balance; pin in Task 5/MySQL verification.
- A 02:00 posting belongs to the night shift that began the previous date, and future shift edits must not regroup it; pin in Task 3.
- Reversing an IN lot already consumed by an OUT must fail without changing ledger, lots, or balances; pin in Task 7.

---

### Task 1: Operational roles and Vendor-scoped user model

**Files:**
- Create: `database/migrations/2026_10_29_002000_expand_users_for_operational_roles.php`
- Create: `database/migrations/2026_10_29_002050_create_audit_logs_table.php`
- Modify: `app/Enums/UserRole.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `app/Http/Requests/Auth/LoginRequest.php`
- Modify: `app/Console/Commands/CreateAdmin.php`
- Create: `app/Models/AuditLog.php`
- Create: `app/Actions/Audit/WriteAuditLog.php`
- Test: `tests/Feature/OperationalUserTest.php`
- Test: `tests/Feature/AuthenticationTest.php`

**Interfaces:**
- Produces: `UserRole::{Admin,Warehouse,Vendor}`, `User::vendor(): BelongsTo`, `User::isAdmin(): bool`, `User::isWarehouse(): bool`, `User::isVendor(): bool`.
- Produces: factory states `admin()`, `warehouse()`, and `vendor(Vendor $vendor)`.
- Produces: `WriteAuditLog::handle(User $actor, string $action, Model $entity, array $before, array $after, ?string $reason = null): AuditLog`.

- [ ] **Step 1: Write failing operational-user tests**

  Add tests asserting: legacy `user` migrates to `warehouse`; `vendor_id` is required for Vendor role and forbidden for Admin/Warehouse; inactive users cannot log in; each factory state casts to the expected enum; mass assignment still cannot self-promote or reassign Vendor; audit records preserve actor/action/entity/before/after/reason with a UUID identity.

- [ ] **Step 2: Run the targeted tests and confirm the expected failure**

  Run: `php artisan test --compact tests/Feature/OperationalUserTest.php tests/Feature/AuthenticationTest.php`

  Expected: FAIL because Warehouse/Vendor cases, columns, helpers, and factory states do not exist.

- [ ] **Step 3: Add the migration and user-domain behavior**

  Migration adds nullable constrained `vendor_id`, `is_active` default true, nullable `employee_no`, nullable `position`, and `last_login_at`; convert existing `user` role values to `warehouse` before changing defaults. Keep role/vendor/status out of normal `$fillable`; administrative actions will use explicit assignment.

- [ ] **Step 4: Update login and admin command, then rerun tests**

  Login rejects inactive accounts and records `last_login_at` after success. Admin command creates `UserRole::Admin` with no Vendor.

  Run: `php artisan test --compact tests/Feature/OperationalUserTest.php tests/Feature/AuthenticationTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit the task**

  Run: `git add app/Enums/UserRole.php app/Models/User.php app/Models/AuditLog.php app/Actions/Audit/WriteAuditLog.php app/Http/Requests/Auth/LoginRequest.php app/Console/Commands/CreateAdmin.php database/factories/UserFactory.php database/migrations/2026_10_29_002000_expand_users_for_operational_roles.php database/migrations/2026_10_29_002050_create_audit_logs_table.php tests/Feature/OperationalUserTest.php tests/Feature/AuthenticationTest.php && git commit -m "feat: add operational user roles"`

### Task 2: User Management authorization and pages

**Files:**
- Create: `app/Actions/Users/SaveUser.php`
- Create: `app/Http/Controllers/UserManagement/UserController.php`
- Create: `app/Http/Requests/UserManagement/SaveUserRequest.php`
- Create: `app/Http/Requests/UserManagement/UserIndexRequest.php`
- Create: `app/Policies/UserPolicy.php`
- Create: `app/Queries/UserListQuery.php`
- Create: `resources/js/Pages/UserManagement/Index.vue`
- Create: `resources/js/Pages/UserManagement/Form.vue`
- Modify: `app/Policies/MasterDataPolicy.php`
- Modify: `app/Policies/ItemPolicy.php`
- Modify: `app/Policies/VendorPolicy.php`
- Modify: `app/Policies/CategoryPolicy.php`
- Modify: `app/Policies/LocationPolicy.php`
- Modify: `app/Policies/UnitPolicy.php`
- Modify: `app/Queries/ItemListQuery.php`
- Modify: `app/Http/Controllers/MasterData/ItemController.php`
- Modify: `app/Http/Controllers/MasterData/ItemExportController.php`
- Modify: `app/Http/Controllers/MasterData/ReferenceController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Components/AppSidebar.vue`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/UserManagementTest.php`
- Test: `tests/Feature/VendorMasterDataScopeTest.php`
- Test: `tests/Browser/user-management.spec.js`

**Interfaces:**
- Consumes: Task 1 role helpers, factory states, and `WriteAuditLog`.
- Produces: `SaveUser::handle(array $validated, ?User $user = null): User` and Admin-only routes `users.index/create/store/edit/update/status`.
- Produces: shared auth payload containing role, vendor id/name when applicable, and ability flags.

- [ ] **Step 1: Write failing policy and CRUD feature tests**

  Assert Admin can create/update/deactivate accounts; Warehouse and Vendor receive 403 for every mutation; Vendor selection is required only for Vendor role; used users are deactivated rather than deleted; changing role/Vendor creates an audit-ready before/after payload hook. Add the review-focus test that a Vendor sees only its linked Vendor/items in Master Data index/detail/export, cannot enumerate reference masters, and a forged Vendor filter or route never exposes another Vendor.

- [ ] **Step 2: Run the feature test and confirm failure**

  Run: `php artisan test --compact tests/Feature/UserManagementTest.php tests/Feature/VendorMasterDataScopeTest.php`

  Expected: FAIL with missing routes/classes.

- [ ] **Step 3: Implement backend action, requests, policy, query, and routes**

  `SaveUser` validates the role/vendor invariant inside a database transaction, hashes a supplied password, preserves the existing password when blank on edit, never accepts role changes from public model mass assignment, and writes before/after audit records.

- [ ] **Step 4: Build the Inertia pages and browser test**

  Reuse `AppLayout`, `PageBanner`, `FormField`, `StatusBadge`, `Pagination`, and current design tokens. The index supports search, role/status/Vendor filters, and explicit deactivate/reactivate actions.

  Run: `php artisan test --compact tests/Feature/UserManagementTest.php tests/Feature/VendorMasterDataScopeTest.php && npm run build && npx playwright test tests/Browser/user-management.spec.js`

  Expected: all commands PASS.

- [ ] **Step 5: Commit the task**

  Run: `git add app/Actions/Users app/Http/Controllers/UserManagement app/Http/Requests/UserManagement app/Policies app/Queries/UserListQuery.php app/Queries/ItemListQuery.php app/Http/Controllers/MasterData/ItemController.php app/Http/Controllers/MasterData/ItemExportController.php app/Http/Controllers/MasterData/ReferenceController.php app/Http/Middleware/HandleInertiaRequests.php resources/js/Pages/UserManagement resources/js/Components/AppSidebar.vue routes/web.php tests/Feature/UserManagementTest.php tests/Feature/VendorMasterDataScopeTest.php tests/Browser/user-management.spec.js && git commit -m "feat: add user management"`

### Task 3: Configurable shift definitions and immutable shift instances

**Files:**
- Create: `database/migrations/2026_10_29_002100_create_shift_tables.php`
- Create: `app/Enums/OperationalShiftStatus.php`
- Create: `app/Models/ShiftDefinition.php`
- Create: `app/Models/OperationalShift.php`
- Create: `app/Actions/Shifts/ResolveOperationalShift.php`
- Create: `app/Actions/Shifts/SaveShiftDefinition.php`
- Create: `app/Http/Controllers/Settings/ShiftDefinitionController.php`
- Create: `app/Http/Requests/Settings/SaveShiftDefinitionRequest.php`
- Create: `resources/js/Pages/Settings/Shifts/Index.vue`
- Modify: `routes/web.php`
- Modify: `resources/js/Components/AppSidebar.vue`
- Modify: `database/seeders/MasterDataSeeder.php`
- Test: `tests/Feature/ShiftResolutionTest.php`
- Test: `tests/Browser/shift-settings.spec.js`

**Interfaces:**
- Produces: `ResolveOperationalShift::handle(CarbonImmutable $postedAt): OperationalShift`.
- Produces: `SaveShiftDefinition::handle(array $data, User $admin): ShiftDefinition`; edits create a future effective version rather than rewriting historical definitions.

- [ ] **Step 1: Write failing shift-resolution tests**

  Assert default day shift is 06:00–18:00 WITA, night shift is 18:00–06:00 across midnight, 02:00 resolves to the prior-date night instance, boundaries are start-inclusive/end-exclusive, and a future schedule version does not regroup existing instances.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/ShiftResolutionTest.php`

  Expected: FAIL because shift tables and resolver do not exist.

- [ ] **Step 3: Implement UUID tables, models, defaults, and resolver**

  Add unique constraints for definition code/effective range and operational instance start/end. Seeder uses idempotent creation for DAY 06:00–18:00 and NIGHT 18:00–06:00 without overwriting user changes.

- [ ] **Step 4: Add the Admin-only settings page and rerun tests**

  Implement index/create-future-version endpoints and an accessible settings page. Warehouse may view current shift labels but only Admin can change future definitions.

  Run: `php artisan test --compact tests/Feature/ShiftResolutionTest.php && npm run build && npx playwright test tests/Browser/shift-settings.spec.js`

  Expected: PASS.

- [ ] **Step 5: Commit the task**

  Run: `git add app/Enums/OperationalShiftStatus.php app/Models/ShiftDefinition.php app/Models/OperationalShift.php app/Actions/Shifts app/Http/Controllers/Settings/ShiftDefinitionController.php app/Http/Requests/Settings/SaveShiftDefinitionRequest.php resources/js/Pages/Settings/Shifts/Index.vue resources/js/Components/AppSidebar.vue routes/web.php database/migrations/2026_10_29_002100_create_shift_tables.php database/seeders/MasterDataSeeder.php tests/Feature/ShiftResolutionTest.php tests/Browser/shift-settings.spec.js && git commit -m "feat: add configurable operational shifts"`

### Task 4: Inventory period, ledger, lot, balance, and audit schema

**Files:**
- Create: `database/migrations/2026_10_29_002200_create_inventory_ledger_tables.php`
- Create: `app/Enums/InventoryPeriodStatus.php`
- Create: `app/Enums/StockTransactionStatus.php`
- Create: `app/Enums/StockTransactionType.php`
- Create: `app/Enums/StockDirection.php`
- Create: `app/Models/InventoryPeriod.php`
- Create: `app/Models/StockTransaction.php`
- Create: `app/Models/StockLedgerEntry.php`
- Create: `app/Models/StockBalance.php`
- Create: `app/Models/StockLot.php`
- Create: `app/Models/StockLotAllocation.php`
- Create: `app/Data/StockPostingData.php`
- Create: `app/Data/StockPostingLineData.php`
- Test: `tests/Feature/InventoryLedgerSchemaTest.php`

**Interfaces:**
- Consumes: Task 1 `AuditLog`.
- Produces: immutable DTO `StockPostingData(type, sourceType, sourceId, physicalOccurredAt, actorId, idempotencyKey, reason, lines)`.
- Produces: immutable DTO `StockPostingLineData(itemId, vendorItemId, locationId, direction, quantity)`.
- Produces: relations used by every later stock action and report.

- [ ] **Step 1: Write failing schema and model tests**

  Assert all PK/FK IDs are UUID strings; quantity columns retain four decimals; one balance exists per STOCK item–location or CONSIGNMENT vendor-item–location bucket; idempotency key and source reference are unique; delete restrictions preserve ledger history.

- [ ] **Step 2: Run and observe missing-schema failure**

  Run: `php artisan test --compact tests/Feature/InventoryLedgerSchemaTest.php`

  Expected: FAIL because tables/models are absent.

- [ ] **Step 3: Create migrations, enums, models, and DTOs**

  Use explicit composite unique indexes with a normalized non-null `bucket_key` string (`STOCK:item:location` or `CONSIGNMENT:vendor_item:location`) to avoid nullable-unique ambiguity across MySQL and SQLite. Ledger entries store signed quantity; DTO input quantities remain positive.

- [ ] **Step 4: Rerun schema tests**

  Run: `php artisan test --compact tests/Feature/InventoryLedgerSchemaTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit the task**

  Run: `git add app/Enums/InventoryPeriodStatus.php app/Enums/StockTransactionStatus.php app/Enums/StockTransactionType.php app/Enums/StockDirection.php app/Models/InventoryPeriod.php app/Models/StockTransaction.php app/Models/StockLedgerEntry.php app/Models/StockBalance.php app/Models/StockLot.php app/Models/StockLotAllocation.php app/Data database/migrations/2026_10_29_002200_create_inventory_ledger_tables.php tests/Feature/InventoryLedgerSchemaTest.php && git commit -m "feat: add immutable inventory ledger schema"`

### Task 5: Atomic and idempotent stock posting actions

**Files:**
- Create: `app/Actions/Inventory/BuildStockBucket.php`
- Create: `app/Actions/Inventory/PostStockIn.php`
- Create: `app/Actions/Inventory/PostStockOut.php`
- Test: `tests/Feature/StockPostingTest.php`
- Test: `tests/Feature/MySqlStockConcurrencyTest.php`

**Interfaces:**
- Consumes: Task 1 `WriteAuditLog`, Task 3 `ResolveOperationalShift`, and Task 4 posting DTOs/models.
- Produces: `BuildStockBucket::handle(Item $item, ?VendorItem $vendorItem, Location $location): string`.
- Produces: `PostStockIn::handle(StockPostingData $data): StockTransaction`.
- Produces: `PostStockOut::handle(StockPostingData $data): StockTransaction`.

- [ ] **Step 1: Write failing posting tests**

  Assert IN creates positive ledger, balance, and one lot; OUT creates negative ledger and FIFO allocations; ownership mismatch and inactive placement fail atomically; insufficient balance fails; duplicate idempotency key returns the original transaction without a second balance change; physical date does not change the posting shift.

- [ ] **Step 2: Run and confirm expected action failures**

  Run: `php artisan test --compact tests/Feature/StockPostingTest.php`

  Expected: FAIL because posting actions do not exist.

- [ ] **Step 3: Implement both posting actions in database transactions**

  Lock source and balance rows, validate the active inventory period, resolve shift from `posted_at = now()`, create ledger and lot changes, update balances, link source, and write audit. Use unique constraints to resolve duplicate requests safely.

- [ ] **Step 4: Test SQLite behavior and MySQL concurrency**

  Run: `php artisan test --compact tests/Feature/StockPostingTest.php`

  Run: `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml --filter MySqlStockConcurrencyTest`

  Expected: both PASS; concurrent final-quantity OUT permits one commit and one controlled insufficient-stock failure, with balance `0.0000`.

- [ ] **Step 5: Commit the task**

  Run: `git add app/Actions/Inventory tests/Feature/StockPostingTest.php tests/Feature/MySqlStockConcurrencyTest.php && git commit -m "feat: add atomic stock posting"`

### Task 6: Opening-balance preview and activation

**Files:**
- Create: `database/migrations/2026_10_29_002300_create_opening_balance_tables.php`
- Create: `app/Enums/OpeningBalanceBatchStatus.php`
- Create: `app/Models/OpeningBalanceBatch.php`
- Create: `app/Models/OpeningBalanceLine.php`
- Create: `app/Services/OpeningBalance/WorkbookReader.php`
- Create: `app/Actions/OpeningBalance/SaveOpeningBalanceBatch.php`
- Create: `app/Actions/OpeningBalance/ActivateOpeningBalance.php`
- Create: `app/Http/Controllers/OpeningBalanceController.php`
- Create: `app/Http/Requests/OpeningBalance/StoreOpeningBalanceRequest.php`
- Create: `resources/js/Pages/OpeningBalance/Index.vue`
- Create: `resources/js/Pages/OpeningBalance/Preview.vue`
- Modify: `routes/web.php`
- Modify: `resources/js/Components/AppSidebar.vue`
- Test: `tests/Feature/OpeningBalanceTest.php`
- Test: `tests/Browser/opening-balance.spec.js`

**Interfaces:**
- Consumes: `PostStockIn::handle()` from Task 5.
- Produces: `SaveOpeningBalanceBatch::handle(array $rows, InventoryPeriod $period, User $admin, string $source): OpeningBalanceBatch`.
- Produces: `ActivateOpeningBalance::handle(OpeningBalanceBatch $batch, User $admin, string $idempotencyKey): InventoryPeriod`.

- [ ] **Step 1: Write failing opening-balance tests**

  Assert form/XLSX preview writes no ledger; duplicate bucket, bad ownership, inactive master, negative or over-precision quantity fail with row messages; only Admin activates; activation posts each bucket once and marks period ACTIVE; edits/second activation are rejected; Warehouse is read-only; no operational post is allowed before activation.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/OpeningBalanceTest.php`

  Expected: FAIL with missing tables/routes/actions.

- [ ] **Step 3: Implement schema, reader, preview, and activation action**

  Reuse PhpSpreadsheet, require internal item code plus location and Vendor code for consignment, preserve source row numbers, and post opening lots using one idempotent activation transaction.

- [ ] **Step 4: Implement accessible Inertia flow and verify**

  Run: `php artisan test --compact tests/Feature/OpeningBalanceTest.php && npm run build && npx playwright test tests/Browser/opening-balance.spec.js`

  Expected: PASS.

- [ ] **Step 5: Commit the task**

  Run: `git add database/migrations/2026_10_29_002300_create_opening_balance_tables.php app/Enums/OpeningBalanceBatchStatus.php app/Models/OpeningBalanceBatch.php app/Models/OpeningBalanceLine.php app/Services/OpeningBalance app/Actions/OpeningBalance app/Http/Controllers/OpeningBalanceController.php app/Http/Requests/OpeningBalance resources/js/Pages/OpeningBalance resources/js/Components/AppSidebar.vue routes/web.php tests/Feature/OpeningBalanceTest.php tests/Browser/opening-balance.spec.js && git commit -m "feat: add opening balance activation"`

### Task 7: Audited adjustment and safe reversal

**Files:**
- Create: `database/migrations/2026_10_29_002400_create_stock_adjustments_table.php`
- Create: `app/Enums/StockAdjustmentStatus.php`
- Create: `app/Models/StockAdjustment.php`
- Create: `app/Actions/Inventory/CreateStockAdjustment.php`
- Create: `app/Actions/Inventory/ReviewStockAdjustment.php`
- Create: `app/Actions/Inventory/PostStockAdjustment.php`
- Create: `app/Actions/Inventory/ReverseStockTransaction.php`
- Create: `app/Events/StockTransactionReversed.php`
- Create: `app/Policies/StockCorrectionPolicy.php`
- Create: `app/Http/Controllers/Inventory/StockCorrectionController.php`
- Create: `app/Http/Requests/Inventory/StoreStockCorrectionRequest.php`
- Create: `resources/js/Pages/Inventory/Corrections/Index.vue`
- Create: `resources/js/Pages/Inventory/Corrections/Create.vue`
- Modify: `routes/web.php`
- Modify: `resources/js/Components/AppSidebar.vue`
- Test: `tests/Feature/StockCorrectionTest.php`
- Test: `tests/Browser/stock-correction.spec.js`

**Interfaces:**
- Consumes: Task 1 `WriteAuditLog` and Task 5 posting actions.
- Produces: `CreateStockAdjustment::handle(array $data, User $requester): StockAdjustment` for Warehouse/Admin drafts.
- Produces: `ReviewStockAdjustment::handle(StockAdjustment $adjustment, bool $approve, string $note, User $admin): StockAdjustment`.
- Produces: `PostStockAdjustment::handle(StockAdjustment $adjustment, User $admin, string $idempotencyKey): StockTransaction`.
- Produces: `ReverseStockTransaction::handle(StockTransaction $original, User $admin, string $reason, string $idempotencyKey): StockTransaction`.
- Produces: after-commit `StockTransactionReversed(StockTransaction $original, StockTransaction $reversal)`.
- Later plans attach source-specific restoration hooks through `StockTransactionReversed` event listeners.

- [ ] **Step 1: Write failing correction tests**

  Assert posted records reject update/delete; Warehouse/Admin can create an adjustment draft; only Admin can approve/post adjustment or reverse; a reason is mandatory; rejected drafts never change stock; reversal creates opposite entries and one link to original; duplicate reversal is idempotent; consumed IN lot cannot be reversed; reversing OUT restores lot quantities and balance; all before/after/reason values are audited.

- [ ] **Step 2: Run and confirm failure**

  Run: `php artisan test --compact tests/Feature/StockCorrectionTest.php`

  Expected: FAIL because correction actions/schema are absent.

- [ ] **Step 3: Implement adjustment, reversal, policy, and audit behavior**

  Keep source-specific workflow restoration out of this task; dispatch `StockTransactionReversed` only after commit so Refill/MR plans can subscribe safely.

- [ ] **Step 4: Add role-aware correction pages and rerun tests**

  Implement Warehouse/Admin adjustment-draft UI plus Admin review/post/reversal controls. Require a reason, show source impact, and never present posted rows as editable.

  Run: `php artisan test --compact tests/Feature/StockCorrectionTest.php && npm run build && npx playwright test tests/Browser/stock-correction.spec.js`

  Expected: PASS.

- [ ] **Step 5: Commit the task**

  Run: `git add database/migrations/2026_10_29_002400_create_stock_adjustments_table.php app/Enums/StockAdjustmentStatus.php app/Models/StockAdjustment.php app/Actions/Inventory/CreateStockAdjustment.php app/Actions/Inventory/ReviewStockAdjustment.php app/Actions/Inventory/PostStockAdjustment.php app/Actions/Inventory/ReverseStockTransaction.php app/Events/StockTransactionReversed.php app/Policies/StockCorrectionPolicy.php app/Http/Controllers/Inventory/StockCorrectionController.php app/Http/Requests/Inventory/StoreStockCorrectionRequest.php resources/js/Pages/Inventory/Corrections resources/js/Components/AppSidebar.vue routes/web.php tests/Feature/StockCorrectionTest.php tests/Browser/stock-correction.spec.js && git commit -m "feat: add audited stock corrections"`

### Task 8: Foundation regression, MySQL migration, and documentation gate

**Files:**
- Modify: `docs/master-data-database.md`
- Modify: `README.md`
- Test: `tests/Feature/UuidInfrastructureTest.php`
- Test: `tests/Feature/UuidUpgradeTest.php`

**Interfaces:**
- Consumes: Tasks 1–7.
- Produces: verified foundation contract for Refill, MR, Procurement, and Monitoring plans.

- [ ] **Step 1: Extend infrastructure tests**

  Assert every new table PK and FK uses UUID-compatible columns, migrations preserve existing Master Data, seeding remains idempotent, and no default/demo operational account or opening balance is created.

- [ ] **Step 2: Run focused migration and infrastructure checks**

  Run: `php artisan test --compact tests/Feature/UuidInfrastructureTest.php tests/Feature/UuidUpgradeTest.php`

  Expected: PASS.

- [ ] **Step 3: Document schema, commands, and recovery limits**

  Update docs with role mapping, shift defaults, opening activation, ledger source-of-truth, idempotency, and the rule that main DB migration requires explicit instruction.

- [ ] **Step 4: Run the proportional full gate**

  Run: `php artisan test --compact`

  Run: `php vendor/bin/pint --test`

  Run: `npm run build`

  Run: `npm run test:browser`

  Run: `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml`

  Expected: all PASS; capture exact counts/output in the handoff.

- [ ] **Step 5: Commit the task**

  Run: `git add README.md docs/master-data-database.md tests/Feature/UuidInfrastructureTest.php tests/Feature/UuidUpgradeTest.php && git commit -m "docs: record inventory foundation"`

