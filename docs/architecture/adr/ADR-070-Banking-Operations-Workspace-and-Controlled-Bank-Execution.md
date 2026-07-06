# ADR-070: Banking Operations Workspace and Controlled Bank Execution

**Status:** Accepted for controlled implementation
**Date:** 2026-07-07

## Context

Sprint 26 delivered controlled Cash Payment Execution with a dedicated `cash-payment-execution` confirmation intent. Sprint 27 extends the operational workspace layer to cover Banking-owned operations, completing the finance payment execution coverage with controlled Bank Payment Execution and Bank Reconciliation.

The Banking module (`Modules/Finance/Banking`) has existed as an API-only domain with no Inertia web controller. This ADR defines the exact Banking web workspace boundary, confirmation policy, and controlled Bank execution activation contract.

## Domain ownership matrix (reaffirmed)

| Domain | Owns | Source |
|---|---|---|
| Payables | Supplier invoice, three-way match, exception review, approval, payment proposal, AP settlement | `Modules/Finance/Payables` |
| General Ledger | Journal candidate, draft, finalization, posting, Financial Period, Business Date | `Modules/Finance/GeneralLedger` |
| General Cashier | Cash session, instrument, CASH payment execution, cash count, cash reconciliation, cashbook | `Modules/Operations/GeneralCashier` |
| Banking | Bank account, bank statement, BANK payment execution, bank reconciliation, banking workspace | `Modules/Finance/Banking` |

Banking owns the new operational workspace and any Banking mutation routes. Existing Cashbook Evidence Workspace remains General Cashier-owned. Existing Bank Context Projection in Cashbook Evidence remains read-only historical/operational evidence; no ownership is moved.

## Sprint 27 Banking Activation Ledger

### A1. Banking Operations Workspace — CONTEXT-READY

| Fact | Evidence |
|---|---|
| Domain owner | Banking (`Modules/Finance/Banking`) |
| Controller namespace/path convention | `Modules/Finance/Banking/Http/Controllers/BankingOperationsWorkspaceController.php` — follows existing Finance web controller pattern |
| Route group/web convention | `routes/web.php` under `Route::prefix('finance')->middleware(['auth', 'active.property'])` — follows existing `finance.payables.*` pattern |
| React/Inertia page convention | `resources/js/Pages/Ivorq/Finance/BankingOperationsWorkspace.tsx` — follows existing workspace page pattern with `IvorqLayout` |
| Read-only query boundary | `ControlledBankAccount::where('property_id', $propertyId)` for bank accounts; `ControlledBankStatementLine::whereIn('controlled_bank_account_id', ...)` with `direction = OUTFLOW` for statement lines; `PaymentExecution::whereNotNull('controlled_bank_account_id')` for bank payment execution evidence; `BankPaymentReconciliation::where('property_id', $propertyId)` for reconciliation evidence |
| Property/company/team scope | Session `active_property_id` resolved via `$request->session()->get('active_property_id') ?? app(CurrentPropertyService::class)->resolveOrFail()` |
| Capability projection convention | `$user->can(PaymentExecutionService::PERMISSION)` for `can_execute_bank`; `$user->can(ManualBankReconciliationService::PERMISSION)` for `can_reconcile` — server-projected only |
| No lifecycle mutation | Read-only workspace; no Bank account, statement line, payment execution, reconciliation, Cashbook, journal, allocation, Financial Period, or Business Date mutation |
| No browser financial calculation | All amounts, currency, status, eligibility server-resolved |
| Postgres test convention | `tests/Postgres/Finance/Banking/BankingOperationsWorkspaceTest.php` extending `PostgresTestCase` with `RefreshDatabase` |
| No General Cashier/Cashbook ownership change | `CashbookEvidenceWorkspaceController` and `CashbookEvidenceWorkspace.tsx` remain unchanged; Bank Execution Context Projection in Cashbook Evidence remains as-is |

### A2. Bank Payment Execution Confirmation — ACTIVATION-READY

