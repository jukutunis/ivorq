# ADR-075: Banking Target Intake and Human-Governed Mapping Architecture

**Status:** Accepted
**Date:** 2026-07-07

## Context

ADR-073 established the architecture policy for Banking legacy-to-controlled migration and cutover. ADR-074 delivered the Sprint 31 migration control plane: `BankingMigrationPlan`, `BankingMigrationManifestEntry`, and `BankingMigrationExceptionQuarantine` — all non-executable control-plane artifacts with no mapping, bridge, or data-plane capability.

Sprint 32 now introduces the first explicit human-governed target-intake mapping proposal and independent review workflow. This is a control-plane-only planning action. A mapping proposal records that a properly authorized human proposed a specific existing controlled target for a source item already inventoried by Sprint 31, and a separate authorized reviewer accepted or rejected that planning decision.

This ADR records the architecture decisions for the Sprint 32 target-intake and mapping proposal boundary.

## Mandatory ADR Baseline

| ADR | Title | Key relevance |
|---|---|---|
| ADR-004 | Finance Module Boundary Architecture | Banking owns bank account and bank statement evidence |
| ADR-007 | Reconciliation Architecture Finalization | Strict 1-to-1 reconciliation invariant |
| ADR-019 | Payment and Bank Reconciliation Engine | Two-tiered architecture; bank reconciliation is 1-to-1 |
| ADR-052 | Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture | Controlled Banking is forward path |
| ADR-056 | Banking Legacy Isolation and Manual Evidence Coexistence Architecture | Legacy isolation policy |
| ADR-063 | Polyglot Specialized Services Boundary | No Go/Python; no direct PostgreSQL |
| ADR-064 | FX Operational Access Segregation and Finance Role Configuration | Role and permission assignment convention |
| ADR-070 | Banking Operations Workspace and Controlled Bank Execution | Controlled Banking web workspace |
| ADR-071 | Bank Reconciliation Session Lifecycle Integration | Read-only session evidence |
| ADR-072 | Banking Reconciliation Domain Convergence and Legacy Isolation | Cross-domain non-relationship proven |
| ADR-073 | Banking Legacy-to-Controlled Migration and Cutover Architecture | Migration architecture policy baseline |
| ADR-074 | Banking Legacy-to-Controlled Migration Implementation Foundation | Sprint 31 control plane decisions |

## Decisions

### 1. Sprint 32 Account-Level Scope

Sprint 32 covers only account-level target-intake mapping. The mapping source must be an existing Sprint 31 `BankingMigrationManifestEntry` with source model exactly `BankAccount`. The mapping target must be an existing property-scoped `ControlledBankAccount`.

Do not include: `BankStatementLine`, `ReconciliationMatch`, `ReconciliationSession`, or `PaymentExecution` target intake. Account-level planning is the only permissible mapping unit.

### 2. Control-Plane vs Data-Plane Distinction

A mapping proposal is a control-plane planning artifact. It never creates, modifies, links, or consumes operational Banking records. It is not an operational bridge. It is not permission to execute migration or cutover.

Even a review-accepted proposal remains:

```
MIGRATION_EXECUTION_NOT_AUTHORIZED
CUTOVER_NOT_AUTHORIZED
```

### 3. Source Must Be a Sprint 31 Manifest Entry

Every mapping proposal must reference an existing `BankingMigrationManifestEntry`. The manifest entry must:
- belong to the active property;
- belong to the selected `BankingMigrationPlan`;
- have source model exactly `BankAccount`;
- not be quarantined, blocked, excluded, or otherwise unavailable under source-proven inventory status.

### 4. Target Must Be an Existing Controlled Bank Account

The target must be an existing `ControlledBankAccount` that:
- belongs to the active property;
- is active (`is_active = true`);
- is selected explicitly by an authorized Finance Manager.

### 5. Target Selection Is Fully Manual

No target is suggested, calculated, ranked, inferred, or auto-selected. The controller/service resolves the target by ULID only. No automatic target candidate list may be ranked or filtered using legacy source values.

### 6. Finance Controller Reviews Independently

Review is performed by a `finance-controller` (or user with `finance.banking.migration.mapping.review` permission). The reviewer makes an independent acceptance or rejection decision. No reverse transition. No reopen. No retry through mutation.

### 7. Maker-Checker Separation

The proposal maker (Finance Manager) cannot review or accept/reject their own proposal. The reviewer (Finance Controller) cannot create/propose under the same workflow. This prohibition is enforced server-side.

### 8. Accepted Mapping Remains Non-Operational

`REVIEW_ACCEPTED` means only:

```
Human-governed mapping proposal accepted for future migration planning.
No data-plane action is authorized.
```

### 9. No Source/Target Operational Record Changed

No legacy `BankAccount` record is modified. No `ControlledBankAccount` record is created or modified. All proposal/review writes remain in the new `banking_migration_target_intakes` table only.

### 10. No Target Record Created

Sprint 32 does not create any `ControlledBankAccount` from legacy data. A mapping proposal records a link to an existing controlled account only.

### 11. No Legacy Source Record Modified

Legacy `BankAccount` records remain immutable historical evidence.

### 12. No Mapping Result Consumed by Controlled Services

No mapping result may be consumed by `PaymentExecutionService`, `ManualBankReconciliationService`, `ReconciliationSessionService`, `ReconciliationFinalizationService`, or any other controlled execution or reconciliation service.

### 13. No Financial Payload Stored

No balance, amount, account number, external reference, comparison, or raw payload is stored in the mapping proposal. The proposal stores only identity/parent references, status, and audit evidence.

