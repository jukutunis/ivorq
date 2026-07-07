# ADR-076: Banking Account Migration Pilot Authorization Architecture

**Status:** Accepted
**Date:** 2026-07-07

## Context

ADR-073 established the architecture policy for Banking legacy-to-controlled migration and cutover. ADR-074 delivered the Sprint 31 migration control plane: `BankingMigrationPlan`, `BankingMigrationManifestEntry`, and `BankingMigrationExceptionQuarantine`. ADR-075 delivered Sprint 32 human-governed account-level `BankingMigrationTargetIntake` mapping proposals with independent Finance Controller review.

Sprint 33 now introduces a control-plane-only Pilot Authorization workflow. It records that a Finance Manager requested a pilot authorization review for an already review-accepted account-level Mapping Proposal, and a separate, independent Finance Controller reviewed that request. Pilot authorization is a control-plane governance record only. It never authorizes migration execution, target write, bridge activation, property cutover, legacy freeze, or operational Banking mutation.

## Mandatory ADR Baseline

| ADR | Title | Key relevance |
|---|---|---|
| ADR-004 | Finance Module Boundary Architecture | Banking owns bank account and bank statement evidence |
| ADR-007 | Reconciliation Architecture Finalization | Strict 1-to-1 reconciliation invariant |
| ADR-052 | Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture | Controlled Banking is forward path |
| ADR-056 | Banking Legacy Isolation and Manual Evidence Coexistence Architecture | Legacy isolation policy |
| ADR-063 | Polyglot Specialized Services Boundary | No Go/Python; no direct PostgreSQL |
| ADR-064 | FX Operational Access Segregation and Finance Role Configuration | Role and permission assignment convention |
| ADR-070 | Banking Operations Workspace and Controlled Bank Execution | Controlled Banking web workspace |
| ADR-071 | Bank Reconciliation Session Lifecycle Integration | Read-only session evidence |
| ADR-072 | Banking Reconciliation Domain Convergence and Legacy Isolation | Cross-domain non-relationship proven |
| ADR-073 | Banking Legacy-to-Controlled Migration and Cutover Architecture | Migration architecture policy baseline |
| ADR-074 | Banking Legacy-to-Controlled Migration Implementation Foundation | Sprint 31 control plane decisions |
| ADR-075 | Banking Target Intake and Human-Governed Mapping Architecture | Sprint 32 target intake decisions |

## Decisions

### 1. Sprint 33 Control-Plane-Only Scope

Sprint 33 authorizes only a Banking-owned pilot authorization **control plane**. A pilot authorization is a governance record that documents a human request for independent authorization review and its outcome. It never executes data migration, creates target records, activates an operational bridge, or authorizes cutover.

Even after pilot authorization review acceptance, all records must remain:

```
MIGRATION_EXECUTION_NOT_IMPLEMENTED
CUTOVER_NOT_AUTHORIZED
```

No Sprint 33 status, route, service, permission, UI control, or controller action may convert those values into data-plane authority.

### 2. Required Predecessor Chain

Every Pilot Authorization must reference:

1. An existing same-property `BankingMigrationPlan`
2. An existing `BankingMigrationManifestEntry` under that plan
3. An existing `BankingMigrationTargetIntake` under that plan/manifest
4. The Target Intake must have status `REVIEW_ACCEPTED`
5. The underlying Manifest Entry must have source model exactly `BankAccount`
6. The Target Intake must reference an existing same-property active `ControlledBankAccount`

### 3. Account-Level Source Restriction

The source Manifest Entry must be `BankAccount` only. No statement-line, reconciliation, payment, or journal migration scope is authorized.

### 4. Mapping Proposal Acceptance vs Pilot Authorization Review

Mapping Proposal review acceptance (Sprint 32) is a planning governance action: a Finance Controller accepted that a proposed target mapping is a reasonable planning decision. Pilot Authorization review (Sprint 33) is a separate independent governance action: a different Finance Controller independently reviews whether a pilot authorization request should be accepted. Both are control-plane only. Neither authorizes execution.

### 5. Explicit Human Request and Independent Authorization Review

A Finance Manager requests pilot authorization. A separate Finance Controller independently reviews. The authorization reviewer must differ from the Target Intake review actor and from the Pilot Authorization request actor.

### 6. Non-Operational Pilot Authorization

`REVIEW_ACCEPTED` means only:

```
Independent control-plane review accepted.
No migration execution, target write, bridge activation, or cutover is authorized.
```

### 7. No Operational Banking Service Consumption

