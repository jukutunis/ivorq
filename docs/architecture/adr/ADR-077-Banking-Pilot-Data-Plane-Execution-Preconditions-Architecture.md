# ADR-077: Banking Pilot Data-Plane Execution Preconditions Architecture

**Status:** Accepted
**Date:** 2026-07-07

## Context

Sprint 27 delivered controlled Banking Operations with `ControlledBankAccount`, `ControlledBankStatementLine`, `BankPaymentReconciliation`, controlled Bank Payment Execution, and Manual Bank Reconciliation. Sprint 28 added read-only `ReconciliationSession` evidence projection from legacy Banking. Sprint 29 formalized Controlled/Legacy separation with explicit source-authority documentation (ADR-072). Sprint 30 established the formal migration and cutover architecture (ADR-073). Sprint 31 delivered the non-executable migration control plane: `BankingMigrationPlan`, `BankingMigrationManifestEntry`, and `BankingMigrationExceptionQuarantine` (ADR-074). Sprint 32 introduced human-governed `BankingMigrationTargetIntake` with independent review (ADR-075). Sprint 33 introduced `BankingMigrationPilotAuthorization` with three-actor separation and independent review (ADR-076).

None of the preceding packages created a migration execution capability, data-plane service, target write, lineage persistence, bridge activation, mapping activation, dual-write, backfill, cutover state, legacy freeze, operational rollback, or reconciliation lifecycle conversion.

Sprint 34 now defines the mandatory architecture required before a future package may ever execute one account-level Banking migration pilot. Sprint 34 is architecture and read-only evidence only. It does not authorize migration execution, pilot execution, target write, lineage persistence, property cutover, legacy freeze, or operational rollback.

This ADR records the source-proven architecture decisions for future data-plane execution preconditions.

## Mandatory ADR Baseline

| ADR | Title | Key relevance |
|---|---|---|
| ADR-004 | Finance Module Boundary Architecture | Banking owns bank account and bank statement evidence |
| ADR-007 | Reconciliation Architecture Finalization | Strict 1-to-1 reconciliation invariant |
| ADR-019 | Payment and Bank Reconciliation Engine | Two-tiered architecture; bank reconciliation is 1-to-1 |
| ADR-052 | Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture | Controlled Banking is forward path; external evidence required |
| ADR-056 | Banking Legacy Isolation and Manual Evidence Coexistence Architecture | Legacy isolation policy |
| ADR-063 | Polyglot Specialized Services Boundary | No Go/Python; no direct PostgreSQL; no event bus; no broker |
| ADR-064 | FX Operational Access Segregation and Finance Role Configuration | Role/permission assignment convention |
| ADR-070 | Banking Operations Workspace and Controlled Bank Execution | Controlled Banking web workspace |
| ADR-071 | Bank Reconciliation Session Lifecycle Integration | Read-only session evidence |
| ADR-072 | Banking Reconciliation Domain Convergence and Legacy Isolation | Cross-domain non-relationship proven |
| ADR-073 | Banking Legacy-to-Controlled Migration and Cutover Architecture | Migration architecture policy |
| ADR-074 | Banking Legacy-to-Controlled Migration Implementation Foundation | Migration control plane |
| ADR-075 | Banking Target Intake and Human-Governed Mapping Architecture | Human-governed target intake |
| ADR-076 | Banking Account Migration Pilot Authorization Architecture | Pilot authorization workflow |

## Source-Proof Evidence Inventory

### A. Control-Plane Chain Complete

The repository now contains the full non-executable control-plane chain:

| Record | Model | Table | Proven by |
|---|---|---|---|
| Migration Plan | `BankingMigrationPlan` | `banking_migration_plans` | `Modules/Finance/Banking/Models/BankingMigrationPlan.php:12` |
| Manifest Entry | `BankingMigrationManifestEntry` | `banking_migration_manifest_entries` | `Modules/Finance/Banking/Models/BankingMigrationManifestEntry.php:12` |
| Exception Quarantine | `BankingMigrationExceptionQuarantine` | `banking_migration_exception_quarantines` | `Modules/Finance/Banking/Models/BankingMigrationExceptionQuarantine.php:12` |
| Target Intake | `BankingMigrationTargetIntake` | `banking_migration_target_intakes` | `Modules/Finance/Banking/Models/BankingMigrationTargetIntake.php:14` |
| Pilot Authorization | `BankingMigrationPilotAuthorization` | `banking_migration_pilot_authorizations` | `Modules/Finance/Banking/Models/BankingMigrationPilotAuthorization.php:13` |

