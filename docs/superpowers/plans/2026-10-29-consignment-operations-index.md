# Consignment Operations Implementation Plan Index

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved eight-part Consignment operational scope as five independently reviewable, sequential plans.

**Architecture:** The plans build a modular monolith around one immutable inventory ledger. Each later plan consumes explicit interfaces and events produced by earlier plans, so stock is never recalculated independently by Refill, Data OUT, Procurement, or Monitoring.

**Tech Stack:** PHP 8.3, Laravel 13, Inertia 3, Vue 3, MySQL/SQLite, PhpSpreadsheet 5.10, PHPUnit 12, Tailwind CSS 4, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-29-consignment-operations-design.md`

## Global Constraints

- Execute in the order below; do not start a dependent plan until its predecessor passes its verification gate.
- Preserve existing Master Data behavior and unrelated dirty-worktree changes.
- Use additive UUID migrations and test-first changes; never run destructive database operations.
- Do not migrate the user's main database or deploy production without explicit instruction.
- At every plan boundary run the plan's complete verification gate and record actual output.

## Review Focus

- Cross-plan event names and action signatures must remain exactly consistent with the producing plan.
- Refill/Incoming, Data OUT, Procurement, and BA must never implement independent balance mutation.
- Vendor scope must apply to backend query, detail, export, and download—not only navigation.
- Every retryable posting/import/finalization action needs a unique idempotency boundary.
- Existing user data and Master Data relationships must survive every forward migration.

---

## Execution order

1. [Identity and Inventory Foundation](2026-10-29-identity-inventory-foundation.md)
   - Covers requested parts 1–4: role/user management, shifts, opening balance,
     ledger, posting, idempotency, audit, adjustment, and reversal.
2. [Refill, Incoming Refill, and Data IN](2026-10-29-refill-incoming-data-in.md)
   - Covers requested part 5.
3. [Material Request and Data OUT](2026-10-29-material-request-data-out.md)
   - Covers requested part 6 and produces immutable procurement row sources.
4. [Procurement Document Workflow](2026-10-29-procurement-document-workflow.md)
   - Covers requested part 7: Reservasi/PR, validation, PO, GRGI, and documents.
5. [Monitoring and Reconciliation](2026-10-29-monitoring-reconciliation.md)
   - Covers requested part 8: transaction/shift recap, Stock System, and BA.

## Cross-plan contracts

- `PostStockIn::handle(StockPostingData): StockTransaction` is used by opening
  balance, Incoming Refill, and manual Data IN.
- `PostStockOut::handle(StockPostingData): StockTransaction` is used only by
  Data OUT.
- `StockTransactionReversed` lets Refill and MR restore workflow counters after
  the ledger reversal commits.
- `MaterialIssuePosted` creates one procurement requirement per consignment
  issue line; `MaterialIssueReversed` marks references for review/void.
- Monitoring reads ledger, issue, refill, and procurement records but never
  mutates balances.

## Final completion gate

- [ ] All five plans completed in order with task-level commits/reviews.
- [ ] `php artisan test --compact` passes.
- [ ] `php vendor/bin/pint --test` passes.
- [ ] `npm run build` passes.
- [ ] `npm run test:browser` passes.
- [ ] `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml` passes on the
      isolated MySQL test database.
- [ ] Migration and UUID checks pass without touching the user's main database.
- [ ] A final review confirms the eight requested parts and three roles match
      the approved spec.

