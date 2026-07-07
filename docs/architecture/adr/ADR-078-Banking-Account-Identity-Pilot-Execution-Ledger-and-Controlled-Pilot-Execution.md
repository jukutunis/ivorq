# ADR-078: Banking Account Identity Pilot Execution Ledger and Controlled Pilot Execution

**Status:** Accepted
**Date:** 2026-07-07

## Context

Sprint 27 delivered controlled Banking Operations. Sprint 28 added read-only `ReconciliationSession` evidence projection. Sprint 29 formalized Controlled/Legacy separation (ADR-072). Sprint 30 established migration and cutover architecture (ADR-073). Sprint 31 delivered the non-executable migration control plane: `BankingMigrationPlan`, `BankingMigrationManifestEntry`, and `BankingMigrationExceptionQuarantine` (ADR-074). Sprint 32 introduced human-governed `BankingMigrationTargetIntake` with independent review (ADR-075). Sprint 33 introduced `BankingMigrationPilotAuthorization` with three-actor separation (ADR-076). Sprint 34 defined execution preconditions architecture (ADR-077).

None of these packages created migration execution capability, data-plane service, target write, lineage persistence, bridge activation, or cutover.

Sprint 35 now delivers the first narrowly permitted data-plane lineage write: an immutable account-identity execution lineage ledger. Sprint 35 is not a general migration engine, bulk migration, record-copy engine, or property cutover.

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
| ADR-073 | Banking Legacy-to-Controlled Migration and Cutover Architecture | Migration architecture policy |
| ADR-074 | Banking Legacy-to-Controlled Migration Implementation Foundation | Migration control plane |
| ADR-075 | Banking Target Intake and Human-Governed Mapping Architecture | Human-governed target intake |
| ADR-076 | Banking Account Migration Pilot Authorization Architecture | Pilot authorization workflow |
| ADR-077 | Banking Pilot Data-Plane Execution Preconditions Architecture | Execution preconditions |

## Decisions

### 1. Sprint 35 Account-Identity-Only Scope

Sprint 35 may execute only this narrow, immutable action:

```
Legacy BankAccount source identity
→ pre-approved existing ControlledBankAccount target identity
→ immutable Banking Migration Account Identity Execution Ledger record
```

The execution means only:

```
An approved legacy BankAccount identity has immutable, auditable lineage
to one pre-existing active ControlledBankAccount identity
for a property-scoped pilot.
```

It does not mean:

```
Legacy account values were copied.
Legacy balances were migrated.
Controlled account values were changed.
Bank statements were migrated.
Reconciliations were migrated.
Payment Executions were created.
Journals were posted.
Property cutover occurred.
Legacy Banking is frozen.
Controlled Banking became authority for all historical records.
```

### 2. Explicit Distinction: Execution Lineage Ledger vs Operational Banking Mutation

The execution ledger `banking_migration_account_identity_executions` is a Banking-owned immutable lineage record. It is separate from all operational Banking tables (legacy and controlled). It records only identity provenance — what source identity maps to what existing target identity under what governance chain. It never modifies an operational Banking record.

### 3. Required Predecessor Chain

Every execution ledger record must reference the complete governance chain:

```
BankingMigrationPlan
→ BankingMigrationManifestEntry (source_domain = legacy_banking, source_model = BankAccount)
→ BankingMigrationTargetIntake (REVIEW_ACCEPTED)
→ BankingMigrationPilotAuthorization (REVIEW_ACCEPTED)
→ BankingMigrationAccountIdentityExecution
```

### 4. Source Restriction

Source domain is server-fixed to `legacy_banking`. Source model is server-fixed to `BankAccount`. No statement-line, reconciliation, payment, or journal scope is authorized.

### 5. Existing Target Restriction

Target must be an existing active, same-property `ControlledBankAccount`. The target ULID is server-derived from the Target Intake. No target creation, activation, or mutation is authorized.

### 6. Immutable Ledger Ownership

The execution ledger is owned by the Banking module (`Modules/Finance/Banking`). It is not a separate module, service, or framework.

### 7. No Legacy/Controlled Operational Table Mutation

Zero legacy or controlled operational Banking tables are modified during execution. All writes remain inside `banking_migration_account_identity_executions` and, for conflict scenarios, `banking_migration_exception_quarantines`.

### 8. No Financial, Reconciliation, or Accounting Effects

Execution does not:
- Create or modify Payment Execution
- Create or modify reconciliation records
- Create or modify journals
- Affect General Ledger
- Affect Financial Period
- Affect Business Date
- Affect Cashbook, Cash Session, Cash Instrument
- Affect AP Settlement Allocations
- Create or modify statement lines
- Affect a bank statement or external evidence record

### 9. Exact Immutable Lineage Contract

The ledger record contains only identity provenance evidence:

| Field | Source | Immutable |
|---|---|---|
| ULID identity | Server-generated | Yes |
| Property identity | Server-resolved | Yes |
| Migration Plan FK | Server-resolved from chain | Yes |
| Manifest Entry FK | Server-resolved from chain | Yes |
| Target Intake FK | Server-resolved from chain | Yes |
| Pilot Authorization FK | Server-resolved from chain | Yes |
| Source domain | Server-fixed `legacy_banking` | Yes |
| Source model | Server-fixed `BankAccount` | Yes |
| Source ULID | Server-derived from Manifest Entry | Yes |
| Source property | Server-derived | Yes |
| Source identity hash | Server-derived from Manifest Entry | Yes |
| Safe source snapshot hash | Server-recomputed at execution | Yes |
| Target domain | Server-fixed `controlled_banking` | Yes |
| Target model | Server-fixed `ControlledBankAccount` | Yes |
| Target ULID | Server-derived from Target Intake | Yes |
| Target property | Server-derived | Yes |
| Target identity hash | Server-derived from Target Intake | Yes |
| Execution outcome | Server-fixed | Yes |
| Execution actor | Server-resolved | Yes |
| Pilot authorization reviewer | Server-derived snapshot | Yes |
| Correlation ID | Server-generated | Yes |
| Idempotency key | Server-bound | Yes |
| Confirmation evidence | Server-derived intent/context proof only | Yes |
| Executed timestamp | Server-generated | Yes |
| Audit fields | Server-resolved | Yes |

The only successful outcome permitted is:

```
ACCOUNT_IDENTITY_LINEAGE_EXECUTED
```

Forbidden success states include: `ACCOUNT_MIGRATED`, `ACCOUNT_COPIED`, `ACCOUNT_CUT_OVER`, `MIGRATION_COMPLETED`, `TARGET_ACTIVATED`, `EXECUTION_READY`.

### 10. Execution Idempotency Policy

| Condition | Outcome |
|---|---|
| Same source identity + same target identity + same valid lineage replay | Return original immutable execution result; no second ledger record |
| Same source identity + different target identity | Hard conflict; immutable Quarantine; no success record |
| Same target identity + different source identity | Hard conflict; immutable Quarantine; no success record |
| Same property + same idempotency key replay | Return original result only when lineage context matches; otherwise controlled conflict |
| Safe source snapshot changed | Hard conflict; immutable Quarantine; no success record |
| Target inactive | Controlled failure; no success record |
| Property mismatch | Controlled failure; no success record |
| Target Intake/Pilot Authorization not REVIEW_ACCEPTED | Controlled failure; no success record |
| Relevant unresolved Quarantine | Controlled failure; no success record |
| Confirmation stale/mismatched/replayed | Controlled failure; no success record |
| Actor separation fails | Controlled failure; no success record |

### 11. Source Identity Replay Policy

When the same source identity (source_domain + source_model + source_ulid + property_id) is replayed with the same target identity, return the existing immutable execution ledger record. Do not create a duplicate.

### 12. Different-Target Conflict Policy

When the same source identity is submitted with a different target identity, create an immutable Quarantine record with exception code `EXECUTION_SOURCE_ALREADY_CLAIMED`. Do not create an execution success record. Do not infer which target is correct.

### 13. Already-Claimed-Target Conflict Policy

When the same target identity is submitted with a different source identity, create an immutable Quarantine record with exception code `EXECUTION_TARGET_ALREADY_CLAIMED`. Do not create an execution success record.

### 14. Source Snapshot Drift Policy

At execution time, the safe source snapshot hash is recomputed from the current legacy `BankAccount` record using only non-financial provenance fields: `source_model | source_ulid | source_property_id | created_at | updated_at`. If this hash differs from the Manifest Entry's `source_snapshot_hash`, execution is blocked and a Quarantine record with exception code `EXECUTION_SOURCE_SNAPSHOT_CHANGED` is created.

### 15. Unresolved Quarantine Policy

Any unresolved (`is_resolved = false`) Exception Quarantine record for the same Migration Plan and same source identity (source_ulid) blocks execution. The conflict is not automatically resolved.

### 16. Inactive Target Policy

If the target `ControlledBankAccount.is_active` is `false` at execution time, execution fails without creating a success or quarantine record. The failure reason is returned to the caller.

### 17. Property Mismatch Policy

If the Migration Plan, Manifest Entry, Target Intake, Pilot Authorization, source `BankAccount`, and target `ControlledBankAccount` are not all in the same property, execution fails closed. No execution ledger record is created.

### 18. Target Intake / Pilot Authorization Regression Policy

Target Intake must be `REVIEW_ACCEPTED` at execution time. Pilot Authorization must be `REVIEW_ACCEPTED` at execution time. Status is revalidated server-side; the browser cannot supply or override status.

### 19. Conflict Quarantine Policy

When a safe execution conflict is detected, an immutable `BankingMigrationExceptionQuarantine` record is created. The Quarantine uses existing Sprint 31 exception code conventions. Quarantine records are immutable and never auto-resolved.

### 20. Sensitive Confirmation Policy

Execution requires a new sensitive confirmation intent `banking-migration-account-identity-pilot-execution`. Confirmation binds server-side to: property_id, migration_plan_id, manifest_entry_id, target_intake_id, pilot_authorization_id, source_identity_hash, safe_source_snapshot_hash, target_identity_hash. The browser must not supply or override any binding value. No raw confirmation secret is stored in the execution ledger.