### B. No Execution Capability Exists

Direct source inspection of all routes, controllers, services, and models confirms:

| Assertion | Proof |
|---|---|
| No execution route exists | `routes/web.php:76-91` — 8 routes only: GET index, POST create plan, POST request/execute dry run, POST propose/review target intake, POST request/review pilot authorization. Zero execution, pilot-run, cutover, correction, or rollback routes. |
| No execution service exists | Grep for `BankingMigrationExecution` returns zero results across the entire repository |
| No execution model exists | No model, table, or migration for `BankingMigrationExecution` |
| No execution controller method exists | `BankingMigrationPlanController` has no execution, pilot-run, or cutover method |
| No target write capability exists | `BankingMigrationTargetIntakeService` and `BankingMigrationPilotAuthorizationService` perform only control-plane inserts/updates into their own tables; no legacy or controlled record is mutated |
| No lineage persistence exists | Zero lineage, ledger, or immutable-execution-aggregate tables, models, or services |
| No mapping activation exists | A `REVIEW_ACCEPTED` Target Intake is a planning record only; no service activates it into a runtime binding |
| No bridge exists | ADR-072 and ADR-073 confirmed zero cross-domain relationship, foreign key, service method, or runtime bridge |

### C. Execution Authority Always Deferred

Every control-plane record carries fixed, server-owned authority constants:

| Record | Authority Field | Source-Proven Value | Source |
|---|---|---|---|
| `BankingMigrationPlan` | `execution_authority` | `UNAVAILABLE` | `BankingMigrationPlanService.php:22` |
| `BankingMigrationPlan` | `cutover_authority` | `CUTOVER_NOT_AUTHORIZED` | `BankingMigrationPlanService.php:21` |
| `BankingMigrationTargetIntake` | `execution_authority` | `UNAVAILABLE` | `BankingMigrationTargetIntakeService.php:21` |
| `BankingMigrationTargetIntake` | `cutover_authority` | `CUTOVER_NOT_AUTHORIZED` | `BankingMigrationTargetIntakeService.php:22` |
| `BankingMigrationPilotAuthorization` | `execution_authority` | `MIGRATION_EXECUTION_NOT_IMPLEMENTED` | `BankingMigrationPilotAuthorizationService.php:22` |
| `BankingMigrationPilotAuthorization` | `cutover_authority` | `CUTOVER_NOT_AUTHORIZED` | `BankingMigrationPilotAuthorizationService.php:23` |

These values are server-constants. The browser never submits them. No controller method modifies them.

### D. Source Evidence Provenance

The `BankingMigrationDryRunService` computes safe, non-financial source evidence hashes:

**Source identity hash** (`BankingMigrationDryRunService.php:235-243`):
```
SHA-256(legacy_banking | sourceModel | sourceUid | sourcePropertyId)
```

**Source snapshot hash** (`BankingMigrationDryRunService.php:245-259`):
```
SHA-256(sourceModel | sourceUid | sourcePropertyId | createdAt | updatedAt)
```

Both hashes use only:
- `source_domain` (always `legacy_banking`)
- `source_model` (e.g., `BankAccount`)
- `source_ulid` (ULID primary key)
- `source_property_id` (property UUID)
- `created_at` (timestamp)
- `updated_at` (timestamp)

Neither hash uses:
- `balance` (opening_balance, current_balance, reconciled_balance)
- `amount`
- `currency` (currency_code)
- `account_number`
- `bank_name`
- `account_name`
- `external_reference`
- `description`
- `vendor`
- GL account references
- reconciliation output
- auto-match output
- financial status or lifecycle state

This is source-proven at `BankingMigrationDryRunService.php:245-259`.

### E. Legacy/Controlled Cross-Domain Non-Relationship Confirmed

