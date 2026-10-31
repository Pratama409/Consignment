# Schema Normalization to 3NF and Bug Sweep — Design

Date: 2026-10-31 · Branch: `feature/consignment-operations`

## Intent

The operational schema grew quickly and now stores facts in more than one place:
a bucket's item, Vendor, and location are repeated on every ledger row alongside
a derived `bucket_key`; transaction lines repeat `item_id` that `vendor_item_id`
already determines; applicant identity repeats on every material request. Each
copy can disagree with its source, and today only triggers and application code
keep them in sync. The goal is a schema where every non-key column depends on
the key, the whole key, and nothing but the key (3NF), plus a sweep that fixes
the bugs this exposes. Existing data is dummy and may be discarded.

## Goals

- Remove every derivable or transitively dependent column listed under *Changes*.
- Keep all integrity guarantees: immutable ledger, nonnegative balances, FIFO
  lots, idempotency keys, quantity range guards — on both MySQL and SQLite.
- Fix bugs found while doing so. Already fixed: Vendor dashboard filtered
  `procurement_requirements.vendor_id`, a column that does not exist.
- Add a schema test that fails if a removed redundant column reappears.

## Non-goals

- No data migration or backfill. No change to Master Data tables already on `main`.
- No behavior change visible to users.
- No `migrate:fresh` on the application database `consignment` without explicit
  approval at that moment. Tests run on `consignment_testing` and SQLite only.

## Anti-goals

- A ledger that is easier to mutate than today. Normalization must not weaken
  immutability or the bucket guards.
- Two schema histories. The unmerged migrations are edited in place.

## Approaches considered

- **A. Edit unmerged migrations in place (chosen).** Clean history, no backfill
  code; possible because those migrations never reached `main`.
- B. Additive forward migrations with backfill — wasted work for dummy data.
- C. One migration that drops and recreates affected tables — leaves two
  conflicting schema versions.

## Changes

Rules: a row says each fact once. Where a fact is derivable by join, the column
is removed and the join is provided by a model relation or query.

1. **`stock_buckets` (new).** `id`, `item_id` (nullable), `vendor_item_id`
   (nullable), `location_id`, timestamps. Exactly one of `item_id` (internal
   STOCK) or `vendor_item_id` (CONSIGNMENT) is set; `unique(item_id,
   location_id)` and `unique(vendor_item_id, location_id)`. Because only one side
   is stored, a consignment bucket's item is reached through `vendor_item`, so
   no transitive dependency remains. `bucket_key` and its string-building guards
   are removed; uniqueness is carried by the two unique indexes.
2. **Ledger family.** `stock_ledger_entries`, `stock_balances`, `stock_lots`
   and `opening_balance_lines` drop `item_id`, `vendor_item_id`, `location_id`,
   `bucket_key` (and `vendor_id` on the ledger) and gain `stock_bucket_id`
   (FK, restrict). `stock_balances` becomes `unique(stock_bucket_id)`. Triggers
   for precision, sign, immutability, and REPLACE protection are kept; the
   bucket/vendor-snapshot triggers are replaced by one `stock_buckets` guard
   (XOR rule, STOCK items must be `ownership_type = STOCK`, vendor items must be
   CONSIGNMENT). Opening lines get-or-create the bucket at preview time.
3. **Line tables that repeat `item_id`.** Drop `item_id` where `vendor_item_id`
   is required (`vendor_reconciliation_lines`; `procurement_requirements` —
   its `vendor_item_id`, `item_id`, `location_id` all come from
   `material_issue_line_id`). Where a line may be STOCK or CONSIGNMENT
   (`manual_receipt_lines`, `stock_adjustments`) apply the same XOR rule as
   `stock_buckets`. `required_quantity` on a requirement is dropped if the plan
   confirms it always equals the issue line's `issued_quantity`.
4. **Reference documents.** New header table for procurement reference documents
   (stage, version, `document_no`); `procurement_document_lines` references it
   instead of repeating `document_no` on every line. One document may cover many
   requirements and one requirement may have many documents (transcript §8), so
   the link stays many-to-many through the lines. Re-uploading must update the
   matching header/lines without duplicating them. Issue date stays separate from
   upload time. Name must not collide with the existing file table
   `procurement_documents`. In the meeting (02:12) GRGI is entered per PO, so a
   GRGI header references its PO header as parent; the plan checks whether the
   current per-requirement `GRGI` lines can derive that parent instead of
   storing the PO number again.
