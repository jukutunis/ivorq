# ADR-074: Banking Legacy-to-Controlled Migration Implementation Foundation

**Status:** Accepted
**Date:** 2026-07-07

## Context

ADR-073 established the architecture policy for Banking legacy-to-controlled migration and cutover. It confirmed that no structural relationship exists between legacy and controlled Banking domains, that ten of eleven migration prerequisites were absent in repository source at Sprint 30, and that runtime migration is NOT authorized.

Sprint 31 now delivers the non-executable implementation foundation: a Banking-owned migration control plane that provides property-scoped Migration Plans, immutable Manifest Entries as legacy source inventory, and immutable Exception Quarantine records — all without creating any mapping, bridge, migration execution, or cutover capability.

This ADR records the source-proven implementation decisions for the Sprint 31 control plane.

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

## Decisions

### 1. Control-Plane-Only Scope

Sprint 31 authorizes only a Banking-owned migration **control plane**. A control plane manages plans, identity inventory, provenance evidence, and exceptions. It never executes data migration.

A future Sprint will define the migration **data plane**, which would handle the actual record creation from legacy to controlled domain under an explicit future authorization package.

Sprint 31 must never:
- create a `ControlledBankAccount` from a legacy `BankAccount`
- create a `ControlledBankStatementLine` from a legacy `BankStatementLine`
- create a `BankPaymentReconciliation` from a legacy `ReconciliationMatch` or `ReconciliationSession`
- map a legacy record to a controlled record
- compare legacy records against controlled records
- use similarity, equal fields, account number, bank name, account name, amount, date, currency, vendor, GL account, or external reference to infer a cross-domain relationship
- execute migration or cutover
- enable operational rollback

The only permitted operational writes are to new Sprint 31 Banking migration control-plane tables.

### 2. Banking Ownership

The migration control plane is owned by the Banking module (`Modules/Finance/Banking`). It does not move into Payables, General Ledger, General Cashier, Cashbook, or a generic migration framework.

### 3. Property-Scoped Plan Requirement

Every Migration Plan is property-scoped. The property is server-resolved from the authenticated session context. The browser never submits a property ID.

A plan created for one property is invisible and inaccessible from another property.

### 4. Fixed Source and Target Domains

- Source domain: `legacy_banking` (server-fixed, immutable)
- Target domain: `controlled_banking` (server-fixed, immutable)

The browser never submits a source or target domain. These values are server-owned constants.

### 5. Legacy Source Inventory Does Not Establish Eligibility

Legacy source records are inventoried for identity and non-financial provenance only. Inventory does not imply migration eligibility, target identity, deterministic mapping, or migration readiness.

### 6. Manifest Entries Are Inventory Evidence Only

Manifest Entries record only:
- immutable ULID identity
- parent Migration Plan identity
- source domain, model/type, ULID, and property identity
- source identity hash (safe non-financial inputs only)
- source snapshot hash (limited to non-financial provenance)
- dry-run version/run identity
- inventory status
- audit fields

Manifest Entries never store target model, target ID, mapping, candidate, amount, balance, currency comparison, account number, bank name, external reference, or confidence score.

### 7. No Target Model or Target Identifier Stored

No control-plane record stores a controlled target model name, target table name, or target ULID. The control plane does not know what a legacy record maps to in the controlled domain.

### 8. No Legacy Balance, Account Number, or Financial Payload Stored

Control-plane records may not store:
- legacy balances (opening_balance, current_balance, reconciled_balance, unreconciled_balance, bank_account_balance_before, bank_account_balance_after)
- account numbers
- bank names for comparison
- raw statement payload
- amounts for comparison
- currency for comparison
- vendor references for comparison
- external references for comparison
- GL account references for comparison
- confidence scores
- auto-match results

### 9. Immutable Provenance Requirements

All control-plane records are immutable after creation. Status transitions are the only permitted updates, and only through approved state machine transitions.

### 10. Local Duplicate Identity Quarantine Only

Duplicate detection operates locally within the control plane:
- same source domain + same source model + same source ULID + same source property + same plan + same dry-run version

A detected local duplicate results in `QUARANTINED` status and an immutable Exception Quarantine record. No cross-domain comparison occurs.

### 11. Exception Quarantine Rules

Exception Quarantine records are immutable. They contain:
- parent Migration Plan identity
- optional Manifest Entry identity
- server-defined exception code and severity
- source domain, model/type, ULID, and property
- correlation identity
- audit fields
- immutable no-automatic-resolution state

Quarantine records must never:
- recommend a controlled target
- suggest a mapping
- reveal balance, account number, or raw financial payload
- accept browser-submitted exception outcomes
- resolve automatically

### 12. CUTOVER_NOT_AUTHORIZED