### 14. Property Scope and Target Active-Status Requirements

Every target intake record must be property-scoped. The target `ControlledBankAccount` must be active. Cross-property access fails closed.

### 15. Local Duplicate and Conflict Handling

Duplicate handling remains control-plane local. The same Migration Plan + Manifest Entry combination may not have more than one active (non-archived, non-rejected) mapping proposal.

A previously archived or review-rejected proposal may not be silently reactivated. Any new proposal after a rejected/archived proposal must be a newly created control-plane record.

No comparison of controlled accounts with each other. No comparison of legacy source fields with controlled target fields. No account equivalence or conflict inference.

### 16. Proposal Review Audit Requirements

Every proposal records:
- correlation ID (ULID, server-generated and immutable);
- proposal actor (server-resolved authenticated Finance Manager);
- review actor (server-resolved authenticated Finance Controller, when review occurs);
- review outcome (server-owned review decision);
- review timestamp (server-generated);
- audit columns (created_by, updated_by, timestamps).

### 17. Permission and Role Boundary

Two existing permissions are relevant:
- `finance.banking.migration.view` — view mapping proposals;
- `finance.banking.migration.manage` — create/propose account-level target-intake mapping.

One new permission is introduced:
- `finance.banking.migration.mapping.review` — review (accept/reject) mapping proposals.

| Role | View | Propose | Review |
|---|---|---|---|
| Finance Manager | Yes | Yes | No |
| Finance Controller | Yes | No | Yes |
| General Ledger Accountant | No | No | No |
| Accounts Payable Officer | No | No | No |
| General Cashier | No | No | No |
| Super Admin / Property Admin | Yes (via Permission::all()) | Yes (via Permission::all()) | Yes (via Permission::all()) |

No new role is created. No new sensitive confirmation intent is added.

### 18. Confirmation Policy

Mapping proposal creation and review are non-financial control-plane operations. No sensitive action confirmation is required. No confirmation intent is created or consumed.

### 19. Future Execution Preconditions

Before any review-accepted proposal can become migration-authorized, a future Sprint must deliver:
- Target identity write service with idempotency;
- Migration audit correlation;
- Property-level cutover status;
- Rollback/correction path;
- Legacy freeze/read-only enforcement;
- Target validation boundary;
- Post-cutover reconciliation treatment;
- Operational sign-off and authorization.

### 20. No Go/Python/Event Bus/Direct PostgreSQL Rule

Per ADR-063:
- No Go or Python worker, service, or migration process;
- No direct PostgreSQL migration process;
- No message broker or event bus;
- No microservice extraction;
- All control-plane writes use approved Laravel service boundaries only.

### 21. Wave 1 and Wave 2 Manifest

| Wave | Content |
|---|---|
| Wave 1 (this ADR) | `BankingMigrationTargetIntake` aggregate, state machine, permissions, policy, read-only workspace projection |
| Wave 2 (conditional) | Finance Manager proposal action, Finance Controller review action, property-scoped review queue, immutable proposal/review audit evidence |
| Future data-plane migration | NOT AUTHORIZED |

### 22. Explicit Future Data-Plane Migration Deferral

Sprint 32 does not authorize:
- Runtime migration;
- Legacy deletion;
- Legacy archive execution;
- Controlled record creation from legacy records;
- Migration execution;
- Cutover execution;
- Property freeze;
- Operational rollback.

## Non-Goals

Sprint 32 must not create or expose:
- Automatic target selection, matching, or recommendation;
- Fuzzy matching, similarity matching, or candidate ranking;
- Account-number, bank-name, account-name, or external-reference matching;
- Amount, date, currency, vendor, or GL-account comparison;
- Confidence score or migration score;
- Financial comparison or balance comparison;
- Target recommendation or candidate ranking;
- Account creation from legacy data;
- Statement-line, reconciliation, or payment mapping;
- Migration execution or cutover action;
- Dual-write, backfill, or operational bridge.

## State Machine

States:
- `DRAFT`
- `PROPOSED`
- `REVIEW_ACCEPTED`
- `REVIEW_REJECTED`
- `ARCHIVED`

Allowed transitions:
- DRAFT → PROPOSED
- PROPOSED → REVIEW_ACCEPTED
- PROPOSED → REVIEW_REJECTED
- REVIEW_ACCEPTED → ARCHIVED
- REVIEW_REJECTED → ARCHIVED

No reverse transition. No reopen. No execution or cutover state.

### Browser-Input Exclusion

Proposal browser input may contain only:
- `banking_migration_plan_id`
- `banking_migration_manifest_entry_id`
- `controlled_bank_account_id`

Review browser input may contain only:
- `banking_migration_target_intake_id`
- `review_outcome` (REVIEW_ACCEPTED or REVIEW_REJECTED)

All meaningful values are server-resolved.

## Consequences

### Positive

1. Human-governed account-level mapping is formalized as a control-plane planning action.
2. Maker-checker separation is enforced between Finance Manager and Finance Controller.
3. All mapping proposals remain non-operational — no bridge, execution, or cutover is authorized.
4. Existing Sprint 27–31 Banking behaviors remain unchanged.
5. Property-scoped control prevents cross-property leakage.

### Limitations

1. No data-plane migration capability — all migration execution is deferred.
2. No cutover execution — `CUTOVER_NOT_AUTHORIZED` is fixed.
3. Account-level mapping only — no statement-line, reconciliation, or payment mapping.

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

---

**Implementation status:** Wave 1 delivered. Wave 2 classification: PENDING. Future data-plane migration: NOT AUTHORIZED.
