# ADR-071: Bank Reconciliation Session Lifecycle Integration

**Status:** Accepted for source-proven Wave 1 read‑only boundary only
**Date:** 2026-07-07

## Context

Sprint 27 delivered a Banking Operations Workspace with controlled Bank Payment Execution, Manual Bank Reconciliation, and read-only Banking evidence projection. Sprint 28 evaluates the existing `ReconciliationSession` domain for integration into the Banking Operations Workspace and determines whether lifecycle actions (session creation, session-linked manual reconciliation) are source-proven and safe to expose through the Inertia web layer.

## Existing Reconciliation Session Domain

### Aggregate and model

| Fact | Evidence |
|---|---|
| Model | `Modules/Finance/Banking/Models/ReconciliationSession.php:15` |
| Table | `reconciliation_sessions` |
| Status enum | `ReconciliationSessionStatusEnum` — Open, InProgress, Review, Completed, Finalized, Cancelled |
| Bank account relation | `belongsTo(BankAccount)` — references the legacy `bank_accounts` table, NOT `controlled_bank_accounts` |
| Property relation | `BelongsToProperty` trait — has `property_id` |
| Company/team | No direct company or team field |
| Currency | Derived through `BankAccount::currency_code` |
| Date range | `statement_date_start`, `statement_date_end` (date fields, not business dates) |
| Balances | `opening_balance`, `reconciled_balance`, `unreconciled_balance` (decimal:2) |
| Audit | `HasAuditColumns` trait + lifecycle fields (`completed_by`, `cancelled_by`, `reviewed_by`, `finalized_by`) |
| Match relation | `hasMany(ReconciliationMatch)` — matches link to legacy `BankStatementLine` (NOT `ControlledBankStatementLine`) |
| BankPaymentReconciliation relation | **NONE** — zero references between `ReconciliationSession` and `BankPaymentReconciliation` |

### Lifecycle service inventory

| Service/Method | Permission | Source |
|---|---|---|
| `ReconciliationSessionService::create(array $data)` | `banking.reconciliation.create` | `ReconciliationSessionService.php:18` |
| `ReconciliationSessionService::complete(string $sessionId, string $userId)` | `banking.reconciliation.manage` | `ReconciliationSessionService.php:39` |
| `ReconciliationSessionService::cancel(string $sessionId, string $userId)` | `banking.reconciliation.manage` | `ReconciliationSessionService.php:67` |
| `ReconciliationSessionService::delete(ReconciliationSession $session)` | `banking.reconciliation.manage` | `ReconciliationSessionService.php:85` |
| `ReconciliationFinalizationService::finalize(ReconciliationSession $session, string $userId, ?string $notes)` | Not exposed as web action | `ReconciliationFinalizationService.php:18` |
| `SessionStateGuard::transitionTo()` | Internal governance guard | `SessionStateGuard.php:36` |
| `ReconciliationSessionController::index/store/show/complete/cancel/destroy` | API-only (`auth:sanctum`) | `ReconciliationSessionController.php` |
| `ReconciliationMatchController::store()` | API-only (`auth:sanctum`) | `ReconciliationMatchController.php` |

No Inertia web controller exists for any Reconciliation Session action.

### Lifecycle transition rules (SessionStateGuard)

```
Open → InProgress → Review → Completed → Finalized
Open → Cancelled
InProgress → Cancelled
Review → Cancelled
```

- Finalized cannot transition to any other state.
- Maker-checker enforced at Completed and Finalized transitions (maker cannot approve; reviewer cannot finalize).
- Backdated session creation prevented (new session must start after latest completed/finalized session end date).

No `activate`, `reopen`, `archive`, `discard`, or `exception` transitions exist.

### Confirmation policy

None of the existing Reconciliation Session lifecycle services call `SensitiveActionConfirmationService`. Session creation, completion, cancellation, finalization, and match storage do not require confirmation. This is consistent with operational evidence recording (not an approval/finalization decision). No new confirmation intent is created by this ADR.

### Statement-line ownership and uniqueness

`ReconciliationMatch` links `BankStatementLine` (legacy) to a session via `bank_statement_line_id`. `BankStatementLine` has an `is_reconciled` boolean flag. The `ReconciliationMatch` model enforces uniqueness through database constraints (ADR-007): strict 1-to-1 matching between a bank statement line and an IVORQ entity.

`BankStatementLine` is a separate model/table from `ControlledBankStatementLine`. These are two independent domains:
- **Legacy Banking**: `BankAccount` → `BankStatementLine` → `ReconciliationMatch` → `ReconciliationSession`
- **Controlled Banking** (Sprint 27): `ControlledBankAccount` → `ControlledBankStatementLine` → `BankPaymentReconciliation`

There is no source-proven bridge between these two domains.

## Domain Gap: Two Separate Bank Account Models

The `ReconciliationSession` references `BankAccount` (`Modules/Finance/Banking/Models/BankAccount`), which has fields: `bank_name`, `account_name`, `account_number`, `opening_balance`, `current_balance`, `reconciled_balance`, `is_default`.

