# ADR-073: Banking Legacy-to-Controlled Migration and Cutover Architecture

**Status:** Accepted
**Date:** 2026-07-07

## Context

Sprint 27 delivered a controlled Banking Operations Workspace with `ControlledBankAccount`, `ControlledBankStatementLine`, `BankPaymentReconciliation`, controlled Bank Payment Execution, and Manual Bank Reconciliation. Sprint 28 added read-only `ReconciliationSession` evidence projection from the legacy Banking module. Sprint 29 formalized visibly separated Controlled Banking and Legacy Banking workspace sections with explicit source-authority documentation (ADR-072).

No migration, bridge, mapping, dual-write, backfill, or cutover has ever been authorized. ADR-056 established legacy isolation policy. ADR-072 documented the cross-domain non-relationship. Sprint 30 now defines the formal architectural authority required before any future legacy Banking record could ever migrate into the Controlled Banking domain.

This ADR is policy only. It does not authorize runtime migration, controlled migration, legacy deletion, cutover execution, data transformation, or lifecycle activation.

## Mandatory ADR Baseline

The following ADRs provide the architecture foundation for migration authority decisions:

| ADR | Title | Key relevance |
|---|---|---|
| ADR-004 | Finance Module Boundary Architecture | Banking owns bank account and bank statement evidence |
| ADR-005 | Banking Standards Deferred | Advanced banking features deferred; core must stabilize |
| ADR-007 | Reconciliation Architecture Finalization | Strict 1-to-1 reconciliation invariant |
| ADR-019 | Payment and Bank Reconciliation Engine | Two-tiered architecture; bank reconciliation is 1-to-1 |
| ADR-052 | Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture | Controlled Banking is forward path; external evidence required |
| ADR-056 | Banking Legacy Isolation and Manual Evidence Coexistence Architecture | Legacy isolation policy; future transition requires explicit ADR |
| ADR-063 | Polyglot Specialized Services Boundary | No Go/Python; no direct PostgreSQL integration |
| ADR-068 | Supplier Payment and Settlement Operational Workspaces | Workspace pattern; Cashbook Evidence |
| ADR-069 | Controlled Cash Payment Execution Activation | CASH execution with confirmation |
| ADR-070 | Banking Operations Workspace and Controlled Bank Execution | Banking web workspace; BANK execution; bank reconciliation |
| ADR-071 | Bank Reconciliation Session Lifecycle Integration | Read-only session evidence; Wave 2 session lifecycle deferred |
| ADR-072 | Banking Reconciliation Domain Convergence and Legacy Isolation | Cross-domain non-relationship proven; controlled readiness evidence |

## Source-Proof Ledger

### A. Legacy Source Inventory

| Model | Table | Source |
|---|---|---|
| `BankAccount` | `bank_accounts` | `Modules/Finance/Banking/Models/BankAccount.php:12` |
| `BankStatementLine` | `bank_statement_lines` | `Modules/Finance/Banking/Models/BankStatementLine.php:13` |
| `ReconciliationMatch` | `reconciliation_matches` | `Modules/Finance/Banking/Models/ReconciliationMatch.php:13` |
| `ReconciliationSession` | `reconciliation_sessions` | `Modules/Finance/Banking/Models/ReconciliationSession.php:15` |

**Key legacy characteristics:**

- **Balance-bearing fields on BankAccount:** `opening_balance`, `current_balance`, `reconciled_balance` (all `decimal:2`)
- **Balance-bearing fields on ReconciliationSession:** `opening_balance`, `reconciled_balance`, `unreconciled_balance` (all `decimal:2`)
- **Balance-bearing fields on ReconciliationMatch:** `bank_account_balance_before`, `bank_account_balance_after`, `confidence_score`
- **Import-related fields on BankStatementLine:** `transaction_date`, `description`, `reference`, `external_reference`, `amount`, `is_reconciled`
- **Property scope:** via `BelongsToProperty` trait
- **Audit:** via `HasAuditColumns` trait
- **Identity:** ULID primary keys via `HasUlid` trait
- **Soft deletes:** Enabled
- **Status lifecycle:** ReconciliationSession has status enum (Open → InProgress → Review → Completed → Finalized; can Cancel from Open/InProgress/Review)
- **API-only:** ReconciliationSession and ReconciliationMatch controllers are `auth:sanctum` only
- **No Inertia web route** exists for any legacy Banking mutation

