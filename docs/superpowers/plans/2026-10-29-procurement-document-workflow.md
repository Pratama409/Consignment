# Procurement Document Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track each consignment Data OUT through Reservasi/WO, PR validation, PO, GRGI, and versioned documents using idempotent row-key Excel imports without changing stock.

**Architecture:** One immutable procurement requirement is created per consignment `material_issue_line`. Each SAP stage has child document lines so a quantity may be split across multiple references; import batches and rows preserve versions, validation results, and audit history.

**Tech Stack:** PHP 8.3, Laravel 13, Inertia 3, Vue 3, PhpSpreadsheet 5.10, private Laravel storage, PHPUnit 12, Tailwind CSS 4, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-29-consignment-operations-design.md`

## Global Constraints

- Execute after Material Request/Data OUT is complete.
- Procurement never changes ledger, lots, or stock balances.
- Row UUID, not PN, spreadsheet order, or document number, is the import identity.
- Upload/update preserves prior values and files as versions; no silent overwrite or deletion.
- Vendor sees only its own filtered fields and only attachments explicitly shared by Admin.
- Files use private storage, random paths, allowlisted MIME/extensions/size, checksum, and authorized download.

## Review Focus

- A row-key belonging to another Vendor or issue must not be reassigned by editing visible spreadsheet columns; pin in Task 3.
- Split PR/PO/GRGI quantities must not exceed the immutable issued quantity and must retain leading zeros; pin in Tasks 1 and 5.
- Re-importing the same file or retrying apply must not create duplicate document lines; pin in Task 3.
- A mixed-Vendor source attachment must never be downloadable by any Vendor even if one line is theirs; pin in Task 6.
- Data OUT reversal after PO/GRGI must preserve references and mark them for Admin review/void rather than delete them; pin in Task 2.

---

### Task 1: Procurement requirement, document-line, import, validation, and file schema

**Files:**
- Create: `database/migrations/2026_10_29_005000_create_procurement_tables.php`
- Create: `app/Enums/ProcurementStage.php`
- Create: `app/Enums/ProcurementRequirementStatus.php`
- Create: `app/Enums/ProcurementValidationDecision.php`
- Create: `app/Enums/ProcurementImportStatus.php`
- Create: `app/Models/ProcurementRequirement.php`
- Create: `app/Models/ProcurementDocumentLine.php`
- Create: `app/Models/ProcurementImportBatch.php`
- Create: `app/Models/ProcurementImportRow.php`
- Create: `app/Models/ProcurementValidation.php`
- Create: `app/Models/ProcurementDocument.php`
- Test: `tests/Feature/ProcurementSchemaTest.php`

**Interfaces:**
- Produces: one `ProcurementRequirement` per consignment issue line with immutable `row_key` UUID and `required_quantity`.
- Produces: child line identity `(requirement_id, stage, version, line_no)` and active-version query scope.

- [ ] **Step 1: Write failing schema/model tests**

  Assert UUIDs; one requirement per issue line; STOCK issues cannot have requirements; document numbers remain strings with leading zero; active child quantity per stage cannot exceed required quantity; import checksum/version constraints; private document metadata and default `is_vendor_visible=false`.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/ProcurementSchemaTest.php`

  Expected: FAIL because procurement schema/models are absent.

- [ ] **Step 3: Implement migrations, enums, models, and guarded status transitions**

  Enforce quantity totals in application transactions plus database row locks; do not rely on a cross-row CHECK constraint unavailable in MySQL.

- [ ] **Step 4: Rerun schema tests**

  Run: `php artisan test --compact tests/Feature/ProcurementSchemaTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add database/migrations/2026_10_29_005000_create_procurement_tables.php app/Enums/ProcurementStage.php app/Enums/ProcurementRequirementStatus.php app/Enums/ProcurementValidationDecision.php app/Enums/ProcurementImportStatus.php app/Models/ProcurementRequirement.php app/Models/ProcurementDocumentLine.php app/Models/ProcurementImportBatch.php app/Models/ProcurementImportRow.php app/Models/ProcurementValidation.php app/Models/ProcurementDocument.php tests/Feature/ProcurementSchemaTest.php && git commit -m "feat: add procurement workflow schema"`

### Task 2: Requirement creation and Data OUT reversal handling

**Files:**
- Create: `app/Actions/Procurement/CreateProcurementRequirement.php`
- Create: `app/Listeners/Procurement/CreateRequirementAfterIssue.php`
- Create: `app/Listeners/Procurement/ReviewRequirementAfterIssueReversal.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/ProcurementRequirementTest.php`

**Interfaces:**
- Consumes: `MaterialIssuePosted`/`MaterialIssueReversed` events.
- Produces: `CreateProcurementRequirement::handle(MaterialIssueLine $line): ?ProcurementRequirement`; returns null for STOCK, existing requirement on retry, or new requirement for CONSIGNMENT.