### 21. Separate Execution Actor Authority

The executor must be a Finance Manager with `finance.banking.migration.pilot.execution.execute`. The executor must differ from:
- Target Intake proposal actor
- Target Intake review actor
- Pilot Authorization request actor
- Pilot Authorization review actor

Finance Controller, General Ledger Accountant, Accounts Payable Officer, and General Cashier cannot execute.

### 22. No Automatic Retry

Execution failure does not trigger automatic retry. Explicit subsequent requests with the same source-proven idempotency identity may be submitted.

### 23. Correction Policy

Sprint 35 does not support rollback, update, or delete of execution ledger rows. A future correction package may introduce an immutable superseding correction record only after separate architecture authority. It must not mutate a prior execution ledger, legacy/controlled operational records, balances, reconciliation, Payment Execution, or journals.

### 24. Property Cutover Remains Separate

A single account-level pilot execution never implies property cutover, legacy freeze, or controlled activation. The execution ledger carries `CUTOVER_NOT_AUTHORIZED` status.

### 25. Post-Execution Reconciliation Deferred

Post-execution reconciliation treatment remains deferred to its own ADR and implementation package.

### 26. No Go/Python/Event Bus/Direct PostgreSQL

Per ADR-063:
- No Go or Python worker, service, or migration process
- No direct PostgreSQL migration process
- No message broker or event bus
- No microservice extraction
- All execution writes use approved Laravel service boundaries only

### 27. Database Uniqueness and Immutable Boundary

PostgreSQL unique constraints enforce:
1. One property-scoped legacy source identity may have only one successful execution lineage record.
2. One property-scoped controlled target identity may be claimed by only one successful execution lineage record.
3. One property-scoped idempotency key may have only one execution outcome.

Model-level immutability is enforced: no update route, no delete route, no update service, no delete service. The model prevents normal ORM update/delete through supported application boundaries.

### 28. Permission and Role Boundary

| Permission | Purpose |
|---|---|
| `finance.banking.migration.pilot.execution.execute` | Execute account identity lineage after all gates pass |

| Role | Authority |
|---|---|
| Finance Manager | May execute only after all chain, confirmation, and actor-separation gates pass |
| Finance Controller | Cannot execute |
| General Ledger Accountant | Cannot execute |
| Accounts Payable Officer | Cannot execute |
| General Cashier | Cannot execute |
| Super Admin / Property Admin | Existing broad bootstrap pattern only; all actor separation and same-property gates still apply |

No new role is created.

### 29. Browser-Input Exclusion

Execution request browser input may contain only `banking_migration_pilot_authorization_id` and existing source-proven sensitive-confirmation interaction fields. The browser must never submit: property, company, team, source model, source ULID, target model, target ULID, source hash, target hash, snapshot hash, actor, reviewer, permission, execution outcome, cutover authority, audit payload, idempotency key, or confirmation binding context.

### 30. Wave 1 and Wave 2 Implementation Manifest

| Wave | Status | Content |
|---|---|---|
| Wave 1 | To be delivered | ADR-078, immutable execution ledger schema, model, enum, policy, permission boundary, read-only workspace projection |
| Wave 2 | Conditional | Sensitive-confirmed account identity pilot execution service, controller action, confirmation intent, PostgreSQL tests |
| Future broader migration | NOT AUTHORIZED | Requires separate ADR/package |
| Property cutover | NOT AUTHORIZED | Requires separate ADR/package |

### 31. Future Broader Migration/Cutover Preconditions

Before any broader migration or cutover:
- All ADR-077 execution preconditions remain applicable
- A dedicated broader-migration ADR must be approved
- Property cutover requires a separate ADR/package
- Post-execution reconciliation treatment requires a separate ADR/package
- Correction workflow requires a separate ADR/package

## Consequences

### Positive

1. First narrowly permitted data-plane lineage write with immutable audit provenance.
2. Account-identity lineage is formally recorded without creating any operational mutation.
3. Idempotency, source/target uniqueness, and conflict quarantine are enforced at the PostgreSQL level.
4. Actor-separation, sensitive confirmation, and chain-gate validation are server-enforced.
5. All Sprint 27–34 Banking, Finance, Reconciliation, Payment, GL, Cash, and AP behaviors remain unchanged.
6. No generic migration engine, worker, queue, or external integration introduced.

### Limitations

1. Account-identity lineage only — no balance, statement, reconciliation, or payment migration.
2. Property cutover remains `CUTOVER_NOT_AUTHORIZED`.
3. No correction or rollback capability — immutable ledger records cannot be changed.
4. Post-execution reconciliation treatment remains deferred.

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
- ADR-076: Banking Account Migration Pilot Authorization Architecture
- ADR-077: Banking Pilot Data-Plane Execution Preconditions Architecture

---

**Implementation status:** Wave 1 delivered. Wave 2 classification: PENDING. Future broader migration: NOT AUTHORIZED. Property cutover: NOT AUTHORIZED.
