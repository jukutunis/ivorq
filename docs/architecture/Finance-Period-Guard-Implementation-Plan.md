# 1. Title
Finance Period Guard Implementation Plan

# 2. Status
Status: Draft — Implementation Review Required

# 3. Purpose
This implementation plan outlines the steps and boundaries for developing the Finance Period Guard. This guard protects any ledger-affecting operation (beginning with future Inventory Ledger posting) by enforcing that the Financial Period corresponding to the active Property Business Date is valid, open, and cleanly resolved.

# 4. Governing Design Inputs
This implementation plan is governed by the committed `Finance-Period-Guard-Design-Specification.md` design document. This implementation plan does not supersede the Design Specification and may not alter its principles.

# 5. Current Repository Evidence
- Canonical FinancialPeriod model:
  Modules\Finance\GeneralLedger\Models\FinancialPeriod
- Financial period table:
  gl_financial_periods
- Property-scoped via property_id and BelongsToProperty.
- Unique key:
  property_id + period_year + period_month
- Statuses:
  Open, Closing, Closed, Reopened
- CurrentBusinessDateService resolves active Business Date through CurrentPropertyService.
- No FinancialPeriod test suite existed during preflight.
- Existing PeriodControlService may mutate by creating a missing period.

# 6. Implementation Objective
The objective is to implement a strict, read-only Finance Period Guard that ensures deterministic validation of the active Financial Period before any ledger persistence, failing closed on any invalid, missing, or ambiguous state without mutating data.

# 7. Proposed File-Level Change Boundary