- [ ] **Step 1: Write failing lifecycle tests**

  Assert requirement creation is idempotent and copies issued Vendor/item/location/qty snapshots; STOCK is ignored; reversal with no documents marks VOID; reversal with PR/PO/GRGI marks REQUIRES_REVIEW, preserves every reference, and flags the issue as document outstanding.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/ProcurementRequirementTest.php`

  Expected: FAIL with missing actions/listeners.

- [ ] **Step 3: Implement actions/listeners and after-commit registration**

  Never call stock actions from these listeners. Record actor/reason from reversal audit when available.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/ProcurementRequirementTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Actions/Procurement/CreateProcurementRequirement.php app/Listeners/Procurement app/Providers/AppServiceProvider.php tests/Feature/ProcurementRequirementTest.php && git commit -m "feat: create procurement requirements"`

### Task 3: Reservasi/PR Excel export, preview, and idempotent import

**Files:**
- Create: `app/Services/Procurement/WorkbookExporter.php`
- Create: `app/Services/Procurement/WorkbookReader.php`
- Create: `app/Services/Procurement/ImportPlanner.php`
- Create: `app/Actions/Procurement/ApplyProcurementImport.php`
- Create: `app/Http/Controllers/Procurement/ReservationPrController.php`
- Create: `app/Http/Requests/Procurement/UploadProcurementWorkbook.php`
- Create: `app/Policies/ProcurementPolicy.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProcurementReservationImportTest.php`

**Interfaces:**
- Produces: `WorkbookExporter::reservationPr(iterable $requirements): Spreadsheet`.
- Produces: `WorkbookReader::read(UploadedFile $file, ProcurementStage $stage): array<ImportRowData>`.
- Produces: `ApplyProcurementImport::handle(ProcurementImportBatch $batch, User $actor, string $idempotencyKey): ProcurementImportBatch`.

- [ ] **Step 1: Write failing export/import tests**

  Assert export includes row key, MR/OUT, Vendor, PN, description, issued qty, and blank Reservasi/PR columns; import trusts row key but verifies immutable snapshots; forged keys, changed Vendor/qty, duplicate active rows, formulas, oversized files, and cross-stage columns fail; leading zeros survive; retry/file duplicate applies once.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/ProcurementReservationImportTest.php`

  Expected: FAIL because workbook services/actions/routes are absent.

- [ ] **Step 3: Implement export, isolated preview, and atomic apply**

  Preview stores source snapshot and errors but changes no active references. Apply locks requirements and creates a new active version while retaining the previous version.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/ProcurementReservationImportTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Services/Procurement app/Actions/Procurement/ApplyProcurementImport.php app/Http/Controllers/Procurement/ReservationPrController.php app/Http/Requests/Procurement/UploadProcurementWorkbook.php app/Policies/ProcurementPolicy.php routes/web.php tests/Feature/ProcurementReservationImportTest.php && git commit -m "feat: add reservation and pr import"`

### Task 4: Admin PR validation and revision loop

**Files:**
- Create: `app/Actions/Procurement/ValidateProcurementRequirement.php`
- Create: `app/Http/Controllers/Procurement/ValidationController.php`
- Create: `app/Http/Requests/Procurement/ValidateRequirementRequest.php`
- Test: `tests/Feature/ProcurementValidationTest.php`

**Interfaces:**
- Produces: `ValidateProcurementRequirement::handle(ProcurementRequirement $requirement, ProcurementValidationDecision $decision, string $note, User $admin): ProcurementValidation`.
- Produces: revision-only workbook through Task 3 exporter filtered to latest REVISE validation.

- [ ] **Step 1: Write failing validation tests**

  Assert only Admin can validate; VALID advances to PO_PENDING; REVISE/REJECT requires a note; revision export includes only latest unresolved revisions; corrected import creates a new version and returns to validation without deleting the rejected version.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/ProcurementValidationTest.php`

  Expected: FAIL with missing action/controller.

- [ ] **Step 3: Implement validation action, endpoint, and revision export filter**

  Lock the requirement and latest active PR version. Audit decision, note, validator, and timestamp.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/ProcurementValidationTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Actions/Procurement/ValidateProcurementRequirement.php app/Http/Controllers/Procurement/ValidationController.php app/Http/Requests/Procurement/ValidateRequirementRequest.php tests/Feature/ProcurementValidationTest.php && git commit -m "feat: add pr validation workflow"`

### Task 5: PO and GRGI split-line imports and completion

**Files:**
- Create: `app/Actions/Procurement/RecalculateProcurementStatus.php`
- Create: `app/Http/Controllers/Procurement/PurchaseOrderController.php`
- Create: `app/Http/Controllers/Procurement/GrgiController.php`
- Test: `tests/Feature/ProcurementPoGrgiTest.php`

**Interfaces:**
- Consumes: Task 3 reader/planner/apply with `ProcurementStage::PO` and `::GRGI`.
- Produces: `RecalculateProcurementStatus::handle(ProcurementRequirement $requirement): ProcurementRequirement` and updates `material_issue_line.documents_outstanding`.