Legacy models have ULID identity, property scope, and audit columns — they can safely represent an immutable migration source when a future migration package proves eligibility, target identity, and provenance.

### B. Controlled Target Inventory

| Model | Table | Source |
|---|---|---|
| `ControlledBankAccount` | `controlled_bank_accounts` | `Modules/Finance/Banking/Models/ControlledBankAccount.php:16` |
| `ControlledBankStatementLine` | `controlled_bank_statement_lines` | `Modules/Finance/Banking/Models/ControlledBankStatementLine.php:15` |
| `BankPaymentReconciliation` | `bank_payment_reconciliations` | `Modules/Finance/Banking/Models/BankPaymentReconciliation.php:17` |

**Key controlled characteristics:**

- **No balance fields:** ControlledBankAccount has no `opening_balance`, `current_balance`, or `reconciled_balance`
- **Operational GL reference:** `ControlledBankAccount.operational_gl_account_id` maps to `Account` (GL bank control account)
- **Immutable source evidence:** `source_identity_hash` (SHA-256), `source_snapshot` (array) on all three models
- **Identity fields:** `bank_name`, `account_name`, `external_account_reference` (NOT `account_number`)
- **Statement line evidence:** `external_reference`, `source_reference`, `direction` (ControlledBankStatementLineDirectionEnum), `vendor_reference`
- **Reconciliation 1-to-1:** `BankPaymentReconciliation` links one `PaymentExecution` to one `ControlledBankStatementLine` via one `posted_journal_entry_id`
- **Registration:** Source evidence registered through `BankingSourceEvidenceService` with idempotency (returns existing on replay)
- **Active workspace:** `BankingOperationsWorkspaceController` — Inertia web controller with bank account, statement line, execution, and reconciliation evidence projection
- **No migration intake boundary:** No model, service, route, controller, or method exists for receiving migrated legacy records into the controlled domain

### C. Cross-Domain Identity Proof

Direct source inspection of all Banking models, relationships, and service methods confirms:

| Assertion | Proof |
|---|---|
| `BankAccount` ↔ `ControlledBankAccount` | **No relation.** Separate tables, separate models. Zero foreign keys or code references between them. |
| `BankStatementLine` ↔ `ControlledBankStatementLine` | **No relation.** Separate tables, separate models. `ReconciliationMatch` references `BankStatementLine`; `BankPaymentReconciliation` references `ControlledBankStatementLine`. |
| `ReconciliationMatch` ↔ `BankPaymentReconciliation` | **No relation.** Zero cross-references. |
| `ReconciliationSession` ↔ `BankPaymentReconciliation` | **No relation.** `ReconciliationSession` references `BankAccount`; `BankPaymentReconciliation` references `ControlledBankAccount`. |
| `ReconciliationSession` ↔ `ControlledBankStatementLine` | **No relation.** Zero code references. |
| legacy `account_number` ↔ controlled `external_account_reference` | **No structural relation.** Different field names, different semantics. External account reference is an externally provided reference string; legacy account number is a bank account number. |
| legacy `external_reference` (BankStatementLine) ↔ controlled `external_reference` (ControlledBankStatementLine) | **No structural relation.** Same field name, different tables, zero cross-references. |
| legacy `bank_account_id` (ReconciliationSession) ↔ controlled `controlled_bank_account_id` (BankPaymentReconciliation) | **No structural relation.** Different tables, different models. No foreign key links. |

Any apparent similarity in field names (bank name, account name, amount, currency, property, vendor) is coincidental — not structural.

**No mapping table, foreign key, service method, or runtime bridge connects the two domains.**

### D. Migration Safety Proof

The following migration prerequisites are evaluated against repository source:

| Prerequisite | Present in source? | Evidence |
|---|---|---|
| Immutable source lineage | **Partial** — Legacy models have ULID identity, audit columns; no source-to-target lineage contract | Legacy has `HasUlid`, `HasAuditColumns`, `BelongsToProperty`. No source lineage contract exists between domains. |
| Duplicate identity handling | **Absent** — No cross-domain duplicate detection exists | ADR-072 cross-domain grep returned zero results |
| Target idempotency key | **Absent** — No migration idempotency hash or contract exists for legacy-to-controlled intake | `BankingSourceEvidenceService` has idempotency for its own registration, not for legacy intake |
| Target write service | **Absent** — No service exists for receiving migrated legacy records | `BankingSourceEvidenceService` registers controlled accounts from fresh source references, not from legacy records |
| Actor/correlation identity | **Absent** — No migration actor, correlation ID, or migration context exists | Zero references to migration actor, correlation ID, or migration provenance in Banking module |
| Migration audit correlation | **Absent** — No migration audit table, event, or log exists | Zero migration audit references |
| Property cutover status | **Absent** — No property-level cutover flag, status, or transition exists | No `is_cut_over`, `migration_status`, or equivalent field on any model |
| Rollback/correction path | **Absent** — No rollback mechanism, correction workflow, or reversible migration service exists | Zero rollback or correction references in Banking module |
| Legacy freeze/read-only boundary | **Absent** — No legacy freeze flag, lock, or read-only enforcement exists | Legacy models have `SoftDeletes` but no freeze mechanism |
| Target validation boundary | **Absent** — No validation service for migration intake exists | No migration-specific validation exists |
| Approved post-cutover reconciliation treatment | **Absent** — No reconciliation treatment for migrated records exists | Zero references to post-cutover reconciliation |

**Conclusion: Runtime migration is NOT authorized.** Ten of eleven migration prerequisites are absent from repository source. The partial presence of immutable source lineage (ULID identity on legacy models) is insufficient without target identity, idempotency, write service, audit correlation, and policy boundaries.

### E. Finance and Reconciliation Proof

| Assertion | Proof | Source |
|---|---|---|
| Legacy balances are not controlled authority | Confirmed | ADR-056 Sections 1, 3 |
| No migration may fabricate a controlled external statement line | Confirmed — `ControlledBankStatementLine` requires `BankingSourceEvidenceService::registerStatementLine()` with fresh external reference and source reference | `BankingSourceEvidenceService.php:84` |
| No migration may fabricate `PaymentExecution` | Confirmed — `PaymentExecutionService::recordConfirmedBankExecution()` requires approved proposal item, active session, BANK instrument, controlled bank account, controlled statement line, and confirmation | ADR-070 A3 |
| No migration may fabricate `BankPaymentReconciliation` | Confirmed — `ManualBankReconciliationService::reconcilePostedBankPayment()` requires posted journal, linked PaymentExecution, active bank account, statement line, exact amount match | `ManualBankReconciliationService.php:25` |
| Strict 1-to-1 reconciliation remains intact | Confirmed — ADR-007 finalized; both legacy (`ReconciliationMatch` unique constraints) and controlled (`BankPaymentReconciliation` idempotency) enforce it | ADR-007, ADR-072 |
| No legacy reconciliation session/match can be reinterpreted as controlled reconciliation | Confirmed — No cross-domain relationship; separate models, tables, services | ADR-072 |
| No GL, Financial Period, Business Date, Cashbook, Cash Session, Cash Instrument, Payment Execution, AP Settlement Allocation, or Journal behavior may be changed | Confirmed — Sprint 30 does not modify any of these | Current source inspection |

## Decisions

### 1. Post-Cutover Authority

Controlled Banking is the post-cutover operational source authority. Legacy Banking remains historical authority for records not explicitly migrated under a future approved migration package. Existing coexistence does not imply migration eligibility.

Any future migration must explicitly designate which domain is the migration source and which is the target. After cutover, the controlled domain becomes the authoritative operational path for migrated records. Legacy records that are not migrated remain available for historical reference and audit but are never treated as controlled source authority.

### 2. Migration Unit and Scope

Any future migration must be property-scoped. A property cannot be treated as cut over merely because some records are present in controlled Banking. A future package must define the exact migration unit — such as account identity, statement evidence, or reconciliation evidence. Sprint 30 does not select or execute a migration unit.

