# 1. Title
Finance Period Guard Design Specification

# 2. Status
Status: Draft — Design Review Required

# 3. Purpose
The future Finance Period Guard protects any ledger-affecting operation, beginning with future Inventory Ledger posting, by ensuring:
- Current Property context is resolved server-side.
- Current Property Business Date exists and is Open.
- Calendar year and month derive only from that resolved Business Date.
- Matching Financial Period is resolved by: property_id + business-date year + business-date month.
- Only an explicitly valid Financial Period state may permit future posting.
- Missing, mismatched, duplicate, closed, closing, reopened, or ambiguous states reject deterministically.
- Validation never creates or mutates a Financial Period.

# 4. Scope
The scope of this design specification covers the creation of a read-only Finance Period Guard that protects future ledger postings by verifying that the financial period corresponding to the active business date is open and valid.

# 5. Verified Existing Foundation
- FinancialPeriod is Property-scoped.
- gl_financial_periods has property_id, period_year, period_month, status, audit fields, soft deletes, and a composite unique key.
- FinancialPeriodStatusEnum has Open, Closing, Closed, Reopened.
- CurrentBusinessDateService and CurrentPropertyService already establish server-side Property and Business Date context.
- Existing PeriodControlService has mutating behavior on missing periods and is therefore unsuitable for the future Inventory Ledger posting guard.
- No existing FinancialPeriod test suite was found.

# 6. Critical Preflight Finding
PeriodControlService::isOpen() must not be reused directly by a future Inventory Ledger posting path because it may create a missing Financial Period.

A financial posting guard must fail closed and remain read-only.

The future posting guard must not invoke PeriodControlService::isOpen(), enforceOpen(), or any PeriodControlService method unless that method has been independently reviewed and proven strictly read-only with no implicit Financial Period creation, opening, reopening, update, or other mutation. PeriodControlService is not globally wrong; it remains appropriate for controlled Finance administration workflows.

# 7. Guard Design Principles
- Fail closed.
- No hidden mutation.
- No caller-supplied Property ID, Business Date, year, month, or timezone.
- Resolve context only through CurrentPropertyService and CurrentBusinessDateService.
- Derive year and month only from the active persisted Business Date.
- Query only the current Property scope.
- Do not bypass soft-delete/global scope controls.
- No implicit period creation.
- No automatic reopening.
- No direct posting path may bypass the guard.
- The guard must be reusable for future Inventory Ledger and other ledger-affecting modules.

# 8. Canonical Resolution Flow
1. Resolve current Property from CurrentPropertyService.
2. Resolve active Property Business Date from CurrentBusinessDateService.
3. Reject when Property context is absent.
4. Reject when no Business Date exists.
5. Reject when Business Date is not Open.
6. Derive calendar year/month from the resolved Business Date.
7. Resolve FinancialPeriod using: property_id + period_year + period_month.
8. Reject when no matching Financial Period exists.
9. Reject when more than one active matching Financial Period is observed. (The composite unique constraint is expected to prevent duplicate active period rows for property_id + period_year + period_month. Ambiguous-period handling remains a defensive corruption/data-anomaly rejection rule and must never select an arbitrary record.)
10. Evaluate Financial Period status using explicit policy.
11. Return an immutable successful guard result only when all conditions pass.
12. Do not persist, create, update, reopen, close, or mutate anything during validation.

# 9. Financial Period State Policy
Open     → eligible for future posting
Closing  → reject
Closed   → reject
Reopened → reject by default pending explicit governance decision
Missing  → reject
Ambiguous → reject

Any unrecognized, unsupported, future, corrupted, or otherwise non-explicit Financial Period status must reject deterministically by default. The guard must fail closed.

Reopened must not automatically allow ledger posting: reopening is a controlled finance exception and requires explicit governance, authorization, audit, and eventual ADR-level review if it becomes a long-lived cross-domain posting rule.

# 10. Deterministic Rejection Matrix

| Condition | Guard outcome | Mutation permitted? | Future posting permitted? | Audit / diagnostic requirement |
| :--- | :--- | :--- | :--- | :--- |
| Current Property missing | Reject | No | No | Diagnostic context required |
| Business Date missing | Reject | No | No | Diagnostic context required |
| Business Date closed | Reject | No | No | Diagnostic context required |
| Business Date unexpected state | Reject | No | No | Diagnostic context required |
| Financial Period missing | Reject | No | No | Diagnostic context required |
| A matching, non-soft-deleted, unambiguous Financial Period for the current resolved Property is Open, and every preceding Property and Business Date validation has already passed. | Pass | No | Yes | N/A |
| Financial Period Closing | Reject | No | No | Diagnostic context required |
| Financial Period Closed | Reject | No | No | Diagnostic context required |
| Financial Period Reopened | Reject | No | No | Diagnostic context required |
| Period belongs to another Property | Reject | No | No | Diagnostic context required |
| Duplicate/ambiguous matching period records | Reject | No | No | Diagnostic context required |
| Soft-deleted historical period only | Reject | No | No | Diagnostic context required |
| Attempt to use caller-provided date/property/year/month | Reject | No | No | Diagnostic context required |

