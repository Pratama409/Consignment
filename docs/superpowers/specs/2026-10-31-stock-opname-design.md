# Stock Opname Design

Status: awaiting approval. Source: raw meeting transcript
(`Transkrip_Meeting_Gabungan_Timestamp_Diperbaiki.txt`, 00:35-01:13) and the
flow document the user shared (section 11, Stock Opname).

## Intent

Warehouse counts what is physically on the shelf; the system compares it with
the system stock and shows `SHORT` / `OVER` / `BALANCE`. A bucket with a
difference is frozen (no transactions) until the difference is resolved. Every
stock movement that happens after a count is logged so the admin can see it
before approving. This is the control that lets consignment stock be trusted
before a Vendor reconciliation (BA).

## Goals

- Admin creates a session: type `FULL`, `PARTIAL` or `CYCLE` (monthly), and its scope.
- Warehouse enters actual quantities **without seeing system quantities**.
- The system computes the difference per bucket and freezes buckets with a difference.
- Admin approves the result; each difference becomes a draft **Koreksi Stok**
  (existing flow) that the admin then approves there. The session completes
  when every difference is posted.
- A printable report: session number, scope, counter (name, position), approver
  (name, position), each bucket (system, actual, difference, status), and the
  movements logged after the count.

## Non-goals

QR/barcode scanning, photo or AI detection, per-unit serial IDs, SAP integration,
Excel import of counts, and different approval chains for FA or audit (the
transcript says only the approval differs; one admin approval for now).

## Anti-goals

Stock changing without a ledger entry; Warehouse seeing system quantity while
counting; a second way to change stock besides posting.

## Decisions (from the user)

- Roles follow the flow document: **admin (GL) creates the session, reviews
  differences, approves and closes it; Warehouse counts; Vendor has no access.**
  Creating the session as Warehouse was considered and not chosen.
- Differences are resolved through the **existing Koreksi Stok** (draft, then
  admin approval), not by a second path that edits stock.

## Approaches

1. **Session and line tables; freeze derived from open difference lines, checked
   inside `PostStock`** — chosen (my call). One source of truth, no duplicated
   state, and the check runs after the bucket's balance lock so concurrent
   postings cannot slip past it.
2. A frozen flag on `stock_buckets` — rejected: duplicates state that can drift,
   and buckets are immutable by design.
3. Reuse `stock_adjustments` as the count — rejected: an adjustment is one line
   with no counting phase.

## Design

**Tables (3NF; item, Vendor and location are read through the bucket):**

- `stock_opname_sessions`: `session_no` (unique), `scope_type`, `cycle_month`
  (nullable), `status` (`COUNTING`, `COUNTED`, `RESOLVING`, `COMPLETED`,
  `CANCELLED`), `created_by`, `approved_by`, `counted_by` (the user who submitted
  the count), `submitted_at`, `approved_at`, `completed_at`, `note`.
- `stock_opname_lines`: `session_id`, `stock_bucket_id`, `counted_quantity`
  (nullable until counted), `system_quantity` (snapshot taken when the count is
  submitted, never shown to Warehouse), `counted_by`, `counted_at`,
  `stock_adjustment_id` (nullable). Unique on `(session_id, stock_bucket_id)`.
  Difference and `BALANCE`/`SHORT`/`OVER` are derived, not stored.

**Flow:** `COUNTING` (Warehouse fills lines) -> Warehouse submits -> `COUNTED`
-> admin approves -> `RESOLVING` (a draft Koreksi Stok per difference line,
reason "Selisih Stock Opname <no>") -> every adjustment posted -> `COMPLETED`.
A rejected adjustment returns its line to counting (recount) and the bucket
stays frozen. Admin may cancel before `RESOLVING`; cancelling unfreezes.

**Freeze:** a bucket is frozen when it has a counted line with a non-zero
difference in a session that is not `COMPLETED`/`CANCELLED` and whose
adjustment is not yet posted. `PostStock` rejects any posting to a frozen bucket
except the posting of the Koreksi Stok that belongs to that line. `BALANCE`
lines do not freeze.

**Log after count:** derived from `stock_ledger_entries` for each counted
bucket after `counted_at`; shown on the approval screen and printed in the
report. No new table.

**Blind count:** enforced server-side. The Warehouse view and its props never
contain `system_quantity` or a computed difference while the session is
`COUNTING`.

**Access:** admin creates, approves, cancels, prints; Warehouse counts and
submits; Vendor receives 403. Every action writes an audit log (`WriteAuditLog`).
The counter and approver positions come from `users.position`.

## Constraints

Both SQLite and MySQL must stay green; concurrency tests run alone with
`STOCK_POSTING_MYSQL_ISOLATED=1`. Modern-soft-ui for screens. TDD; one
whole-branch review at the end.

## Left to the builder

Session number format; the report layout (follow the reconciliation exporter);
what `FULL` pre-selects (all buckets with a balance); screen copy; a decision
when two Warehouse users edit the same line (last write wins, audited).

## Open points (not blocking)

Whether one session may mix STOCK and CONSIGNMENT buckets (assumed yes);
whether admin approval should be split from session creation by a different
user later (deferred).