No review-accepted authorization record may be consumed by `PaymentExecutionService`, `ManualBankReconciliationService`, `ReconciliationSessionService`, `ReconciliationFinalizationService`, `BankingSourceEvidenceService`, or any other operational Banking service.

### 8. No Source/Target Operational Record Changed

No legacy `BankAccount` record is modified. No `ControlledBankAccount` record is created or modified. No Migration Plan, Manifest Entry, Quarantine, or Target Intake record is modified. All request/review writes remain inside the new `banking_migration_pilot_authorizations` table only.

### 9. No Source/Target Details, Financial Values, or Comparison Data Stored

Pilot Authorization must not store:
- legacy account number, bank name, account name, or external reference
- controlled account number, bank name, account name, or external reference
- source or target balance
- source or target amount
- currency comparison
- vendor reference
- GL account reference
- candidate score, confidence score, mapping score, or migration score
- target recommendation
- source/target side-by-side comparison data

### 10. Property Scope and Target Active-Status Revalidation

Every Pilot Authorization is property-scoped. The controlled target account must be revalidated as same-property and active at request time and review time.

### 11. Local Duplicate/Idempotency Behavior

The same Target Intake may not have more than one active (non-archived) Pilot Authorization. Duplicate requests return the existing authorization (idempotency). A previously archived authorization does not prevent a new request. Concurrent requests cannot create duplicate active authorizations (lock-based prevention).

### 12. Review Audit and Correlation Requirements

Every Pilot Authorization records:
- correlation ID (ULID, server-generated and immutable)
- request actor (server-resolved authenticated Finance Manager)
- review actor (server-resolved authenticated Finance Controller, when review occurs)
- review outcome (server-owned)
- review timestamp (server-generated)
- audit columns (created_by, updated_by, timestamps)

### 13. Permission and Role Boundary

Three permissions are relevant:

| Permission | Role(s) | Sprint 33 authority |
|---|---|---|
| `finance.banking.migration.view` | Finance Controller / Finance Manager | View pilot authorization evidence |
| `finance.banking.migration.manage` | Finance Manager | Request pilot authorization only |
| `finance.banking.migration.mapping.review` | Finance Controller | Existing Mapping Proposal review only |
| `finance.banking.migration.pilot.authorization.review` | Finance Controller | Review pilot authorization independently |

| Role | View | Request | Mapping Review | Pilot Authorization Review |
|---|---|---|---|---|
| Finance Manager | Yes | Yes | No | No |
| Finance Controller | Yes | No | Yes | Yes |
| General Ledger Accountant | No | No | No | No |
| Accounts Payable Officer | No | No | No | No |
| General Cashier | No | No | No | No |
| Super Admin / Property Admin | Yes (via Permission::all()) | Yes (via Permission::all()) | Yes (via Permission::all()) | Yes (via Permission::all()) |

No new role is created.

### 14. Mandatory Three-Actor Separation

The following identities must be distinct:

- Target Intake `proposal_actor_id`
- Target Intake `review_actor_id`
- Pilot Authorization `request_actor_id`
- Pilot Authorization `review_actor_id`

Enforced rules:
- `pilot_authorization.review_actor_id != pilot_authorization.request_actor_id`
- `pilot_authorization.review_actor_id != target_intake.review_actor_id`

If no independent Finance Controller exists, the request remains `REQUESTED`. Do not weaken the actor-separation rule.

### 15. Confirmation Policy

Pilot authorization request and review are non-financial control-plane governance operations. No sensitive action confirmation is required. No confirmation intent is created or consumed.

### 16. Future Execution-Package Preconditions

Before any review-accepted pilot authorization can be consumed for actual migration execution, a future Sprint must deliver:
- Migration execution service with idempotency
- Legacy-to-controlled record write with provenance
- Property-level cutover authorization and status
- Rollback/correction path
- Legacy freeze/read-only enforcement
- Post-cutover reconciliation treatment
- Operational sign-off and approval authority

### 17. No Go/Python/Event Bus/Direct PostgreSQL Rule

Per ADR-063:
- No Go or Python worker, service, or migration process
- No direct PostgreSQL migration process
- No message broker or event bus
- No microservice extraction
- All control-plane writes use approved Laravel service boundaries only

### 18. Wave 1 and Wave 2 Implementation Manifest

| Wave | Content |
|---|---|
| Wave 1 (this ADR) | `BankingMigrationPilotAuthorization` aggregate, non-executable state machine, permissions, policy, read-only workspace projection |
| Wave 2 (conditional) | Finance Manager request action, independent Finance Controller review action, property-scoped request/review queue, immutable audit evidence |
| Future data-plane migration | NOT AUTHORIZED |

