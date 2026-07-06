# ADR-072: Banking Reconciliation Domain Convergence and Legacy Isolation

**Status:** Accepted for Wave 1 read-only evidence boundary only
**Date:** 2026-07-07

## Context

Sprint 27 delivered a controlled Banking Operations Workspace using `ControlledBankAccount`, `ControlledBankStatementLine`, and `BankPaymentReconciliation`. Sprint 28 added read-only `ReconciliationSession` evidence projection from the legacy Banking module (`BankAccount`, `ReconciliationMatch`). These two reconciliation domains coexist in the same workspace but represent entirely separate data sources with no relationship.

ADR-056 established isolation policy: legacy Banking records must not become source authority for the controlled path. Sprint 29 formalizes this boundary with a visibly separated workspace and explicit source-authority documentation.

## The Two Banking Reconciliation Domains

### Controlled Banking Domain (operative)

| Model | Table | Source |
|---|---|---|
| `ControlledBankAccount` | `controlled_bank_accounts` | `Modules/Finance/Banking/Models/ControlledBankAccount.php:16` |
| `ControlledBankStatementLine` | `controlled_bank_statement_lines` | `Modules/Finance/Banking/Models/ControlledBankStatementLine.php:15` |
| `BankPaymentReconciliation` | `bank_payment_reconciliations` | `Modules/Finance/Banking/Models/BankPaymentReconciliation.php:17` |

**Relationships:**
- `ControlledBankAccount` → `hasMany(ControlledBankStatementLine)`
- `ControlledBankAccount` → `belongsTo(Account)` (GL operational account)
- `BankPaymentReconciliation` → `belongsTo(ControlledBankAccount)`
- `BankPaymentReconciliation` → `belongsTo(ControlledBankStatementLine)`
- `BankPaymentReconciliation` → `belongsTo(PaymentExecution)`

**Operational status**: Active. Sprint 27 Bank Payment Execution and Manual Bank Reconciliation operate exclusively on this domain. Property-scoped. `ControlledBankAccount` has `operational_gl_account_id`, `currency_code`, `is_active`, and immutable source evidence fields (`source_identity_hash`, `source_snapshot`). `ControlledBankStatementLine` has `direction`, `amount`, `currency_code`, `external_reference`, and immutable source evidence.

### Legacy Banking Domain (historical)

| Model | Table | Source |
|---|---|---|
| `BankAccount` | `bank_accounts` | `Modules/Finance/Banking/Models/BankAccount.php:12` |
| `BankStatementLine` | `bank_statement_lines` | `Modules/Finance/Banking/Models/BankStatementLine.php:13` |
| `ReconciliationMatch` | `reconciliation_matches` | `Modules/Finance/Banking/Models/ReconciliationMatch.php:13` |
| `ReconciliationSession` | `reconciliation_sessions` | `Modules/Finance/Banking/Models/ReconciliationSession.php:15` |

**Relationships:**
- `ReconciliationSession` → `belongsTo(BankAccount)`
- `ReconciliationSession` → `hasMany(ReconciliationMatch)`
- `ReconciliationMatch` → `belongsTo(BankStatementLine)`

**Operational status**: Historical/compatibility. API-only (`auth:sanctum`). Balance-bearing fields (`opening_balance`, `current_balance`, `reconciled_balance`) exist on `BankAccount` and `ReconciliationSession`. Import-related fields exist on `BankStatementLine` (`is_reconciled` flag). Auto-match logic and reconciliation session lifecycle exist but are not exposed through Inertia web routes.

## Cross-Domain Non-Relationship (Proven)

A direct source inspection of all Banking models, relationships, and service methods confirms:

| Assertion | Proof |
|---|---|
| `BankAccount` ↔ `ControlledBankAccount` | **No relation.** Separate tables, separate models. Zero foreign keys or code references between them. |
| `BankStatementLine` ↔ `ControlledBankStatementLine` | **No relation.** Separate tables, separate models. `ReconciliationMatch` references `BankStatementLine`; `BankPaymentReconciliation` references `ControlledBankStatementLine`. |
| `ReconciliationMatch` ↔ `BankPaymentReconciliation` | **No relation.** Zero cross-references. |
| `ReconciliationSession` ↔ `BankPaymentReconciliation` | **No relation.** `ReconciliationSession` references `BankAccount`; `BankPaymentReconciliation` references `ControlledBankAccount`. |
| `ReconciliationSession` ↔ `ControlledBankStatementLine` | **No relation.** Zero code references. |

No mapping table, foreign key, service method, or runtime bridge connects the two domains. Any apparent similarity in field names (bank name, account name, amount, currency, property, vendor) is coincidental — not structural.

## Data and Source-Authority Proof