5. **Refill receipts.** Drop `vendor_refill_receipts.vendor_refill_id` only if
   `vendor_refill_shipment_id` is NOT NULL, because the refill is then reached
   through the shipment. If a receipt may exist without a shipment (partial or
   urgent incoming), keep the column. The plan verifies this, and also whether
   `vendor_do_number` on receipts and `vendor_document_no` on shipments are the
   same fact before merging them.

## Deliberately not normalized (historical snapshots)

`operational_shifts` (code, name, window) and `stock_transactions.operational_shift_id`
are fixed at posting time. `vendor_reconciliation_lines` quantities are an
immutable BA snapshot. `material_request_approvals`/`revisions` share a
`revision_no` by design of their versioning. `material_requests.applicant_*`
(name, NRP, phone) are what the applicant declared at request time: the form is
public, so an `applicants` table keyed by NRP would let anyone who types another
person's NRP rewrite that person's earlier requests, and the employee-identity
source (SS6) is still undecided. These stay, with a comment each.

## Transcript alignment

Checked against `docs/analisis-video-meeting-proyek-2026-10-29.md`:
- §6 per-Vendor consignment balance by `vendor_item_id` + location: matches
  `stock_buckets`.
- §6 history keeps the location at the time of the event, so the Vendor's default
  location edit never moves history: `stock_buckets.location_id` and
  `material_issue_lines.location_id` hold the actual location, never the
  `vendor_items` default. Dropping `procurement_requirements.location_id` is safe
  only because it resolves to the issue line's location.
- §6 no delete of posted history, reversal instead: unchanged.
- §8 one document is not always 1:1 with a request: reflected in change 4.
- §C employee identity, departments, and approval chain are a later stage and are
  not touched here.

## Bug sweep

After each step run: SQLite suite, MySQL suite (`phpunit.mysql.xml`), Pint, build,
and the Playwright suite. SQLite already passed a query on a nonexistent column
that MySQL rejected, so MySQL is the gate for schema work. Any failure is
root-caused before a fix. A final read-through of controllers, queries, and
exporters checks that none still reads a removed column.

## Order of work

1. Schema test (fails first), then `stock_buckets` and the ledger family, with
   `BuildStockBucket`, `PostStock`, `ReverseStockTransaction`,
   `SaveOpeningBalanceBatch`, `SystemStockQuery`, `VendorReconciliationSourceQuery`,
   and the stock models updated.
2. Line tables and procurement requirements.
3. Reference-document header and refill receipts.
4. Work Order number and Backcharge flag on Data OUT.
5. Demo data seeder (dummy Vendors, items, locations, an opening balance, a
   sample refill and request) so the reset database is demo-ready, then the bug
   sweep and final review.

Each step ends green on both engines before the next starts.

## Left to the builder

Exact table and index names beyond those above; the relation/query shape that
replaces removed columns; whether `required_quantity` and `vendor_do_number`
are droppable (verify against the code, then decide and note it in the plan).

## Meeting gaps found

Checked against the raw meeting transcript. The user needs the web for a demo, so:
- **In scope, last step (after normalization is green):** Data OUT gets a Work
  Order number and a Backcharge (BC) flag (01:13–01:15; WO is per
  unit/department and separate from the reservation number). My calls:
  `material_issues.work_order_no` (nullable string, one WO per issue) and
  `material_issue_lines.is_backcharge` (boolean, per line since lines can differ).
  Both are shown on the Data OUT form and detail, included in Monitoring
  exports, and covered by tests. Backcharge is a flag only; no billing logic.
- **Out of scope, next project:** Stock Opname (system vs actual, short/over
  freeze, log of changes after a count; 00:35–00:59). `stock_adjustments` covers
  corrections only. It needs its own brainstorm and spec.
- `vendors.pic_name` stays: one Vendor has one PIC (00:28), so a separate PIC
  table would add a join without removing any repetition.

## Calls I made (please check)

- Ledger is normalized now, since data is disposable.
- `stock_buckets` stores item *or* vendor item, not both, to be strictly 3NF.
- Applicant fields stay on `material_requests` as an event-time snapshot (dropped
  the earlier `applicants` idea; see the exclusion above).
- Edit unmerged migrations in place rather than adding new ones.
- No commit made; nothing is committed until you say so.