ADR-072 and ADR-073 proved zero structural relationship between legacy and controlled Banking domains. Sprint 34 confirms this remains true — no new relationship, foreign key, bridge table, mapping table, or cross-domain reference has been added in any Sprint 27–33 package.

## Decisions

### A. Scope and Future Migration Unit

The only future pilot unit permitted for consideration is:

```text
Legacy BankAccount identity
→ existing ControlledBankAccount target reference
```

This is an account-identity-level mapping. The following are explicitly excluded from the pilot scope:

- balance migration (opening_balance, current_balance, reconciled_balance)
- statement-line migration (`BankStatementLine` → `ControlledBankStatementLine`)
- reconciliation match migration (`ReconciliationMatch` → `BankPaymentReconciliation`)
- reconciliation session migration (`ReconciliationSession`)
- payment execution migration (`PaymentExecution`)
- journal creation or mutation
- account creation (the `ControlledBankAccount` must already exist and be active)
- operational target update (the `ControlledBankAccount` field values are not modified)
- controlled source-evidence mutation (`source_identity_hash`, `source_snapshot` are not overwritten)

The Manifest Entry must represent `source_domain = legacy_banking` and `source_model = BankAccount`. The Target Intake must reference an existing active, same-property `ControlledBankAccount`.

### B. Future Target-Write Boundary

A future pilot execution package may not write directly to:

```text
bank_accounts
bank_statement_lines
reconciliation_matches
reconciliation_sessions
controlled_bank_accounts
controlled_bank_statement_lines
bank_payment_reconciliations
payment_executions
journals
financial_periods
property_business_dates
cashbook
cash_sessions
cash_instruments
ap_settlement_allocations
```

A later execution package may only be considered if it introduces a Banking-owned immutable execution ledger/lineage aggregate through a separate approved ADR and implementation package. This ledger must be separate from all existing operational tables.

Sprint 34 does not create this ledger.

### C. Future Immutable Lineage Contract

A future immutable lineage record must contain at minimum:

| Field | Requirement |
|---|---|
| migration plan identity | Reference to `BankingMigrationPlan` ULID |
| manifest entry identity | Reference to `BankingMigrationManifestEntry` ULID |
| target intake identity | Reference to `BankingMigrationTargetIntake` ULID |
| pilot authorization identity | Reference to `BankingMigrationPilotAuthorization` ULID |
| legacy source domain | Server-fixed `legacy_banking` |
| legacy source model | Server-resolved from manifest entry (must be `BankAccount`) |
| legacy source ULID | From manifest entry `source_ulid` |
| legacy source property | From manifest entry `source_property_id` |
| existing controlled target model | Server-fixed `ControlledBankAccount` |
| existing controlled target ULID | From target intake `controlled_bank_account_id` |
| target property | Server-resolved; must match source property |
| source identity hash | From manifest entry `source_identity_hash` |
| safe source snapshot hash | Re-verified at execution time using only safe provenance fields |
| target identity hash | From target intake `target_identity_hash` |
| execution actor or bounded system actor | Server-resolved user identity |
| authorization reviewer | From pilot authorization `review_actor_id` |
| correlation ID | Fresh ULID |
| execution idempotency key | SHA-256 derived from contract + source identity + target identity + property |
| execution timestamp | Server-resolved `now()` |
| immutable outcome | Success or failure code |
| immutable failure/conflict code | Where applicable |
| audit retention requirement | Permanent; never deleted or modified |

The lineage record must explicitly forbid storing:

```text
legacy balance (opening_balance, current_balance, reconciled_balance)
controlled balance (not applicable — ControlledBankAccount has no balance fields)
account number
bank name
account name
amount
currency comparison
external reference comparison
raw source payload
reconciliation state
auto-match confidence
candidate score
recommendation
```

### D. Execution Idempotency and Duplicate/Conflict Policy

The future execution service must define clear outcomes for each of the following scenarios:

1. **Same source identity + same target lineage replay:**
   - Return the original immutable execution result (read existing lineage record by idempotency key)
   - Do not create a duplicate lineage record
   - Do not mutate any existing operational record

2. **Same source identity with a different target:**
   - Hard conflict
   - Quarantine (record in future lineage ledger with conflict code)
   - No automatic repair
   - No inference of which target is correct