| Fact | Evidence |
|---|---|
| Existing service | `SensitiveActionConfirmationService` (`Modules/Foundation/Authorization/Services/SensitiveActionConfirmationService.php`) |
| Existing intents | `finance-role-assignment`, `finance-approval`, `fx-break-glass`, `administrative-sensitive-action`, `cash-payment-execution` |
| New intent | `bank-payment-execution` — sixth intent in `REGISTERED_INTENTS` array |
| Extension mechanism | Add string to `REGISTERED_INTENTS`; add label in `intentLabel()` map; no `postConfirmationRoute` entry (no automatic continuation after Bank execution confirmation) |
| TTL | Uses existing `CONFIRMATION_TTL_MINUTES = 15` |
| Actor/company/property/session binding | Uses existing `hasValidConfirmation()` checks: actor_id, intent, property_id, company_id, expires_at |
| Audit behavior | Uses existing `auditService->log('sensitive_action_confirmed')` and `auditService->log('sensitive_action_invalidated')` |
| Confirm/invalidate | Uses existing `confirm()` and `invalidate()` methods |
| No authority granted | Intent itself grants no role, permission, bank account, statement line, payment, journal, Cashbook, Banking, or GL authority |
| No automatic continuation | `postConfirmationRoute` returns null for `bank-payment-execution` |
| Backward compatibility | All five existing intents unchanged; no schema/model/permission/role/service/provider/middleware/config/dependency/ownership change |
| Controller unchanged | `SensitiveActionConfirmationController` auto-validates against `REGISTERED_INTENTS`; existing `in_array` check accepts the new intent |
| Existing test | `tests/Postgres/Foundation/Authorization/SensitiveActionConfirmationTest.php` — extended with bank-payment-execution intent cases |

### A3. Controlled Bank Payment Execution — ACTIVATION-READY

| Fact | Evidence |
|---|---|
| Service | `PaymentExecutionService::recordConfirmedBankExecution(paymentProposalItemId, cashierSessionId, bankPaymentInstrumentId, controlledBankAccountId, controlledBankStatementLineId, actor)` (`PaymentExecutionService.php:125-246`) — unchanged |
| Permission | `finance.general-cashier.payment.execute` (`PaymentExecutionService.php:22`) |
| Browser identifier contract | `payment_proposal_item_id` (ULID), `cashier_session_id` (ULID), `bank_payment_instrument_id` (ULID), `controlled_bank_account_id` (ULID), `controlled_bank_statement_line_id` (ULID) — identifiers only |
| Server re-resolution chain | Actor (active, has permission, member of property) → proposal item (approved, active, property-scoped) → source journal (posted AP liability) → operational context (session OPEN, actor-owned, same property; instrument BANK, active, same property, operational GL) → bank account (active, same property, same operational GL, same currency) → statement line (same bank account, same property, same currency, OUTFLOW, amount match, vendor reference match) |
| Amount/currency derivation | From `PaymentProposalItem::requested_payment_amount ?? source_amount`; currency from item — server-resolved only |
| Property/company/team scope | Every target property-scoped; actor must have active property membership |
| Idempotency | `existingExecutionQuery()` + `assertExistingBankExecutionMatches()` — replay returns existing record; conflicting identity throws `DomainException` |
| Audit | `created_by`, `updated_by`, `executed_by`, `executed_at`, full `source_snapshot` including bank account, statement line, external reference |
| Segregation | Session ownership: `cashier_user_id` must equal actor ID |
| Downstream | `CashbookTransactionProjectionService` — existing, unchanged |
| Controller | `BankingOperationsWorkspaceController` (`Modules/Finance/Banking/Http/Controllers/BankingOperationsWorkspaceController.php`) — Banking-owned |
| Workspace page | `resources/js/Pages/Ivorq/Finance/BankingOperationsWorkspace.tsx` |
| Lifecycle service unchanged | No modification to `PaymentExecutionService` |
| No schema/migration/permission/role change | Confirmed — existing permission `finance.general-cashier.payment.execute` sufficient |
| Confirmation | `bank-payment-execution` — dedicated intent; required after authorization and server-side target resolution, before service invocation |
| Prohibited browser input | Amount, currency, rate, account state, account balance, statement amount, statement direction, property, company, team, actor, supplier, invoice, status, Cash session, Cash instrument, allocation, journal, mapping, audit payload, authority |
| Test | `tests/Postgres/Finance/Banking/BankPaymentExecutionWebActionTest.php` |