The Banking Operations Workspace (Sprint 27) projects `ControlledBankAccount` (`Modules/Finance/Banking/Models/ControlledBankAccount`), which has fields: `bank_name`, `account_name`, `external_account_reference`, `currency_code`, `operational_gl_account_id`, `is_active`, `registered_by`, `source_identity_hash`.

These are separate tables, separate models, with no cross-reference or bridge.

## Wave 1 Deliverable: Read-Only Reconciliation Session Workspace

### Scope

Extend the Banking Operations Workspace (`BankingOperationsWorkspaceController::index()`) with read-only `ReconciliationSession` evidence projection:

- Session ID, status, bank account reference, currency, date range
- Session property scope via `where('property_id', $propertyId)`
- `BankAccount` name resolved through the `bankAccount` relationship
- Match count resolved via `withCount('matches')`
- Server-projected capability flags: `can_view_reconciliation_sessions` via `$user->can('banking.reconciliation.view')`
- Controlled empty state
- No action buttons, no mutation

### Browser-input exclusion

Browser must never calculate, infer, or submit: opening balance, closing balance, reconciled balance, unreconciled balance, expected balance, variance, session status, bank account eligibility, statement-line eligibility, currency, property, company, team, actor.

### Permissions

Existing permissions used unchanged:
- `banking.reconciliation.view` → `can_view_reconciliation_sessions`
- `banking.reconciliation.create` → `can_create_reconciliation_session`
- `banking.reconciliation.manage` → `can_manage_reconciliation_session`

All capability flags are server-projected only.

## Wave 2: Session Creation — DEFERRED

| Missing proof | Evidence |
|---|---|
| Bank account model mismatch | `ReconciliationSession` references `BankAccount`; Banking Operations Workspace uses `ControlledBankAccount`. No bridge exists. |
| Browser-supplied financial value | API controller requires `opening_balance` as `required\|numeric`. Cannot safely accept from browser |
| Header-based property resolution | API controller uses `X-Property-Id` header; Inertia web layer uses session-based property resolution |
| No Inertia web controller pattern | No source-proven Inertia web controller for session creation exists |
| Service-level property/actor resolution | `ReconciliationSessionService::create()` receives raw `$data` array without actor or property resolution |
| Schema/migration required | Cannot safely reference `ControlledBankAccount` without schema change (prohibited) |

## Wave 2: Manual Bank Reconciliation Session Integration — DEFERRED

| Missing proof | Evidence |
|---|---|
| No session relationship | `BankPaymentReconciliation` has zero relationship to `ReconciliationSession` — no foreign key, no reference |
| No service integration | `ManualBankReconciliationService::reconcilePostedBankPayment()` does not accept or derive a session ID |
| Separate statement-line domains | `ReconciliationMatch` uses `BankStatementLine`; controlled reconciliation uses `ControlledBankStatementLine` |
| Schema migration required | Integration would require adding a `reconciliation_session_id` foreign key to `bank_payment_reconciliations` (prohibited) |
| Protected service modification | `ManualBankReconciliationService` would need modification to accept session context (prohibited) |
| No source-proven extension point | No existing service, model method, or policy defines session-linked reconciliation |

## No-Go Boundaries

- No automatic reconciliation
- No auto-matching
- No statement import
- No external bank API/integration
- No CSV/OFX/MT940 file ingestion
- No variance journaling
- No suspense posting
- No balance adjustment
- No Go/Python service
- No schema, migration, model, role, or permission creation
- No modification of `PaymentExecutionService`, `ManualBankReconciliationService`, or `SensitiveActionConfirmationService`
- No reuse of confirmation intents outside accepted scope
- No Cash/Cashbook ownership change
- No General Ledger, Financial Period, or Business Date mutation
- No movement of Banking lifecycle into General Cashier, Payables, or General Ledger

## Implementation Manifest

### Wave 1 (delivered by this ADR)

| Commit | Scope |
|---|---|
| Sprint 28: Define reconciliation session lifecycle boundary | ADR-071 only |
| Sprint 28: Add reconciliation session control workspace | Controller extension + page extension + workspace test |

### Wave 2 (deferred)

| Capability | Status | Blocker |
|---|---|---|
| Controlled Reconciliation Session Creation | DEFERRED | Bank account model mismatch (`BankAccount` vs `ControlledBankAccount`); browser-supplied `opening_balance`; API-only controller; no Inertia web pattern |
| Manual Bank Reconciliation Session Integration | DEFERRED | No session relationship on `BankPaymentReconciliation`; no service integration; separate statement-line domains; would require schema migration and protected service modification |

## Consequences

1. **Read-only visibility**: Finance users gain property-scoped reconciliation session evidence in the Banking Operations Workspace — session status, bank account, match counts, and capability flags — without any mutation risk.
2. **No lifecycle activation**: Session creation, completion, finalization, and session-linked reconciliation remain API-only and are not exposed through Inertia web routes.
3. **Domain gap documented**: The two separate bank account models (`BankAccount` vs `ControlledBankAccount`) and two separate reconciliation domains are explicitly documented as an architecture boundary requiring a future bridging decision.
4. **No schema change**: All existing database structure, models, roles, permissions, and services remain unchanged.
5. **Ownership preserved**: Banking owns the workspace projection. General Cashier, Payables, and General Ledger ownership boundaries remain unchanged.