### 19. Explicit Data-Plane Migration/Cutover Deferral

Sprint 33 does not authorize:
- Runtime migration
- Pilot execution
- Legacy deletion or archive execution
- Controlled record creation from legacy records
- Migration execution
- Cutover execution
- Property freeze
- Operational rollback
- Target write
- Bridge activation
- Dual-write
- Backfill

#### Explicit Non-Goals

Sprint 33 must not create or expose:
- Migration execution, pilot execution, migration batch, migration item, or migration queue
- Background worker or queue processor
- Controlled Bank Account creation from legacy data
- Controlled Bank Statement Line creation from legacy data
- Bank Payment Reconciliation creation from legacy data
- Account/statement-line/reconciliation/payment migration
- Operational bridge, dual-write, or backfill
- Legacy record modification or controlled record modification
- Property cutover, legacy freeze, or operational rollback
- Automatic target selection, candidate ranking, or similarity matching
- Account-number, bank-name, account-name, amount, date, currency, vendor, GL, or external-reference matching
- Balance comparison, financial comparison, or variance calculation
- Mapping score, confidence score, or migration score
- Target recommendation
- Bank import, bank feed, or automatic reconciliation
- Go service, Python service, event bus, message broker, or external bank API

No disabled buttons, hidden routes, placeholder controls, TODO actions, or "future execution" affordances for these exclusions.

### 20. State Machine

States:
- `DRAFT`
- `REQUESTED`
- `REVIEW_ACCEPTED`
- `REVIEW_REJECTED`
- `ARCHIVED`

Allowed transitions:
- DRAFT → REQUESTED
- REQUESTED → REVIEW_ACCEPTED
- REQUESTED → REVIEW_REJECTED
- REVIEW_ACCEPTED → ARCHIVED
- REVIEW_REJECTED → ARCHIVED

No reverse transition. No reopen. No retry through status mutation.

Forbidden states include:
- APPROVED_FOR_EXECUTION
- EXECUTION_AUTHORIZED
- READY_TO_MIGRATE
- PILOT_READY
- EXECUTING
- EXECUTED
- CUTOVER_READY
- CUTOVER_COMPLETE
- ROLLED_BACK

### 21. Browser-Input Exclusion

Wave 2 request browser input may contain only `banking_migration_target_intake_id`. Wave 2 review browser input may contain only `banking_migration_pilot_authorization_id` and `review_outcome` (REVIEW_ACCEPTED or REVIEW_REJECTED). All meaningful values are server-resolved.

## Consequences

### Positive

1. Control-plane pilot authorization governance is formalized without enabling execution.
2. Three-actor separation is enforced: proposal actor, proposal reviewer, and authorization reviewer are distinct.
3. All authorization records remain non-operational with `MIGRATION_EXECUTION_NOT_IMPLEMENTED` and `CUTOVER_NOT_AUTHORIZED`.
4. Existing Sprint 27-32 Banking behaviors remain unchanged.
5. Property-scoped control prevents cross-property leakage.
6. No financial values, account details, or comparison data are stored.

### Limitations

1. No data-plane migration capability — all migration execution is deferred.
2. No cutover execution — `CUTOVER_NOT_AUTHORIZED` is fixed.
3. Account-level BankAccount source scope only — no statement-line, reconciliation, or payment authorization.

## Related ADRs

- ADR-004: Finance Module Boundary Architecture
- ADR-007: Reconciliation Architecture Finalization
- ADR-019: Payment and Bank Reconciliation Engine
- ADR-052: Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture
- ADR-056: Banking Legacy Isolation and Manual Evidence Coexistence Architecture
- ADR-063: Polyglot Specialized Services Boundary
- ADR-064: FX Operational Access Segregation and Finance Role Configuration
- ADR-070: Banking Operations Workspace and Controlled Bank Execution
- ADR-071: Bank Reconciliation Session Lifecycle Integration
- ADR-072: Banking Reconciliation Domain Convergence and Legacy Isolation
- ADR-073: Banking Legacy-to-Controlled Migration and Cutover Architecture
- ADR-074: Banking Legacy-to-Controlled Migration Implementation Foundation
- ADR-075: Banking Target Intake and Human-Governed Mapping Architecture

---

**Implementation status:** Wave 1 delivered. Wave 2 classification: PENDING. Future data-plane migration: NOT AUTHORIZED.