### A4. Bank Reconciliation Workspace / Context — CONTEXT-READY

| Fact | Evidence |
|---|---|
| Domain owner | Banking (`Modules/Finance/Banking`) |
| Aggregate/model | `BankPaymentReconciliation` (`Modules/Finance/Banking/Models/BankPaymentReconciliation.php:17`) |
| Existing service | `ManualBankReconciliationService::reconcilePostedBankPayment(postedJournalEntryId, controlledBankStatementLineId, actor)` (`ManualBankReconciliationService.php:25-109`) |
| Existing permission | `finance.banking.reconciliation.manual` (`ManualBankReconciliationService.php:22`) |
| Read-only evidence | Eligible posted bank payments (PaymentExecution with controlled_bank_account_id, linked to posted SupplierPaymentCashDisbursement journals), controlled bank statement lines (OUTFLOW, same property), existing reconciliation records (BankPaymentReconciliation) with status |
| Property scope | All queries scope to property_id from session |
| Capability | Server-projected `$user->can(ManualBankReconciliationService::PERMISSION)` |
| No mutation | Read-only evidence projection; no reconciliation creation, automatic matching, statement line reservation, balance calculation, execution creation |
| No browser financial calculation | Amounts, differences, status all server-resolved |
| Test | `tests/Postgres/Finance/Banking/BankReconciliationWorkspaceTest.php` |
| No schema/migration/permission/role change | Confirmed |

### A5. Manual Bank Reconciliation — ACTIVATION-READY

| Fact | Evidence |
|---|---|
| Service | `ManualBankReconciliationService::reconcilePostedBankPayment(postedJournalEntryId, controlledBankStatementLineId, actor)` (`ManualBankReconciliationService.php:25-109`) — unchanged |
| Permission | `finance.banking.reconciliation.manual` (`ManualBankReconciliationService.php:22`) |
| Identifier contract | `posted_journal_entry_id` (ULID), `controlled_bank_statement_line_id` (ULID) — identifiers only |
| Confirmation | NOT required — follows Cash Reconciliation pattern (operational evidence recording, not an approval/finalization decision). Service does not call `SensitiveActionConfirmationService` |
| Server re-resolution | Posted journal (Status Posted, source_module GeneralCashier, source_type PaymentExecution, posting_event SupplierPaymentCashDisbursement) → PaymentExecution (linked to journal, has controlled_bank_account_id and controlled_bank_statement_line_id) → BankAccount (active, same property, same operational GL, same currency) → StatementLine (same bank account, same property, same currency, OUTFLOW) → exact amount match |
| Idempotency | `sourceIdentityHash` (SHA-256 over contract + journal + execution + bank account + statement line + amounts + actor); `assertExistingReconciliationMatches()` returns existing; conflicting throws `DomainException` |
| Audit | `created_by`, `updated_by`, `reconciled_by`, `reconciled_at`, full `source_snapshot` |
| Controller | `BankingOperationsWorkspaceController` — Banking-owned |
| Prohibited browser input | Balance, adjustment, statement amount, account, currency, match result, journal, property, company, actor, status, audit payload, reconciliation authority |
| Test | `tests/Postgres/Finance/Banking/BankReconciliationWebActionTest.php` |
| No schema/migration/permission/role/service change | Confirmed |

## Browser-input exclusion rule

All Banking web actions follow the exact same browser-input exclusion as existing Finance actions:

- Browser supplies only target identifiers (ULID strings)
- Amount, currency, rate, and all financial values are server-resolved from source models
- Property, company, team, and actor are server-resolved from session
- Account eligibility, statement eligibility, and lifecycle state are server-resolved
- Confirmation is server-resolved (session-bound, TTL-bound)

## Confirmation policy

### bank-payment-execution intent

Defined as a narrow backward-compatible sixth intent in `SensitiveActionConfirmationService::REGISTERED_INTENTS`:

- Uses existing server-owned TTL (15 minutes)
- Uses existing actor/company/property/session binding
- Fails closed — missing/expired/wrong-intent/wrong-actor/wrong-company/wrong-property/wrong-session confirmation invokes no Bank execution service
- Grants no authority
- Has no automatic continuation (`postConfirmationRoute` returns null)
- Does not alter `finance-approval`, `cash-payment-execution`, `fx-break-glass`, or any other existing intent
- Required after authorization, property scope, and server-side target resolution but before Bank execution service invocation

### Bank Reconciliation

Manual Bank Reconciliation does NOT require confirmation. This follows the accepted Cash Reconciliation pattern (ADR-068 A6) where reconciliation is operational evidence recording, not an approval/finalization decision. The `ManualBankReconciliationService` does not call `SensitiveActionConfirmationService` and no confirmation enforcement is added.

## No automatic reconciliation

No automatic matching, auto-reconciliation, statement-line reservation, or persistent lock behavior is introduced. Manual reconciliation is the only reconciliation action in this package.

## No external bank integration

No bank API, public API, statement import, payment batch-file generation, or external bank integration is included.

## No ownership change

- General Cashier continues to own Cash session, Cash instrument, CASH execution, Cash reconciliation, Cashbook
- Banking owns bank account, bank statement, BANK execution, bank reconciliation, and the Banking Operations Workspace
- Payables owns supplier invoice, payment proposal, AP settlement
- General Ledger owns journals, Financial Period, Business Date, posting

## No role/permission/schema/lifecycle-service change

All existing roles, permissions, database schema, models, and lifecycle services remain unchanged. The `bank-payment-execution` intent is a string addition to an existing array constant.

## Sprint 27 Implementation Manifest

| Phase | Commit Subject | Scope |
|---|---|---|
| A | Sprint 27: Define banking operations activation boundary | ADR-068 update + ADR-070 |
| B | Sprint 27: Add banking operations workspace | `BankingOperationsWorkspaceController`, `BankingOperationsWorkspace.tsx`, route, `BankingOperationsWorkspaceTest` |
| C | Sprint 27: Add bank payment execution confirmation | `SensitiveActionConfirmationService` + `SensitiveActionConfirmationController` + `SensitiveActionConfirmationTest` extension |
| D | Sprint 27: Add controlled bank payment execution actions | Bank execute route + controller action + page action + `BankPaymentExecutionWebActionTest` |
| E | Sprint 27: Add bank reconciliation workspace | Reconciliation evidence projection in workspace + `BankReconciliationWorkspaceTest` |
| F | Sprint 27: Add bank reconciliation actions | Reconciliation route + controller action + page action + `BankReconciliationWebActionTest` |

## Consequences

1. **Banking operational visibility**: Finance users gain a Banking-owned workspace with bank account, statement line, payment execution, and reconciliation evidence — all scoped to current property.
2. **Controlled Bank execution**: Bank payment execution is activated with a dedicated `bank-payment-execution` confirmation intent, server-side target re-resolution, and the same security order as Cash execution.
3. **Bank reconciliation**: Manual bank reconciliation is activated as operational evidence recording without confirmation, following the accepted Cash reconciliation pattern.
4. **No new lifecycle**: All mutation actions call existing services only. No new state machine is introduced. No new service, model, permission, role, or schema change is required.
5. **Ownership preserved**: Banking module now has a web workspace while General Cashier, Payables, and General Ledger ownership boundaries remain unchanged.