3. **Same target identity already claimed by a different legacy source:**
   - Hard conflict
   - Quarantine
   - No merge behavior
   - No automatic target-reassignment

4. **Source snapshot changed after Manifest Entry:**
   - Hard conflict
   - Quarantine
   - No execution
   - Snapshot re-verification must use only safe non-financial provenance fields

5. **Target account inactive:**
   - Hard conflict
   - No execution
   - The `is_active` field on `ControlledBankAccount` must be re-checked at execution time

6. **Property mismatch at any step:**
   - Hard failure
   - No execution
   - Plan, Manifest Entry, Target Intake, Pilot Authorization, and target must all share the same property

7. **Target Intake no longer `REVIEW_ACCEPTED`:**
   - Hard failure
   - No execution
   - Status re-verify at execution time

8. **Pilot Authorization no longer `REVIEW_ACCEPTED`:**
   - Hard failure
   - No execution

9. **Unresolved Sprint 31 Exception Quarantine:**
   - Hard failure
   - No execution
   - Quarantine records with `is_resolved = false` and the relevant source identity block execution

No future execution package may infer or repair any conflict using field similarity (bank name, account name, amount, currency, reference, GL account, vendor).

### E. Future Execution Authorization Requirements

Future execution requires all of the following preconditions:

```text
1. Same-property Migration Plan exists and is not ARCHIVED
2. Same-property Manifest Entry exists with source_model = BankAccount
3. Manifest Entry inventory_status is not EXCLUDED, BLOCKED, or QUARANTINED
4. Safe source snapshot unchanged (verified at execution time using only safe provenance fields)
5. No unresolved Exception Quarantine for the same source identity
6. Target Intake status = REVIEW_ACCEPTED
7. Pilot Authorization status = REVIEW_ACCEPTED
8. Target ControlledBankAccount is_active = true
9. Target ControlledBankAccount property matches
10. Independent executor authority (governed by a future dedicated authority decision)
11. Immutable execution idempotency key
12. Fresh execution correlation ID
13. Separate data-plane implementation approval (via future ADR/package)
```

The execution actor must be governed by a future dedicated authority decision. Sprint 34 does not create a new execution permission or assign any execution role.

### F. Correction and Rollback Policy

The following correction and rollback rules apply to any future execution:

1. No destructive rollback — once an immutable execution lineage record exists, it may never be deleted or have its outcome field changed after the fact.
2. No delete or mutation of prior immutable execution lineage.
3. Any future correction must be a separately authorized immutable superseding correction record that references the original lineage record.
4. Correction must never modify legacy or controlled operational records.
5. Correction must never change account balances, payment execution, reconciliation, journal, Financial Period, Business Date, Cashbook, or AP settlement.
6. Property cutover rollback is not part of account pilot execution — it requires a separate package.
7. No automated correction — every correction requires explicit human authorization.
8. No automatic conflict resolution — every conflict requires explicit human review.

### G. Cutover Policy

A single account-level pilot execution never implies:

```text
property cutover
legacy freeze
controlled activation for all historical Banking data
legacy write-block
reconciliation lifecycle conversion
```

Property cutover requires a future independent package defining:

- property eligibility criteria
- freeze/read-only boundary for legacy Banking records
- all migration-unit coverage requirements
- controlled activation boundary
- operational sign-off authority
- monitoring and operational verification
- communication and training requirements
- correction process for post-cutover errors
- cutover rollback policy
- post-cutover reconciliation treatment

### H. Post-Execution Reconciliation and Accounting Treatment

A future account identity lineage record must not:

- create a `BankPaymentReconciliation`
- create or modify `PaymentExecution`
- create or modify a journal
- reinterpret `ReconciliationMatch` or `ReconciliationSession`
- change strict one-to-one reconciliation (ADR-007)
- create controlled bank statement evidence
- produce financial posting or balance authority

Post-execution reconciliation treatment remains deferred to its own ADR and implementation package.

### I. Technical Execution Policy

A future execution package must observe these technical boundaries:

- Laravel service boundary only — no Go, Python, or other language runtime
- Synchronous, explicit user request only — no queue, worker, or background process
- No event bus or message broker
- No direct PostgreSQL process (no `DB::statement()`, no raw schema mutation)
- No automatic retry — failure requires explicit subsequent retry request with the same source-proven idempotency identity
- One database transaction per execution attempt
- Controlled failure — no partial state, no orphaned records
- Explicit subsequent retry request with the same source-proven idempotency identity where appropriate

### J. Required Future Execution Package Gates

No data-plane execution package may start until:

1. ADR-077 is accepted.
2. A dedicated execution implementation authority ADR is approved (defining the execution ledger/lineage schema, service boundary, and actor authority).
3. The future ledger/lineage schema is separately reviewed and approved.
4. Exact executor authority is approved (role/permission assignment).
5. Execution service boundary is reviewed against protected artifacts.
6. Source/target lineage and idempotency tests pass on PostgreSQL.
7. Conflict/quarantine tests pass for all scenarios in Section D.
8. No financial or reconciliation mutation proof passes (source proves zero legacy balance, controlled balance, journal, payment, or reconciliation mutation).
9. Property-cutover remains separately deferred.
10. Master Banking/Finance regression passes with all Sprint 27–34 behavior preserved.

### K. Sprint 34 Implementation Manifest

| Wave | Status | Scope |
|---|---|---|
| Wave 1 | Delivered by this ADR | Future execution precondition architecture only |
| Wave 2 | Conditional | Read-only execution precondition evidence projection only |
| Future execution ledger | Not authorized | Requires separate ADR/package |
| Future account pilot execution | Not authorized | Requires separate ADR/package |
| Property cutover | Not authorized | Requires separate ADR/package |

## No-Go Boundaries (Sprint 34)

Sprint 34 does not authorize:

- Runtime migration execution
- Pilot execution
- Target write (creation or mutation of controlled records from legacy records)
- Lineage persistence (no execution ledger/lineage table, model, or service)
- Bridge activation
- Mapping activation (no runtime binding between legacy and controlled)
- Dual-write
- Backfill
- Property cutover
- Legacy freeze
- Operational rollback
- Automatic target selection, candidate ranking, score, confidence, or recommendation
- Financial comparison, balance comparison, amount/date/currency/account-name/account-number/reference matching
- Bank feed or bank statement import
- Automatic reconciliation
- Go or Python service
- Event bus, message broker, microservice
- Queue worker or migration command
- New schema migration, model, enum, service, policy, permission, role, or confirmation intent
- New POST/PUT/PATCH/DELETE route
- Disabled buttons, hidden routes, placeholders, TODO actions, or unused skeleton service classes

## Consequences

### Positive

1. **Execution preconditions formalized:** Sprint 34 defines the exact architecture required before any future package may execute an account-level migration pilot.
2. **Immutable lineage contract defined:** The minimum fields required for a future execution ledger are specified with explicit prohibitions against storing financial or identity-comparison data.
3. **Idempotency and conflict policy exhaustive:** Nine distinct conflict/quarantine scenarios are defined with hard rules against inference or automatic resolution.
4. **Correction and rollback safety affirmed:** No destructive rollback, immutable execution records, and explicit correction-trace requirements.
5. **Cutover properly separated from account pilot:** A single pilot execution never implies property cutover. Cutover remains a separate, independently authorized package.
6. **Existing invariants preserved:** All Sprint 27–33 Banking behaviors, permissions, roles, preserved.
7. **No new runtime code:** Wave 1 is documentation only. Zero schema, route, permission, role, service, model, or migration changes.

### Limitations

1. **No execution capability:** Sprint 34 creates no migration execution infrastructure. All data-plane migration remains deferred.
2. **No lineage table:** The future immutable execution ledger exists only as a contract definition.
3. **No executor role:** The execution actor authority is deferred to a future ADR.
4. **No post-execution reconciliation treatment:** This remains deferred to its own ADR/package.

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

---

**Implementation status:** Wave 1 delivered (this ADR). Wave 2 classification: to be determined by Wave 2 hard-gate evaluation. Future data-plane migration execution: NOT AUTHORIZED. Future execution ledger: NOT AUTHORIZED. Property cutover: NOT AUTHORIZED.