Every Migration Plan carries the fixed cutover authority `CUTOVER_NOT_AUTHORIZED`. This value is server-owned, immutable, and may not be changed by any Sprint 31 operation.

### 13. Non-Executable Lifecycle

The Migration Plan state machine uses only these states:
- `DRAFT`
- `DRY_RUN_REQUESTED`
- `DRY_RUN_COMPLETED`
- `BLOCKED`
- `ARCHIVED`

Allowed transitions:
- DRAFT → DRY_RUN_REQUESTED
- DRY_RUN_REQUESTED → DRY_RUN_COMPLETED
- DRY_RUN_REQUESTED → BLOCKED
- DRY_RUN_COMPLETED → ARCHIVED
- BLOCKED → ARCHIVED

No reverse transition. No reopen. No execution state. No cutover state. No rollback state.

### 14. Future Execution and Cutover Prerequisites

Before Sprint 31's control plane can be used for actual data migration, a future Sprint must deliver:
- Target identity determination
- Legacy-to-controlled record write service with idempotency
- Migration audit correlation
- Property-level cutover status
- Rollback/correction path
- Legacy freeze/read-only enforcement
- Target validation boundary
- Post-cutover reconciliation treatment
- Operational sign-off and authorization

### 15. Control-Plane-Only Rollback Policy

The control plane itself supports only archiving of plans. No control-plane rollback beyond ARCHIVED state transitions is authorized. Data-plane rollback is deferred to the future implementation package.

### 16. Permission and Role Boundary

Two permissions are introduced:
- `finance.banking.migration.view` — read plan, manifest summary, quarantine summary, and `CUTOVER_NOT_AUTHORIZED`
- `finance.banking.migration.manage` — create plan and request/run a non-destructive dry run only

These are registered in `PermissionSeeder` and assigned through the existing `RoleSeeder` conventions. No new role is created. No new sensitive confirmation intent is added.

Exact role assignments:

| Role | `finance.banking.migration.view` | `finance.banking.migration.manage` |
|---|---|---|
| `finance-controller` | Yes | No |
| `finance-manager` | Yes | Yes |
| `general-ledger-accountant` | No | No |
| `accounts-payable-officer` | No | No |
| `super-admin` | Yes (via `Permission::all()`) | Yes (via `Permission::all()`) |
| `property-admin` | Yes (via `Permission::all()`) | Yes (via `Permission::all()`) |

No General Cashier role receives any migration permission.

### 17. Confirmation Policy

Migration plan creation and dry-run operations are non-financial control-plane operations. No sensitive action confirmation is required. No confirmation intent is created or consumed.

### 18. No Go/Python/Event Bus/Direct PostgreSQL Rule

Per ADR-063:
- No Go or Python worker, service, or migration process
- No direct PostgreSQL migration process
- No message broker or event bus
- No microservice extraction
- All control-plane writes use approved Laravel service boundaries only

### 19. Browser-Input Exclusion

For plan creation, the browser may submit only a narrowly validated request identity. The browser never submits: property, company, team, actor, source domain, target domain, source record identity, controlled record identity, migration eligibility, mapping, cutover status, authority, target relationship, exception resolution, audit payload, permission, or confirmation authority. All meaningful values are server-derived.

### 20. Wave 1 and Wave 2 Manifest

| Wave | Content |
|---|---|
| Wave 1 (this ADR) | Migration Plan aggregate, permissions, authorization, non-executable lifecycle, `CUTOVER_NOT_AUTHORIZED`, control-plane schema |
| Wave 2 (conditional) | Non-destructive dry-run, Manifest Entries, Exception Quarantine, dry-run summary |
| Future data-plane migration | NOT AUTHORIZED |

### 21. Explicit Future Data-Plane Migration Deferral

Sprint 31 does not authorize:
- Runtime migration
- Legacy deletion
- Legacy archive execution
- Controlled record creation from legacy records
- Legacy-to-controlled mapping
- Migration execution
- Cutover execution
- Property freeze
- Operational rollback

## Consequences

### Positive

1. Control-plane foundation exists for future migration without enabling execution.
2. Legacy source inventory can be recorded as provenance evidence without establishing eligibility.
3. Exception quarantine provides structured handling without automatic resolution.
4. Property-scoped plans prevent cross-property leakage.
5. Strict browser-input exclusion preserves server authority.
6. All Sprint 27–30 Banking behaviors remain unchanged.

### Limitations

1. No migration capability — all data-plane migration is deferred.
2. No cutover execution — `CUTOVER_NOT_AUTHORIZED` is fixed.
3. No target mapping — the control plane cannot determine what a legacy record maps to.

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

---

**Implementation status:** Wave 1 delivered. Future data-plane migration: NOT AUTHORIZED.