| Candidate file or area | Purpose | Create / Modify | Why required | Must remain untouched |
| :--- | :--- | :--- | :--- | :--- |
| `Modules\Finance\GeneralLedger\Services\PostingPeriodGuard.php` | Implements the read-only guard | Create | Core business requirement for posting validation | No other existing guard logic |
| `Modules\Finance\GeneralLedger\Exceptions\` | Custom deterministic domain exceptions | Create | Deterministic rejection signaling | N/A |
| `tests\Feature\Finance\` | Comprehensive no-mutation tests | Create | Verification of guard behavior and safety | N/A |

*Note: Actual filenames, exception classes, and test locations require implementation review.*

**The following must remain untouched:**
- existing PeriodControlService unless separately approved;
- FinancialPeriod migration/table schema unless a separate schema gap is proven;
- Business Date committed foundation;
- Inventory Ledger;
- Cost Ledger;
- Cost Control;
- ADRs;
- package/config/environment files.

# 8. Proposed Service Contract
The primary posting-path method will feature:
- no caller-supplied Property ID;
- no caller-supplied Business Date;
- no caller-supplied year/month;
- no caller-supplied timezone;
- server-side current Property resolution;
- server-side current Business Date resolution;
- read-only FinancialPeriod lookup;
- immutable pass result or deterministic domain rejection.

# 9. Proposed Read-Only Resolution Algorithm
1. Resolve current Property from CurrentPropertyService.
2. Resolve active Open Property Business Date from CurrentBusinessDateService.
3. Derive year/month from the persisted Business Date.
4. Query FinancialPeriod only for current Property + year + month.
5. Reject missing, soft-deleted-only, duplicate/ambiguous, mismatched, or invalid status results.
6. Permit only an explicit qualifying Open period.
7. Return immutable success result.
8. Make zero database writes.

No query may silently create a FinancialPeriod.

# 10. PeriodControlService Separation Rule
The implementation must not call PeriodControlService::isOpen(),
PeriodControlService::enforceOpen(), or any PeriodControlService method
from the posting path unless a separate read-only audit and explicit approval
prove that invocation cannot mutate state.

# 11. Proposed Exception and Result Boundary
The guard will return an explicit immutable result or throw dedicated domain exceptions reflecting precise rejection states (e.g., missing property, closed business date, missing financial period, invalid period state, ambiguous match). 

The primary posting-path guard queries only the active, non-soft-deleted
FinancialPeriod records within the server-side resolved current Property scope.

If no qualifying FinancialPeriod exists in that scope, the guard returns
FinancialPeriodMissing.

The primary posting guard must not query another Property to distinguish
whether a Financial Period exists elsewhere. It must not expose whether
another Property has a corresponding Financial Period.

The following are prohibited in the primary posting path:
- withoutGlobalScopes()
- cross-property lookup
- explicit query for another property_id
- any diagnostic that reveals another Property's period existence or status

The primary posting-path guard must use normal active-record scopes only.

It must not use withTrashed() or otherwise inspect soft-deleted FinancialPeriod
records in order to classify a rejected posting attempt.

A missing active FinancialPeriod, including a case where only soft-deleted
historical records might exist, must resolve as FinancialPeriodMissing.

Any persisted FinancialPeriod status that cannot be mapped safely to the
expected FinancialPeriodStatusEnum, is corrupted, unsupported, unknown, or
otherwise invalid must reject deterministically as FinancialPeriodInvalidState.

The posting guard must not allow a raw enum-casting error, ValueError,
database error, or raw persisted status value to escape as a user-facing
diagnostic. 

# 12. Required Test Matrix
1. Current Property A has an Open FinancialPeriod:
   Property A + matching Business Date passes.

2. Current Property B has no matching active FinancialPeriod:
   Property B rejects as FinancialPeriodMissing,
   even if Property A has a matching FinancialPeriod.

3. The primary posting guard performs no cross-property lookup.

4. A soft-deleted FinancialPeriod must not qualify.
   The primary posting guard must return FinancialPeriodMissing without
   using withTrashed() or revealing the historical record.

5. A corrupted, unknown, or enum-unmappable persisted period status rejects as
   FinancialPeriodInvalidState without exposing raw database values.

# 13. No-Mutation Verification Strategy
6. No-mutation tests must prove no INSERT, UPDATE, or DELETE query occurs in
   any pass or rejection path.

7. Before/after assertions must prove FinancialPeriod and PropertyBusinessDate
   record counts, statuses, timestamps, and audit columns remain unchanged.

# 14. Property Isolation Strategy
The guard inherently queries the FinancialPeriod table using the server-side resolved Property context via CurrentPropertyService, ensuring queries cannot cross-pollinate properties. The test suite must assert that a FinancialPeriod from a different property deterministically rejects validation.

# 15. Financial Period State Handling
Any state other than explicitly 'Open' rejects validation. Ambiguous, missing, or unrecognized status evaluates to deterministic rejection, failing closed by default.

# 16. PostgreSQL and SQLite Validation Strategy
- Standard suite may use SQLite where repository configuration requires it.
- Guard tests must run on normal standard test configuration.
- PostgreSQL validation must be repeated on ivorq_test once implementation exists.
- PostgreSQL validation is mandatory whenever FinancialPeriod state constraints, soft-delete behavior, unique indexes, or driver-specific query semantics are involved.
- No test may silently skip PostgreSQL-specific behavior without an explicit environment gate and a separate PostgreSQL validation run.

# 17. Inventory Ledger Integration Boundary
The plan does not authorize Inventory Ledger implementation.

A future Inventory Ledger posting slice may start only after:
1. Finance Period Guard implementation is approved;
2. all guard tests pass;
3. no-mutation proof passes;
4. PostgreSQL validation passes;
5. npm build passes;
6. a separate Inventory Ledger implementation plan is approved.

# 18. Explicit Non-Goals
- Implementing actual posting functionality.
- Modifying Financial Period administration.
- Writing Inventory Ledger code.
- Mutating Financial Period status automatically.

# 19. Implementation Sequence
Phase 0 — Final implementation review
Phase 1 — Guard contract and exception/result design
Phase 2 — Automated FinancialPeriod test foundation
Phase 3 — Read-only PostingPeriodGuard implementation
Phase 4 — No-mutation and Property isolation proof
Phase 5 — PostgreSQL validation on ivorq_test
Phase 6 — Green-state validation
Phase 7 — Separate review before Inventory Ledger preflight

# 20. Implementation Gates
Gate 0 — Property Isolation Review:
Primary guard performs only current-Property active-record queries and has no
cross-property or withTrashed lookup.

Gate 1 — Implementation Review:
Narrow file boundary and error/result semantics approved.

Gate 2 — Test Foundation:
FinancialPeriod and guard tests exist before guard behavior is approved.

Gate 3 — Read-Only Guard:
Guard uses CurrentPropertyService and CurrentBusinessDateService only, makes
zero FinancialPeriod or PropertyBusinessDate writes, and never invokes a
mutating PeriodControlService method.

Gate 4 — Isolation and No-Mutation Proof:
Tests prove no cross-property lookup, no soft-deleted-history lookup, no
INSERT/UPDATE/DELETE, and no mutation of audit fields.

Gate 5 — PostgreSQL Validation:
Guard tests execute on ivorq_test, including state, soft-delete, enum, and
unique-index-sensitive scenarios.

Gate 6 — Green-State Validation:
Standard PHP suite and npm build pass.

Gate 7 — Separate Inventory Ledger Review:
Inventory Ledger remains blocked until a separate implementation plan is
approved.

# 21. Open Technical Questions
- Should domain exceptions be centrally gathered or localized to the GeneralLedger exception boundary?
- What precise transaction isolation level should be required for future ledger posting that consumes the guard?

# 22. Approval Criteria
The plan can be approved only when:
- it preserves the Draft Design Specification;
- it prohibits implicit FinancialPeriod creation;
- it has a narrow file boundary;
- it requires full FinancialPeriod and guard test coverage;
- it requires no-mutation proof;
- it preserves Property isolation;
- it keeps Inventory Ledger blocked;
- it requires PostgreSQL validation and npm build validation.