- [ ] **Step 1: Write failing split/completion tests**

  Assert one requirement may have multiple PO/GRGI lines; active quantity total cannot exceed issued; correction creates new version; complete active references clear document-outstanding; incomplete or REQUIRES_REVIEW remains outstanding; no operation changes any stock table.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/ProcurementPoGrgiTest.php`

  Expected: FAIL because stage controllers/recalculator are absent.

- [ ] **Step 3: Implement stage endpoints and status recalculation**

  Reuse Task 3 import pipeline; do not fork separate parsers. For v1, define completion as validated PR plus PO quantity covering issued quantity. GRGI remains a recorded, visible supplemental reference for Vendor invoicing but does not block completion.

- [ ] **Step 4: Rerun tests and stock-regression assertion**

  Run: `php artisan test --compact tests/Feature/ProcurementPoGrgiTest.php tests/Feature/StockPostingTest.php`

  Expected: PASS with unchanged ledger counts during procurement tests.

- [ ] **Step 5: Commit**

  Run: `git add app/Actions/Procurement/RecalculateProcurementStatus.php app/Http/Controllers/Procurement/PurchaseOrderController.php app/Http/Controllers/Procurement/GrgiController.php tests/Feature/ProcurementPoGrgiTest.php && git commit -m "feat: add po and grgi workflow"`

### Task 6: Private, versioned procurement documents

**Files:**
- Create: `app/Actions/Procurement/StoreProcurementDocument.php`
- Create: `app/Http/Controllers/Procurement/DocumentController.php`
- Create: `app/Http/Requests/Procurement/StoreProcurementDocumentRequest.php`
- Modify: `config/filesystems.php`
- Test: `tests/Feature/ProcurementDocumentSecurityTest.php`

**Interfaces:**
- Produces: `StoreProcurementDocument::handle(ProcurementRequirement $requirement, UploadedFile $file, string $type, bool $vendorVisible, User $actor): ProcurementDocument`.
- Produces: authorized streamed download route; no public disk URL.

- [ ] **Step 1: Write failing storage/authorization tests**

  Assert allowlisted PDF/XLS/XLSX/image MIME and configured max size; fake double extensions, traversal names, and disallowed MIME fail; stored path is random/private; checksum and original name persist; replacement creates version; Vendor sees only own explicitly visible single-Vendor documents; mixed-Vendor source file is Admin/Warehouse only.

- [ ] **Step 2: Run and observe failure**

  Run: `php artisan test --compact tests/Feature/ProcurementDocumentSecurityTest.php`

  Expected: FAIL with missing action/controller/private disk configuration.

- [ ] **Step 3: Implement secure storage and download policy**

  Sanitize only display names, never use them as paths. Authorize before resolving/streaming the file and return 404 for cross-Vendor access.

- [ ] **Step 4: Rerun tests**

  Run: `php artisan test --compact tests/Feature/ProcurementDocumentSecurityTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add app/Actions/Procurement/StoreProcurementDocument.php app/Http/Controllers/Procurement/DocumentController.php app/Http/Requests/Procurement/StoreProcurementDocumentRequest.php config/filesystems.php tests/Feature/ProcurementDocumentSecurityTest.php && git commit -m "feat: secure procurement documents"`

### Task 7: Procurement Inertia pages and end-to-end flow

**Files:**
- Create: `resources/js/Pages/Procurement/ReservationPr/Index.vue`
- Create: `resources/js/Pages/Procurement/ReservationPr/Preview.vue`
- Create: `resources/js/Pages/Procurement/Validation/Index.vue`
- Create: `resources/js/Pages/Procurement/PurchaseOrders/Index.vue`
- Create: `resources/js/Pages/Procurement/Documents/Index.vue`
- Modify: `resources/js/Components/AppSidebar.vue`
- Test: `tests/Browser/procurement-workflow.spec.js`

**Interfaces:**
- Consumes: Tasks 3–6 endpoints.
- Produces: role-aware procurement workflow screens.

- [ ] **Step 1: Write failing browser scenario**

  Cover Warehouse export/upload/preview, Admin validation/revision download, PO upload, document version upload, outstanding-to-complete state, Vendor restricted view/download, processing states, errors by source row, and no stock value change through the full flow.

- [ ] **Step 2: Run and confirm failure**

  Run: `npx playwright test tests/Browser/procurement-workflow.spec.js`

  Expected: FAIL because pages are absent.

- [ ] **Step 3: Implement pages and navigation**

  Reuse the existing Master Import step components where semantics match, but keep procurement batch types and copy distinct from Master PN imports.

- [ ] **Step 4: Run module gate**

  Run: `php artisan test --compact tests/Feature/ProcurementSchemaTest.php tests/Feature/ProcurementRequirementTest.php tests/Feature/ProcurementReservationImportTest.php tests/Feature/ProcurementValidationTest.php tests/Feature/ProcurementPoGrgiTest.php tests/Feature/ProcurementDocumentSecurityTest.php && npm run build && npx playwright test tests/Browser/procurement-workflow.spec.js`

  Expected: PASS.

- [ ] **Step 5: Commit**

  Run: `git add resources/js/Pages/Procurement resources/js/Components/AppSidebar.vue tests/Browser/procurement-workflow.spec.js && git commit -m "feat: add procurement workflow screens"`

