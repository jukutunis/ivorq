# Architecture Audit: Sprint 12.6.3 Reconciliation Constraints

## 1. Context & Investigation Objective
Following the forensic root cause analysis of the test suite failure (`ReconciliationSessionModuleTest::test_bank_statement_line_cannot_be_matched_twice`), a Phase 3 Architecture Audit was triggered to investigate whether Sprint 12.6.3 intentionally introduced support for partial reconciliation, split settlement, many-to-many matching, or bank statement allocation.

The migration in question is:
`2026_06_15_150048_sprint_12_6_3_add_audit_fields_to_reconciliation_matches.php` (Commit: `41bbc418`)

## 2. Document & Repository Audit Findings

An exhaustive search of the repository's documentation (`Finance PRD`, `Banking-Hardening-Review.md`, `Reconciliation-Session-Foundation-Review.md`, and ADRs) was conducted. 

### A. Was the unique constraint intentionally removed?
**Conclusion: No (Architecturally), Yes (Mechanically).**
The migration code explicitly executed `$table->dropUnique(...)`. However, the commit message (`feat(banking): implement reconciliation commit layer`) and the surrounding code changes indicate the primary goal was adding audit fields (`match_method`, `matched_by`, `override_reason`). The developer likely dropped the unique constraints to replace them with non-unique indexes (`$table->index('bank_statement_line_id')`) without realizing the architectural severity, or as an undocumented rogue attempt to support partial matching. There is **zero** documentation, ADR, or PRD approving this architectural shift.

### B. Was partial reconciliation approved?
**Conclusion: No.**
The approved business rules explicitly forbid it.
According to `docs/reviews/Reconciliation-Session-Foundation-Review.md` (Sprint 10.4C) and `docs/audits/Banking-Hardening-Review.md`:
*   **BR-007**: BankStatementLine can only be matched once (enforced by DB unique index).
*   **BR-008**: PaymentVoucher can only be reconciled once (enforced by DB unique index).

### C. Which implementation aligns with enterprise accounting systems?
**Analysis:**
True enterprise accounting systems (e.g., SAP, Oracle NetSuite, Workday) **do** support partial reconciliation, split settlements, and many-to-many statement allocation to handle banking fees, currency discrepancies, and consolidated payments. 
However, implementing partial reconciliation requires a massive structural shift:
1.  Introduction of an `Allocation` or `Settlement` table.
2.  Fractional amount tracking (`amount_applied`, `amount_remaining`).
3.  Complex UI changes for the Matching Engine.

### D. Which option preserves IVORQ architecture consistency?
**Analysis:**
Restoring the unique constraints preserves the current IVORQ architecture. 
The entire test suite, the `ReconciliationSessionService`, the matching engine heuristics, and the approved PRDs were built under the strict assumption of 1-to-1 matching (BR-007 & BR-008). Dropping database constraints without upgrading the application layer creates a severe data integrity vulnerability (race conditions allowing double-matching) while providing no actual UI/Application support for partial reconciliation.

## 3. Architecture Decision

**Decision:** Reject the architectural drift introduced in Sprint 12.6.3. 

The removal of the unique constraints in `sprint_12_6_3` is classified as a **Source Code Defect / Unauthorized Architecture Deviation**. The system must enforce 1-to-1 matching at the database layer until a formal ADR and PRD for "Partial Reconciliation" is designed, approved, and fully implemented across the application stack.

## 4. Business Rule Analysis

*   **Current State:** 1-to-1 Matching (BR-007, BR-008).
*   **Sprint 12.6.3 State:** Database allows many-to-many, but Application/Tests assume 1-to-1. (Inconsistent / Vulnerable).
*   **Enterprise Target State:** Partial/Split Reconciliation. (Deferred to future roadmap).

## 5. Recommended Resolution

To restore system integrity and pass the test suite, the following remediation is strictly recommended:

1.  **Do NOT modify the tests.** The tests correctly assert the approved business rules (BR-007, BR-008).
2.  **Revert the Migration Defect:** Create a new corrective migration (e.g., `sprint_12_6_4_restore_reconciliation_unique_constraints.php`) or modify the existing one (if not deployed to production) to restore:
    *   `UNIQUE(bank_statement_line_id)`
    *   `UNIQUE(matchable_type, matchable_id)`
3.  **Future Roadmap:** If partial reconciliation is required for IVORQ Enterprise, it must be introduced via a formal Architecture Decision Record (ADR) and a dedicated feature sprint, completely overhauling the matching engine rather than quietly dropping database constraints.