| Question | Answer | Evidence |
|---|---|---|
| Are legacy balances authoritative for controlled behavior? | **No.** ADR-056 prohibits reading legacy Banking balances as source authority. Controlled domain has no balance fields. | ADR-056 Sections 1, 3 |
| Are legacy imported statement lines eligible as controlled external evidence? | **No.** ADR-056 prohibits treating imported legacy statement lines as controlled external evidence. Controlled statement lines are manually registered immutable records. | ADR-056 Section 1 |
| Does legacy auto-match output affect controlled reconciliation? | **No.** No code relationship exists. | Cross-domain grep (zero results) |
| May controlled records be backfilled from legacy? | **No.** ADR-056 prohibits backfill. | ADR-056 Section 1 |
| Does any dual-write exist? | **No.** No service writes to both domains. | Code inspection |
| Does any runtime bridge exist? | **No.** | Cross-domain grep (zero results) |

## Strict 1-to-1 Reconciliation Invariant (ADR-007)

ADR-007 finalized bank reconciliation as strictly 1-to-1. Both domains enforce this:
- Legacy: `ReconciliationMatch` unique constraints on `bank_statement_line_id` and polymorphic `matchable_type`/`matchable_id`
- Controlled: `BankPaymentReconciliation` idempotency through `source_identity_hash` — one payment execution to one statement line

Sprint 29 does not weaken or reinterpret this invariant.

## Property, Company, and Team Scope

Both domains scope to `property_id` through the `BelongsToProperty` trait. Neither domain has direct `company_id` or `team_id` fields. Property scope is enforced through session resolution in the web layer and header-based resolution in the API layer.

## Legacy Balance Treatment

Legacy `BankAccount` has balance-bearing fields (`opening_balance`, `current_balance`, `reconciled_balance`). Legacy `ReconciliationSession` has balance fields (`opening_balance`, `reconciled_balance`, `unreconciled_balance`). These are:

- Historical values from the legacy Banking module.
- Not read for controlled operational decisions.
- Not projected in the Sprint 29 workspace.
- Not a controlled source authority.

The Sprint 28 correction (`2322c99`) already removed balance projection from the `ReconciliationSession` workspace. Sprint 29 extends that exclusion to the entire workspace.

## Future Migration Preconditions (Not Authorized)

A future ADR may authorize migration from legacy to controlled Banking. Before that ADR can be accepted, it must define:

1. Source authority — which domain is the migration source of truth.
2. Migration eligibility — which records qualify and which are excluded.
3. Immutable provenance — how migrated records retain source-of-origin evidence.
4. Duplicate handling — what happens when both domains have a similar record.
5. Legacy balance treatment — whether legacy balances migrate, are archived, or are discarded.
6. Reconciliation treatment — how migrated reconciliation records map to controlled reconciliation.
7. Rollback policy — whether migration can be reversed.
8. Audit evidence — what audit trail proves migration integrity.
9. Operational cutover boundaries — at what point the legacy domain becomes read-only.

Sprint 29 does not authorize, implement, or prepare any migration.

## Confirmation Policy

No new confirmation intents. Existing intents unchanged:
- `finance-approval` — approval/finalization decisions only
- `cash-payment-execution` — Cash Payment Execution only
- `bank-payment-execution` — Bank Payment Execution only
- `fx-break-glass` — FX break-glass only

## GL, Financial Period, and Business Date

Sprint 29 workspace is read-only. No GL journal, Financial Period, or Business Date effect is introduced.

## No-Go Boundaries

- No migration, bridge, mapping, dual-write, backfill, or cutover.
- No Go/Python service, event bus, message broker, or direct PostgreSQL integration (ADR-063).
- No schema, migration, model, role, permission, or generic lifecycle.
- No legacy-to-controlled auto-link, correlation, or suggestion engine.
- No bank balance calculation or projection.
- No legacy reconciliation activation.
- No controlled record mutation.
- No cross-domain financial inference.

## Wave 1 Implementation

| Path | Change |
|---|---|
| `docs/architecture/adr/ADR-072-Banking-Reconciliation-Domain-Convergence-and-Legacy-Isolation.md` | New ADR |
| `Modules/Finance/Banking/Http/Controllers/BankingOperationsWorkspaceController.php` | Domain-separated sections in index() |
| `resources/js/Pages/Ivorq/Finance/BankingOperationsWorkspace.tsx` | Two visibly separate evidence areas |
| `tests/Postgres/Finance/Banking/BankingReconciliationDomainConvergenceWorkspaceTest.php` | Domain-separation tests |

The workspace presents controlled and legacy evidence as separate sections with clear labeling. No records from the two domains are merged, combined, or cross-referenced.

## Wave 2

Controlled Reconciliation Readiness Evidence — evaluated at the Wave 1→2 hard gate. If source-proven, it projects only existing controlled-domain status/evidence fields without reading legacy models, calculating balances, or inferring migration readiness.

## Deferred

| Item | Reason |
|---|---|
| Legacy Banking migration | Requires dedicated ADR with source authority, eligibility, provenance, duplicate handling, balance treatment, reconciliation treatment, rollback policy, audit evidence, and cutover boundaries |
| Legacy-to-controlled bridge | Prohibited by ADR-056; no source-proven relationship exists |
| Legacy reconciliation activation | API-only controllers; Inertia web activation requires dedicated source proof |
| Wave 2 controlled readiness (if deferred) | Documented at hard gate |
