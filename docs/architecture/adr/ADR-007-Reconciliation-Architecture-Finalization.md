# ADR 007: Reconciliation Architecture Finalization

## Date
2026-06-20

## Status
Approved

## Context
During Sprint 12.6.3, "Partial Reconciliation" methods were introduced (`commitSplit` and `commitMerge`) alongside strict 1-to-1 reconciliation. This partial reconciliation hypothetically allowed splitting (one bank line to multiple IVORQ documents) and merging (multiple bank lines to one IVORQ document). However, the underlying database schema enforced strict unique constraints on `bank_statement_line_id` and the polymorphic `matchable_type`/`matchable_id` pair. 

A subsequent Final RCA confirmed that the UI, API, and all production controllers only support 1-to-1 matching. The `commitSplit()` and `commitMerge()` service methods were never wired to production workflows and represented isolated "dead code" that could only be invoked via test fixtures. The tests for these methods actively failed because they violated the active database constraints.

## Decision
We will finalize the active architecture as **strict 1-to-1 reconciliation**. 

## Business Rules
1. A single bank statement line can only match exactly one IVORQ entity (e.g., Vendor Payment, Customer Receipt).
2. A single IVORQ entity can only match exactly one bank statement line.
3. Partial payments or aggregated deposits must be resolved operationally within the general ledger (via Journal Entries or split payments in Accounts Payable) before hitting the bank reconciliation engine.

## Rejected Alternatives
* **Removing Unique Constraints**: Rejected. Removing the database constraints to legalize `commitSplit` and `commitMerge` would expose the system to double-matching data corruption and bypass the established business rules.
* **Building Partial Reconciliation UI**: Rejected. There is no current business requirement for partial reconciliation. IVORQ operates on a strict single-source-of-truth ledger where fractional allocations happen prior to bank settlement.

## Impact Analysis
- **Codebase**: `ReconciliationCommitServiceTest` is updated to assert that `commitSplit` and `commitMerge` correctly throw `Illuminate\Database\QueryException` due to constraint violations, verifying the boundary failure.
- **Database**: The strict unique constraints remain active and untouched.
- **Operations**: The reconciliation UI continues to enforce 1-to-1 mapping.

## Governance Approval Rationale
This ADR aligns the application layer with physical reality. It prioritizes data integrity (via database constraints) over speculative features (dead code). By cementing the 1-to-1 architecture, we eliminate test fragility and formally adopt the proven, battle-tested design that the platform actively utilizes.