The migration unit is the granularity at which a record moves from legacy to controlled domain:
- **Account-level migration:** Moving bank account identity (legacy `BankAccount` → controlled `ControlledBankAccount`)
- **Statement-line migration:** Moving statement evidence (legacy `BankStatementLine` → controlled `ControlledBankStatementLine`)
- **Reconciliation migration:** Moving reconciliation session or match records (legacy `ReconciliationSession`/`ReconciliationMatch` → controlled domain records)

Each migration unit requires a dedicated migration decision within the future package.

### 3. Eligibility Policy

A future record is eligible for migration only if its migration package proves:

- Source record is property-scoped
- Immutable source provenance exists (source model, table, ULID identity, audit trail)
- Deterministic unique target identity exists (no inferred mapping)
- Duplicate/conflict behavior is defined (source replayed, target pre-existing, identity collision)
- Target owner/service is approved (write path defined and authorized)
- Actor/correlation/audit context is retained
- No legacy balance becomes controlled authority
- No external evidence is fabricated
- No reconciliation uniqueness rule is weakened

### 4. Explicit Exclusions

Default exclusions until a future implementation package proves otherwise:

- Legacy balance fields (`opening_balance`, `current_balance`, `reconciled_balance`, `unreconciled_balance`, `bank_account_balance_before`, `bank_account_balance_after`)
- Legacy auto-match output (confidence_score, match_method, auto-match results)
- Legacy imported statement lines without admissible external evidence (where `is_reconciled` is set but no external reference is present)
- Legacy Reconciliation Session lifecycle state (status transitions, maker-checker evidence)
- Legacy Reconciliation Match records (polymorphic matchable, confidence_score, balance snapshots)
- Any record with ambiguous target identity (no deterministic unique mapping)
- Any record requiring inferred mapping (similar bank name, account name, or account number)
- Any record requiring financial value derivation
- Any record requiring direct model write (bypassing approved write services)
- Any record that would affect posted accounting outcome

### 5. Duplicate and Conflict Handling

A future migration package must define:

- **Duplicate identity detection:** How the system detects that a legacy record maps to an already-migrated record (source ULID replay)
- **Target-existing-record conflict:** When the controlled domain already has a record matching the proposed target identity
- **Same external reference conflict:** When two legacy records map to the same controlled external reference
- **Same account identity conflict:** When two legacy accounts map to the same controlled operational GL account
- **Same property/currency mismatch:** When a legacy record has different property scope or currency from the target
- **Same legacy record replay:** Idempotent rerun behavior — returning the existing controlled record without duplication
- **Non-destructive quarantine:** Conflicting records are quarantined, not discarded
- **Human-controlled exception handling:** Resolution actions require human authorization, not automatic resolution

Sprint 30 does not implement any of these.

### 6. Provenance and Audit

A future migration package must preserve:

- Immutable legacy source identity (source ULID)
- Source table/model identity (model class, table name)
- Source property
- Migration actor or system actor
- Correlation ID
- Execution timestamp
- Eligibility result
- Duplicate/conflict result
- Target result (target ULID, target table)
- Failure reason
- Audit trail before and after cutover

Sprint 30 does not add any migration audit table, event, or log.

### 7. Reconciliation and Accounting Safety

- Strict one-to-one reconciliation (ADR-007) remains unchanged
- Migrated evidence cannot fabricate payment execution
- Migrated evidence cannot create or post GL journals
- Migrated evidence cannot close Financial Period
- Migrated evidence cannot change Business Date
- Migrated evidence cannot mutate AP settlement
- Migration cannot convert legacy reconciliation state into controlled reconciliation without a dedicated reconciliation migration decision
- All existing `PaymentExecutionService`, `ManualBankReconciliationService`, `ReconciliationSessionService`, and `ReconciliationFinalizationService` behaviors remain unchanged

### 8. Cutover and Rollback Policy

A future cutover package must define:

- **Property eligibility:** Which properties qualify for cutover (all legacy records accounted for, source-ledger reconciliation performed, migration audit complete)
- **Legacy freeze boundary:** At what point legacy Banking becomes read-only for the cutover property
- **Controlled activation boundary:** At what point controlled Banking becomes authoritative for migrated records
- **Operational ownership:** Who authorizes cutover and what operational role signs off
- **Rollback policy:** Whether cutover is reversible, and if so, the exact reversal path, data integrity guarantee, and audit trail
- **Correction mechanism:** How post-cutover errors are corrected without violating immutable provenance
- **Audit retention:** How long migration and cutover audit evidence must be retained
- **Monitoring:** What operational monitors verify cutover integrity post-execution
- **Sign-off and approval authority:** What role or authority can approve cutover execution
- **User communication and training requirements:** What operational users must know before and after cutover

Sprint 30 does not set any property to cut over, freeze legacy data, or perform rollback.

### 9. Go/Python Prohibition

Per ADR-063:

- No Go or Python worker, service, or migration process
- No direct PostgreSQL migration process
- No message broker or event bus
- No microservice extraction
- All future migration writes must enter through approved Laravel command/service boundaries

### 10. Implementation Manifest

| Wave | Status | Content |
|---|---|---|
| Wave 1 | **DELIVERED** (this ADR) | Architecture boundary: post-cutover authority, eligibility policy, explicit exclusions, duplicate/conflict handling, provenance/audit requirements, legacy balance treatment, reconciliation treatment, rollback/cutover conditions, Go/Python prohibition, future migration preconditions |
| Wave 2 | **ACTIVATION_READY** or **DEFERRED** | Read-only migration authority readiness evidence projection (see separate Wave 2 section) |
| Future migration execution | **NOT AUTHORIZED** | Requires dedicated migration ADR with source authority, eligibility, provenance, duplicate handling, balance treatment, reconciliation treatment, rollback policy, audit evidence, and cutover boundaries |

## Wave 2 — Migration Authority Readiness Evidence

### Classification

Wave 2 is classified as `ACTIVATION_READY`. Source proof confirms a read-only, non-financial, non-inferential migration authority readiness evidence projection can be added to the existing Banking Operations Workspace without creating a bridge, mapping, migration batch, migration table, candidate matching engine, data copy, lifecycle activation, financial calculation, or source-authority breach.

### Source Proof for Wave 2

| Condition | Proof |
|---|---|
| Does not create a legacy-to-controlled mapping | Confirmed — projection reports only the ABSENCE of a bridge/mapping, not a mapping table or cross-reference |
| Does not compare legacy values against controlled values | Confirmed — independent domain counts; no field-level comparison, matching, or equivalence check |
| Does not calculate scores, balances, variances, or rankings | Confirmed — no arithmetic, no score computation, no balance reading |
| Does not read legacy balance fields | Confirmed — queries are `count()` only; no `opening_balance`, `current_balance`, or `reconciled_balance` is selected or projected |
| Does not create migration candidates or batches | Confirmed — no model, table, or DTO for migration |
| Does not write to any model | Confirmed — read-only `count()` and boolean/array projection |
| Does not invoke a service mutation | Confirmed — no service method is called for mutation |
| Does not introduce a new route | Confirmed — extends existing `index()` method of `BankingOperationsWorkspaceController` |
| Does not expose a migration or cutover action | Confirmed — no action button, form, or POST route |
| Uses existing server-owned property scope and authorization | Confirmed — all queries scoped to `property_id` from session |
| Is isolated into one atomic commit | Confirmed — controller extension, page extension, and test in one commit |

### Delivered Projection

The migration authority readiness evidence projection reports:

- **Controlled domain operational status:** Active account count, operative/inactive/empty status
- **Legacy domain historical/compatibility status:** Legacy account count, legacy session count, historical/empty status
- **Cross-domain bridge status:** Explicitly reports `absent` — no structural relation exists (source-proven fact)
- **Migration intake boundary status:** Explicitly reports `absent` — no service or model exists for receiving migrated records (source-proven fact)
- **Migration authorization status:** `false` — migration is NOT authorized (source-proven fact)
- **Migration prerequisites:** Pending status for source authority ADR, eligibility policy, provenance definition, duplicate handling, target write service, audit correlation, cutover policy, and rollback policy

All values are source-derived, non-financial, non-inferential, and non-mutating.