# 11. Prohibited Behaviors
- Implementing the Inventory Ledger posting itself.
- Mutating Financial Period records.
- Permitting manual date injection.
- Reusing `PeriodControlService::isOpen()` for ledger posting.
- Invoking `PeriodControlService::isOpen()`, `enforceOpen()`, or any `PeriodControlService` method unless that method has been independently reviewed and proven strictly read-only with no implicit Financial Period creation, opening, reopening, update, or other mutation.

# 12. Proposed Service Boundary
Proposed read-only service:
`Modules\Finance\GeneralLedger\Services\PostingPeriodGuard`
*(Final implementation name remains subject to review.)*

The proposed guard must:
- accept no caller-controlled property/date/period inputs in its primary posting-path method;
- internally use CurrentPropertyService and CurrentBusinessDateService;
- use a read-only query path;
- never call a method that auto-creates Financial Periods;
- return an explicit immutable result or throw domain-specific deterministic exceptions;
- not create a generic cross-module workflow engine.

# 13. Error and Result Semantics
Proposed result/error categories:
- PropertyContextNotResolved
- BusinessDateMissing
- BusinessDateNotOpen
- FinancialPeriodMissing
- FinancialPeriodNotOpen
- FinancialPeriodAmbiguous
- FinancialPeriodScopeMismatch
- PostingPeriodGuardPassed

*(Final exception class names require implementation review.)*

# 14. Required Future Test Coverage
- Open Business Date + Open Financial Period = passes.
- Open Business Date + Missing Financial Period = rejects and does not create a period.
- Open Business Date + Closing Financial Period = rejects.
- Open Business Date + Closed Financial Period = rejects.
- Open Business Date + Reopened Financial Period = rejects by default.
- Closed Business Date + Open Financial Period = rejects.
- No current Property = rejects.
- No Business Date = rejects.
- Financial Period from another Property = rejects.
- Soft-deleted period cannot be used.
- Duplicate/ambiguous period handling rejects deterministically.
- Guard makes no database writes in all pass/reject scenarios.
- Caller cannot inject arbitrary Property ID, date, year, month, or timezone.
- Future Inventory Ledger integration must prove guard invocation before any immutable ledger write.
- Each passing and rejecting guard test must capture FinancialPeriod records before and after invocation, proving that record count, status, opened_at, closed_at, closing_snapshot_at, opened_by, closed_by, created_by, updated_by, and timestamps remain unchanged.

# 15. Security, Authorization, and Audit Considerations
- Period opening/closing/reopening remains a Finance-controlled administrative function.
- A posting guard is not an authorization engine, but must not bypass authorization controls.
- Reopen-related rules require auditability and clear actor attribution.
- Rejection diagnostics must not expose cross-property financial details.
- Every future rejected ledger posting must carry safe diagnostic context.

# 16. Non-Goals
- Building Inventory Ledger
- Building Cost Ledger
- Financial Period UI
- Financial Period creation workflow
- Month-end close implementation
- Reopen workflow implementation
- Accounting journal posting
- GRNI/AP/PPV
- Cost Control UI
- Budgeting, Forecasting, Formal Encumbrance
- Generic event bus or generic outbox
- ADR creation

# 17. Dependencies and Preconditions
- Validated Property Business Date foundation.
- CurrentPropertyService.
- CurrentBusinessDateService.
- FinancialPeriod model/table/status enum.
- Future FinancialPeriod test coverage.
- Explicit implementation approval.
- Future Inventory Ledger implementation must remain blocked until guard implementation and tests pass.

# 18. Implementation Gates
Gate 1 — Design Review:
Specification reviewed and approved.

Gate 2 — Guard Foundation:
Dedicated read-only guard design translated into implementation plan.

Gate 3 — Automated Test Coverage:
FinancialPeriod and guard tests exist and pass.

Gate 4 — No-Mutation Proof:
Tests prove missing/invalid periods never create or alter Financial Period records.

Gate 5 — Future Ledger Integration:
Inventory Ledger posting calls the guard before any ledger persistence.

Gate 6 — Green-State Validation:
PHP suite, PostgreSQL validation where applicable, and npm build pass.

# 19. Open Design Questions
- Should Reopened remain blocked always, or be permitted only through explicit Finance authorization?
- Does Financial Period lifecycle require a separate finance administration workflow spec?
- What exact domain exception hierarchy should be used?
- Should ledger posting rejection write an audit/event record, and where should that responsibility reside?
- Does future Night Audit require a controlled relationship with Financial Period Closing?
- Does the current soft-delete model for FinancialPeriod require further finance governance review?

# 20. Approval Criteria
The specification may be approved only when it:
- treats missing Financial Period as a deterministic rejection;
- prevents all implicit period creation from posting paths;
- uses active Property Business Date as the only source of derived year/month;
- preserves Property isolation;
- has an explicit default rule for every Financial Period status;
- defines no-mutation proof tests;
- keeps Inventory Ledger implementation blocked pending approved implementation plan.