### What the Projection Must NOT Do

- Identify records as migration candidates
- Match records across domains
- Imply account equivalence
- Compare account details
- Compare statement-line evidence
- Display legacy balance
- Display controlled balance
- Display a score, confidence level, or readiness percentage
- Recommend or enable a migration

### PostgreSQL Test Coverage

Focused tests for Wave 2 prove:

1. Unauthenticated access is denied
2. Active-property context is required
3. Migration-readiness evidence remains property-scoped
4. No cross-property legacy or controlled record leaks
5. No legacy balance is read, projected, or rendered
6. No mapping, candidate, bridge, score, confidence, comparison, or financial calculation exists
7. Browser injection cannot alter status, property, authority, or readiness evidence
8. Workspace request performs no model mutation
9. No migration service, batch, item, route, confirmation, role, or permission is created
10. Sprint 27 Banking execution and manual reconciliation remain unchanged
11. Sprint 28 legacy session evidence remains unchanged
12. Sprint 29 controlled/legacy separation remains unchanged

## No-Go Boundaries (Sprint 30)

Sprint 30 does not authorize:

- Runtime data migration
- Legacy deletion or archive execution
- Controlled record creation from legacy records
- Legacy-to-controlled mapping table or bridge
- Data copy, backfill, or dual-write
- Runtime cutover or property migration execution
- Migration batch, item, queue, worker, API, UI action, or approval action
- Rollback execution
- Legacy reconciliation activation or Reconciliation Session lifecycle activation
- Bank statement import, automatic matching, or automatic reconciliation
- Cross-domain candidate matching, fuzzy matching, or equivalence scoring
- Financial comparison, balance comparison, variance calculation, or legacy balance projection
- New schema, migration, model, role, permission, or confirmation intent
- New generic migration engine
- Go/Python service, event bus, message broker, or external bank API
- Disabled buttons, placeholder controls, TODO actions, or hidden routes for excluded features

## Consequences

### Positive

1. **Formal migration authority:** Sprint 30 establishes the architectural rules required before any legacy Banking record can migrate. Future packages have a clear prerequisite checklist.
2. **Legacy isolation preserved:** All legacy isolation policies (ADR-056) are reaffirmed — legacy balances, imported statement lines, and auto-match results remain outside the controlled domain.
3. **Cross-domain non-relationship documented:** The absence of any structural bridge between legacy and controlled Banking is explicitly source-proven and recorded.
4. **Migration safety gap quantified:** Ten of eleven migration prerequisites are absent from source — runtime migration is objectively not authorized.
5. **Wave 2 readiness evidence:** A read-only, non-financial, non-inferential projection informs operational users of migration authority status without enabling or performing migration.
6. **Existing invariants preserved:** All Sprint 27 payment execution, manual reconciliation, Sprint 28 session evidence, Sprint 29 domain separation, and existing confirmation behaviors remain unchanged.

### Limitations

1. **No migration capability:** Sprint 30 does not create any migration infrastructure. All migration prerequisites remain pending.
2. **Legacy records remain historical:** Legacy Banking records cannot be consumed by the controlled operational path until a future migration package is approved.
3. **No cutover execution:** Properties cannot be cut over. Cutover policy is defined but not implemented.

## Related ADRs

- ADR-004: Finance Module Boundary Architecture
- ADR-005: Banking Standards Deferred
- ADR-007: Reconciliation Architecture Finalization
- ADR-019: Payment and Bank Reconciliation Engine
- ADR-052: Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture
- ADR-056: Banking Legacy Isolation and Manual Evidence Coexistence Architecture
- ADR-063: Polyglot Specialized Services Boundary
- ADR-068: Supplier Payment and Settlement Operational Workspaces
- ADR-069: Controlled Cash Payment Execution Activation
- ADR-070: Banking Operations Workspace and Controlled Bank Execution
- ADR-071: Bank Reconciliation Session Lifecycle Integration
- ADR-072: Banking Reconciliation Domain Convergence and Legacy Isolation

---

**Implementation status:** Wave 1 delivered (this ADR). Wave 2 classification: ACTIVATION_READY. Future migration execution: NOT AUTHORIZED.
