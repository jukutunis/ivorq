# ADR-068: Supplier Payment and Settlement Operational Workspaces

**Status:** Accepted for controlled implementation
**Date:** 2026-07-05

## Context

Sprint 22 delivered the FX operational readiness foundation: role segregation, controlled assignment with sensitive reauthentication, broad-admin break-glass, and finance-approval confirmation for all source-proven Finance approve/reject/finalize decisions. Sprint 23 now extends the operational workspace layer to cover the Purchase-to-Pay lifecycle, providing operational views of payment proposals, supplier invoices, settlement allocations, and reconciliation evidence.

This ADR defines the exact source-proven inventory for Sprint 23, documents deferred items where no source-proven web action boundary exists, and establishes the implementation manifest.

## Current Purchase-to-Pay lifecycle

The accepted lifecycle is:

1. **Supplier Invoice Registration** — `SupplierInvoiceRegistrationService` registers invoices with lines linked to receiving lines (three-way match anchor).
2. **Three-Way Match** — `ThreeWayMatchingEngine` matches PO vs. Receipt vs. Invoice; produces `Matched` or `Exception` status.
3. **Exception Review** — `SupplierInvoiceExceptionReviewService::resolveException()` resolves match exceptions (service only; no web route).
4. **Invoice Approval/Rejection** — `SupplierInvoiceApprovalService::approve()/reject()` transitions invoice to terminal state (service only; no web route).
5. **GRNI Clearing** — `GrniClearingApLiabilityCandidateService` creates journal candidates from approved invoices.
6. **GRNI Review/Materialize/Finalize/Post** — Existing GeneralLedger workflow (confirmed by Sprint 22).
7. **Payment Proposal Creation** — `PaymentProposalService::createDraft()/cancelDraft()` generates draft payment proposals from posted AP liability journal entries (web route exists).
8. **Payment Execution** — `PaymentExecutionService::recordCashExecution()/recordConfirmedBankExecution()` records execution evidence (service only; no web route).
9. **AP Settlement Allocation** — `ApSettlementAllocationService` records final settlement (service only; no web route).
10. **Cashbook Projection** — `CashbookTransactionProjectionService` projects cash transactions from posted payments (automatic).
11. **Cash/Bank Reconciliation** — Manual/automatic reconciliation services exist (service only; no web route).

## Domain ownership matrix

| Domain | Owns | Source |
|---|---|---|
| Payables | Supplier invoice, three-way match, exception review, approval, payment proposal, AP settlement | `Modules/Finance/Payables` |
| General Ledger | Journal candidate, draft, finalization, posting, Financial Period, Business Date | `Modules/Finance/GeneralLedger` |
| General Cashier | Cash session, instrument, CASH payment execution, cash count, cash reconciliation, cashbook | `Modules/Operations/GeneralCashier` |
| Banking | Bank account, bank statement, BANK payment reconciliation, bank evidence | `Modules/Finance/Banking` |
| Purchasing | PO, receiving document, GRN | `Modules/Operations/Purchasing` |
| Inventory | Quantity, valuation, ledger | `Modules/Operations/Inventory` |

## Current source-proven operational action inventory

### Actions with existing web controller + route + service

| # | Domain | Action | Controller | Route Name | Service | Permission |
|---|---|---|---|---|---|---|
| P1 | Payables | View AP/GRNI Settlement Workspace | `ApGrniSettlementControlWorkspaceController@index` | `finance.payables.ap-grni-settlement-control` | `ApGrniSettlementAgingProjectionService` | OR-gate across 5 permissions |
| P2 | Payables | Create Payment Proposal Draft | `ApGrniSettlementControlWorkspaceController@createDraft` | `finance.payables.ap-grni-settlement-control.payment-proposals.create` | `PaymentProposalService::createDraft()` | `finance.payables.payment-proposal.create` |
| P3 | Payables | Cancel Payment Proposal Draft | `ApGrniSettlementControlWorkspaceController@cancelDraft` | `finance.payables.ap-grni-settlement-control.payment-proposals.cancel` | `PaymentProposalService::cancelDraft()` | `finance.payables.payment-proposal.cancel` |

### Actions with existing service but NO web controller/route

| # | Domain | Action | Service | Web Route? | Blocker |
|---|---|---|---|---|---|
| D1 | Payables | Approve Supplier Invoice | `SupplierInvoiceApprovalService::approve()` | Yes (Sprint 24) | Activated |
| D2 | Payables | Reject Supplier Invoice | `SupplierInvoiceApprovalService::reject()` | Yes (Sprint 24) | Activated |
| D3 | Payables | Resolve Match Exception | `SupplierInvoiceExceptionReviewService::resolveException()` | Yes (Sprint 25) | Activated |
| D4 | Payables | Approve Payment Proposal | `PaymentProposalApprovalService::approve()` | Yes (Sprint 24) | Activated |
| D5 | Payables | Reject Payment Proposal | `PaymentProposalApprovalService::reject()` | Yes (Sprint 24) | Activated |
| D6 | GeneralCashier | Record Cash Execution | `PaymentExecutionService::recordCashExecution()` | No | Deferred — browser-supplied cash instrument and proposal item selection; no source-proven web action convention; no execution workspace controller; no source-proven confirmation intent (finance-approval is for approval decisions, not operational execution) |
| D7 | Banking | Record Confirmed Bank Execution | `PaymentExecutionService::recordConfirmedBankExecution()` | No | Deferred — browser-supplied bank account and statement line selection; no source-proven web action convention; no execution workspace controller; no source-proven confirmation intent |
| D8 | GeneralCashier | Record Cash Count | `CashCountAndBaselineService::recordCashCount()` | No | No controller |
| D9 | GeneralCashier | Perform Manual Cash Reconciliation | `ManualCashReconciliationService::reconcile()` | No | No controller |
| D10 | Banking | Register Bank Account | `BankingSourceEvidenceService::registerBankAccount()` | No | API-only |
| D11 | Banking | Register Statement Line | `BankingSourceEvidenceService::registerStatementLine()` | No | API-only |
| D12 | Banking | Reconcile Bank Payment | `ManualBankReconciliationService::reconcilePostedBankPayment()` | No | No controller |
| D13 | Banking | Create Reconciliation Session | `ReconciliationSessionService::create()` | `Banking\routes\api.php` | API-only |

Deferred items D1-D13 are source-proven services lacking web controller/route boundaries. They are documented for future packages. This Sprint 23 package delivers operational workspaces only for actions P1-P3 (source-proven web boundaries).

## Current permission and capability projection approach

The existing `ApGrniSettlementControlWorkspaceController` projects capabilities via `$user->can()`:
```php
'permissions' => [
    'can_view' => true,
    'can_create_payment_proposal' => $user->can(PaymentProposalService::CREATE_PERMISSION),
    'can_cancel_payment_proposal' => $user->can(PaymentProposalService::CANCEL_PERMISSION),
],
```

This convention is preserved. No new permissions are created.

## Current property/team isolation model

All Finance routes are under `Route::middleware(['auth', 'active.property'])`. Property is resolved from `$request->session()->get('active_property_id')`. All data queries scope to the resolved property. No cross-property data exposure. This model is preserved unchanged.

## Payment Proposal workspace boundary

**Deliverable**: Enhanced operational workspace with queue-first layout showing:
- Draft payment proposals with vendor, invoice, amount, currency, status evidence
- Ready-to-settle GRNI items projected from `ApGrniSettlementAgingProjectionService`
- Create draft and cancel draft actions (existing P2, P3)
- Approve and reject actions for PENDING_APPROVAL proposals (D4, D5 — activated in Sprint 24)
- Server-projected capability gating including `can_approve` and `can_reject`

**Activated in Sprint 24**: Payment proposal approval and rejection web routes are now exposed through `PaymentProposalControlWorkspaceController` using `PaymentProposalApprovalService::approve()/reject()` with permission `finance.payables.payment-proposal.approve` and `finance-approval` sensitive confirmation enforcement following the existing GRNI candidate review pattern.

## Supplier Invoice / Three-Way Match / Exception workspace boundary

**Deliverable**: Evidence-first read-only operational view showing:
- Supplier invoices with match status, match details, GRNI/AP evidence
- Three-way match results (matched vs. exception)
- Exception queue with variance details
- Approve and reject actions for eligible invoices (D1, D2 — activated in Sprint 24)
- Server-projected capability gating including `can_approve` and `can_reject`

**Activated in Sprint 24**: Supplier invoice approval and rejection web routes are now exposed through `SupplierInvoiceControlWorkspaceController` using `SupplierInvoiceApprovalService::approve()/reject()` with permission `finance.payables.supplier-invoice.approve` and `finance-approval` sensitive confirmation enforcement following the same pattern as Payment Proposal approval actions.

**Activated in Sprint 25**: Supplier invoice exception resolution web route is now exposed through `SupplierInvoiceControlWorkspaceController` using `SupplierInvoiceExceptionReviewService::resolveException()` with permission `finance.payables.supplier-invoice.review-exception` and `finance-approval` sensitive confirmation enforcement. Exception resolution is a prerequisite for approval of invoices with Exception match results and records immutable Finance review evidence without changing the invoice status.

**Not delivered**: Invoice creation (no source-proven route).

## Cash payment execution workspace boundary

**Deliverable**: Read-only projection of existing Cash execution evidence and Cashbook transactions.

**Not delivered**: Cash execution recording (D6 — deferred). Cash execution requires browser-supplied cash instrument and proposal item selection, which are operational financial resource selections with no source-proven web action convention. The `PaymentExecutionService::recordCashExecution()` also requires cash session ownership resolution through `GeneralCashierOperationalFoundationService`, which is a server-side operational context that cannot safely accept browser-supplied identifiers. No source-proven confirmation intent exists for cash execution (the `finance-approval` intent is scoped to approval/finalization decisions, not operational payment execution). The existing `CashbookEvidenceWorkspace` is read-only with no execution controller. Cash count (D8) and cash reconciliation (D9) remain deferred.

## Bank payment execution workspace boundary

**Deliverable**: Read-only projection of existing Bank execution evidence and bank statement lines.

**Not delivered**: Bank execution recording (D7 — deferred). Bank execution additionally requires browser-supplied bank account selection and controlled bank statement line selection, which are operational financial resource selections with no source-proven web action convention. As with cash execution, no source-proven confirmation intent exists for bank execution. Bank account registration (D10) and bank reconciliation (D12) remain deferred.

## AP settlement allocation and payment-evidence boundary

**Deliverable**: Evidence-first view of existing AP settlement allocations showing source payment, allocation amount, and settlement status.

**Not delivered**: Allocation creation (service-only, no web route).

## Cashbook / cash reconciliation evidence boundary

**Deliverable**: Read-only projection of CashbookTransaction records with source payment evidence.

**Not delivered**: Reconciliation creation (D9 — deferred).

## Banking / bank reconciliation evidence boundary

**Deliverable**: Read-only projection of BankPaymentReconciliation records and controlled bank statement lines.

**Not delivered**: Reconciliation session creation (D13 — deferred), auto-matching, variance journaling.

## Approval confirmation requirements

Actions D4-D5 (proposal approve/reject) and D1-D2 (supplier invoice approve/reject) are now activated in Sprint 24 and require `finance-approval` confirmation enforcement. The controller follows the exact same pattern as `GrniControlWorkspaceController`: authorize action permission first, then require valid `SensitiveActionConfirmationService::hasValidConfirmation()` for `finance-approval` intent before invoking the lifecycle service. Missing confirmation redirects to `system.sensitive-action-confirmation.index` with the `finance-approval` intent and a server-owned error message. Actions D3 (exception resolution) remains deferred. Existing confirmation enforcement for GRNI approve/reject/finalize and FX review/finalize remains unchanged.

Cash and Bank execution (D6, D7) do not currently have a source-proven confirmation intent. The `finance-approval` intent is scoped to approval/finalization decisions, not operational payment execution. Execution confirmation policy remains deferred until a future package sources the exact execution authorization and confirmation boundary.

### Sprint 24-25 activated route contracts

| Action | Route | Controller Method | Service Method | Permission | Confirmation |
|---|---|---|---|---|---|
| Approve Payment Proposal | `POST /finance/payables/payment-proposals/{proposal}/approve` | `PaymentProposalControlWorkspaceController@approve` | `PaymentProposalApprovalService::approve()` | `finance.payables.payment-proposal.approve` | `finance-approval` |
| Reject Payment Proposal | `POST /finance/payables/payment-proposals/{proposal}/reject` | `PaymentProposalControlWorkspaceController@reject` | `PaymentProposalApprovalService::reject()` | `finance.payables.payment-proposal.approve` | `finance-approval` |
| Approve Supplier Invoice | `POST /finance/payables/supplier-invoices/{invoice}/approve` | `SupplierInvoiceControlWorkspaceController@approve` | `SupplierInvoiceApprovalService::approve()` | `finance.payables.supplier-invoice.approve` | `finance-approval` |
| Reject Supplier Invoice | `POST /finance/payables/supplier-invoices/{invoice}/reject` | `SupplierInvoiceControlWorkspaceController@reject` | `SupplierInvoiceApprovalService::reject()` | `finance.payables.supplier-invoice.approve` | `finance-approval` |
| Resolve Invoice Exception | `POST /finance/payables/supplier-invoices/{invoice}/resolve-exception` | `SupplierInvoiceControlWorkspaceController@resolveException` | `SupplierInvoiceExceptionReviewService::resolveException()` | `finance.payables.supplier-invoice.review-exception` | `finance-approval` |

Input contracts:
- Approve (both): no body input; actor, property, company resolved server-side
- Reject (both): `rejection_reason` (required, string, min 3, max 500); actor, property, company resolved server-side
- Resolve Exception: `resolution_reason` (required, string, min 3, max 500); actor, property, company resolved server-side

The browser must not supply amount, currency, account, bank, allocation, invoice, journal, property, company, actor, or status.

## Evidence-first UX pattern

All Sprint 23 workspaces follow:
- Queue-first layout with server-projected status
- Evidence panels showing source references (vendor, invoice, GRN, journal entry)
- Lifecycle state badges
- Capability-gated action buttons
- Controlled empty states
- Controlled error states
- No browser-side financial calculation
- No browser-supplied property, company, actor, rate, amount, currency, account, mapping, or lifecycle state

## No browser-supplied financial control inputs

Browser may only supply:
- Action identifiers (create, cancel) via route names
- Existing validated input fields (journal_entry_ids, cancellation_reason)
- No amount, rate, currency, account, mapping, property, company, or lifecycle status

## No direct posting or accounting bypass

Workspace views are read/projection only. Mutation actions call existing lifecycle services only. No new lifecycle service is created.

## Current known deferred items

| Item | Service Exists | Web Route? | Blocker |
|---|---|---|---|---|
| Exception resolution | Yes | No | No controller |
| Cash execution | Yes | No | Deferred — browser-supplied cash instrument and proposal item selection; no execution workspace controller; no source-proven confirmation convention |
| Bank execution | Yes | No | Deferred — browser-supplied bank account and statement line selection; no execution workspace controller; no source-proven confirmation convention |
| Cash count | Yes | No | No controller |
| Cash reconciliation | Yes | No | No controller |
| Bank reconciliation | Yes | No (API-only) | No web route |
| Bank account registration | Yes | Yes (API) | API-only, not Inertia |
| Settlement allocation | Yes | No | No controller |

## Commit-by-commit Sprint 23 implementation manifest

| Phase | Commit Subject | Scope |
|---|---|---|
| A | Sprint 23: Define supplier payment operations boundary | ADR-068 only |
| B | Sprint 23: Add payment proposal control workspace | Enhanced workspace page + projection controller extension |
| C | Sprint 23: Add payment execution evidence workspace | Cashbook and bank execution evidence read-only views |
| D | Sprint 23: Add settlement and reconciliation evidence workspace | AP settlement, cashbook, bank evidence projection |
| E | Sprint 23: Add supplier invoice control workspace | Invoice/match/exception evidence workspace |

## Consequences

1. **Operational visibility**: Finance users gain workspace views of payment proposals, invoices, match results, settlement allocations, and reconciliation evidence — all scoped to current property.
2. **No new lifecycle**: All mutation actions call existing services only. No new state machine is introduced. Payment proposal and supplier invoice approval/rejection routes activated in Sprint 24 using existing services: `PaymentProposalApprovalService` and `SupplierInvoiceApprovalService`.
3. **Deferred web actions**: 8 source-proven services remain without web exposure. Cash and Bank execution deferred due to browser-supplied operational resource selection requirements and unproven confirmation conventions.
4. **No confirmation expansion**: Since no new approve/reject/finalize web actions are added, no new `finance-approval` confirmation enforcement is required.

## Sprint 25 Master Activation Ledger

### A1. AP Settlement Allocation — ACTIVATION-READY

| Fact | Evidence |
|---|---|
| Domain owner | Payables (`Modules/Finance/Payables`) |
| Aggregate/model | `ApSettlementAllocation` (`Modules/Finance/Payables/Models/ApSettlementAllocation.php:16`) |
| Lifecycle | Immutable settlement allocation; validates posted AP liability + posted supplier payment JournalEntry evidence; allocation amount must match payment amount exactly; checks outstanding AP liability ceiling; closes partial payment intent on PaymentProposalItem |
| Service | `ApSettlementAllocationService::allocate(apJournalEntryId, paymentJournalEntryId, amount, actor)` (`Modules/Finance/Payables/Services/ApSettlementAllocationService.php:26`) |
| Permission | `finance.payables.ap-settlement.allocate` (`ApSettlementAllocationService.php:19`) |
| Property scope | `assertActorCanAccessProperty()` checks active property membership on AP journal property (`ApSettlementAllocationService.php:130-140`) |
| Target lookup | `apJournalEntryId` → `JournalEntry::lockForUpdate()->firstOrFail()`; `paymentJournalEntryId` → `JournalEntry::lockForUpdate()->firstOrFail()`; `PaymentExecution` resolved from payment journal `source_id` |
| Request input | `ap_journal_entry_id` (ULID string, 26 chars), `payment_journal_entry_id` (ULID string, 26 chars) — identifiers only |
| Prohibited browser input | Amount (derived from `paymentExecution->source_amount`), currency (from payment execution), property (from journal), actor (server-resolved session), vendor, allocation status, journal, account, mapping |
| Segregation | Self-service: same actor allocates; no maker-checker in current slice |
| Idempotency | `sourceIdentityHash` (SHA-256 over contract + all source IDs + amount + actor); `assertExistingAllocationMatches()` on replay returns existing record; conflicting identity throws `DomainException` |
| Audit | `created_by`, `updated_by` on model |
| Confirmation | NOT required — this service does not call `SensitiveActionConfirmationService`; settlement allocation is operational settlement evidence, not an approval/finalization decision |
| Existing controller | `ApGrniSettlementControlWorkspaceController` (`Modules/Finance/Payables/Http/Controllers/ApGrniSettlementControlWorkspaceController.php`) — extends with `allocate` action |
| Existing workspace page | `resources/js/Pages/Ivorq/Finance/ApGrniSettlementControlWorkspace.tsx` |
| Existing test | `tests/Postgres/Finance/Payables/ApSettlementAllocationFoundationTest.php` (foundation test proving permission, property scope, idempotency) |
| Lifecycle service unchanged | No modification to `ApSettlementAllocationService` |
| No schema/migration/permission/role change | Confirmed |

### A2. Cash Execution Context Projection — CONTEXT-READY

| Fact | Evidence |
|---|---|
| Domain owner | General Cashier (`Modules/Operations/GeneralCashier` / `Modules/Finance/GeneralCashier`) |
| Models available | `PaymentProposalItem` (with `proposal`, `sourceJournalEntry`, `supplierInvoice`), `CashierSession`, `CashierPaymentInstrument` |
| Existing read-only boundary | `CashbookEvidenceWorkspaceController::index()` (`Modules/Finance/GeneralCashier/Http/Controllers/CashbookEvidenceWorkspaceController.php:15`) — projects `transactions` (CashbookTransaction) and `approved_proposals` (APPROVED PaymentProposals) |
| Existing workspace page | `resources/js/Pages/Ivorq/Finance/CashbookEvidenceWorkspace.tsx` |
| Eligibility derivation | Server queries `PaymentProposalItem` with `proposal.status = APPROVED`, `is_active = true`, scoped to current property; `CashierSession` with `status = OPEN` and `cashier_user_id = actor.id`; `CashierPaymentInstrument` with `type = CASH`, `is_active = true`, same property |
| Source evidence | Amount from `PaymentProposalItem::source_amount` / `requested_payment_amount`; currency from item; payment reference from proposal number + invoice number; capability from server `$user->can()` |
| Prohibited | No browser financial calculation, eligibility calculation, totals, balances, execution readiness, or fake execution button |
| Confirmation | N/A — read-only projection |
| Existing test convention | `tests/Postgres/Finance/Payables/CashbookEvidenceWorkspaceTest.php` |
| No schema/migration/permission/role change | Confirmed |

### A3. Cash Payment Execution — ACTIVATION-READY (Sprint 26)

Defined in ADR-069. All missing Sprint 25 boundaries are now source-proven: CashbookEvidenceWorkspaceController is the execution web controller extension point; the `cash-payment-execution` dedicated confirmation intent provides the execution confirmation convention; server-side identifier re-resolution through `PaymentExecutionService::recordCashExecution()` and `GeneralCashierOperationalFoundationService::resolveOperationalContext()` validates every browser-supplied identifier.

| Fact | Evidence |
|---|---|
| Service | `PaymentExecutionService::recordCashExecution()` (`Modules/Operations/GeneralCashier/Services/PaymentExecutionService.php:28-123`) — unchanged |
| Permission | `finance.general-cashier.payment.execute` (`PaymentExecutionService.php:22`) |
| Browser input | `payment_proposal_item_id`, `cashier_session_id`, `cashier_payment_instrument_id` (three ULID identifiers only) |
| Server revalidation | Full chain: actor + permission, proposal item (approved, active, property-scoped), source journal (posted AP liability), session (OPEN, actor-owned, same property), instrument (active, CASH, same property), operational account (active, same property) |
| Idempotency | `existingExecutionQuery()` + `assertExistingExecutionMatches()` with full field comparison |
| Audit | `created_by`, `updated_by`, `executed_by`, `executed_at`, full `source_snapshot` |
| Segregation | Session ownership: `cashier_user_id` must equal actor (`resolveOperationalContext()` line 103-104) |
| Downstream | `CashbookTransactionProjectionService` — existing, unchanged |
| Confirmation | `cash-payment-execution` — dedicated intent defined in ADR-069; uses existing `SensitiveActionConfirmationService` mechanism |
| Controller | `CashbookEvidenceWorkspaceController` (`Modules/Finance/GeneralCashier/Http/Controllers/CashbookEvidenceWorkspaceController.php`) |
| Workspace page | `resources/js/Pages/Ivorq/Finance/CashbookEvidenceWorkspace.tsx` |
| Lifecycle service unchanged | No modification to `PaymentExecutionService` |
| No schema/migration/permission/role change | Confirmed |

### A4. Bank Execution Context Projection — CONTEXT-READY

| Fact | Evidence |
|---|---|
| Domain owner | Banking (`Modules/Finance/Banking`) for bank evidence; General Cashier for execution context |
| Models available | `PaymentProposalItem`, `ControlledBankAccount`, `ControlledBankStatementLine` |
| Existing read-only boundary | `CashbookEvidenceWorkspaceController::index()` — extends to include bank execution context |
| Eligibility derivation | Server queries approved proposal items with BANK instrument type; `ControlledBankAccount` with `is_active = true`, same property, currency match; `ControlledBankStatementLine` with matching account, `direction = OUTFLOW`, property scope |
| Source evidence | Amount and currency from proposal item; bank account reference from `ControlledBankAccount`; statement line evidence; capability from server `$user->can()` |
| Prohibited | No browser financial calculation, eligibility, execution readiness, or execution button |
| Confirmation | N/A — read-only projection |
| No schema/migration/permission/role change | Confirmed |

### A5. Bank Payment Execution — DEFERRED

| Missing source boundary | Evidence |
|---|---|
| No execution workspace/controller | Same as A3 — no source-proven controller for recording confirmed bank payment execution |
| Browser-supplied operational resource selection | `PaymentExecutionService::recordConfirmedBankExecution()` requires `controlledBankAccountId` and `controlledBankStatementLineId` in addition to session/instrument identifiers. Bank account and statement line are multi-property financial resources requiring server-side eligibility projection |
| No source-proven confirmation convention | Same as A3 — no confirmation intent for operational bank payment execution |
| ADR-068 Sprint 23 analysis | "Deferred — browser-supplied bank account and statement line selection required; no execution workspace controller; no confirmation convention" |
| Cross-domain complexity | Bank execution crosses Banking domain (bank account, statement line), General Cashier domain (cashier session), and Payables domain (payment proposal). No cross-domain workspace exists |

### A6. Cash Reconciliation — ACTIVATION-READY

| Fact | Evidence |
|---|---|
| Domain owner | General Cashier (`Modules/Operations/GeneralCashier`) |
| Aggregate/model | `CashReconciliation` (`Modules/Operations/GeneralCashier/Models/CashReconciliation.php:16`) |
| Lifecycle | Derives expected vs. observed amounts from baseline + CashbookTransaction scope; status = RECONCILED if difference = 0, EXCEPTION otherwise |
| Service | `ManualCashReconciliationService::reconcile(cashReconciliationBaselineId, endingCashCountEvidenceId, actor)` (`Modules/Operations/GeneralCashier/Services/ManualCashReconciliationService.php:22`) |
| Permission | `finance.general-cashier.cash-reconciliation.perform` (`ManualCashReconciliationService.php:19`) |
| Property scope | `assertActorCanAccessProperty()` on baseline property (`ManualCashReconciliationService.php:120-130`) |
| Target lookup | `CashReconciliationBaseline::lockForUpdate()->firstOrFail()`; `CashCountEvidence::lockForUpdate()->firstOrFail()` |
| Request input | `cash_reconciliation_baseline_id` (ULID string), `ending_cash_count_evidence_id` (ULID string) — identifiers only |
| Prohibited browser input | Amounts (baseline, inflow, outflow, expected, observed, difference all server-derived from baseline + transaction scope), currency (from baseline), property (from baseline), status (server-derived), account, journal, reconciliation authority |
| Segregation | Self-service in current slice |
| Idempotency | `sourceIdentityHash` (SHA-256 over contract + baseline + count + property + account + currency + amounts + transaction IDs); `assertExistingReconciliationMatches()` returns existing; conflicting throws `DomainException` |
| Audit | `created_by`, `updated_by` on model; `source_snapshot` records full derivation evidence |
| Confirmation | NOT required — service does not call `SensitiveActionConfirmationService`; reconciliation is operational evidence recording |
| Existing controller | `CashbookEvidenceWorkspaceController` (`Modules/Finance/GeneralCashier/Http/Controllers/CashbookEvidenceWorkspaceController.php`) — extends with `reconcile` action |
| Existing workspace page | `resources/js/Pages/Ivorq/Finance/CashbookEvidenceWorkspace.tsx` |
| Existing test | `tests/Postgres/Operations/GeneralCashier/ManualCashReconciliationTest.php` (proves exact match reconciliation, idempotent replay, property scope, invalid actor denial) |
| Lifecycle service unchanged | No modification to `ManualCashReconciliationService` |
| No schema/migration/permission/role change | Confirmed |

### A7. Bank Reconciliation — DEFERRED

| Missing source boundary | Evidence |
|---|---|
| No banking web workspace controller | Banking module has API-only controllers (`BankAccountController`, reconciliation sessions API). No Inertia web controller exists for bank reconciliation workspace |
| ADR-068 Sprint 23 analysis | D12: "Reconcile Bank Payment — service only; no web route" — remains deferred |
| No source-proven web action convention | `ManualBankReconciliationService::reconcilePostedBankPayment()` takes `postedJournalEntryId` and `controlledBankStatementLineId` as identifiers, but the existing CashbookEvidenceWorkspaceController belongs to General Cashier domain, not Banking. Bank reconciliation requires a Banking-specific workspace controller that does not exist |
| Cross-domain complexity | Bank reconciliation spans Banking (bank account, statement line, bank payment reconciliation), General Cashier (payment execution), and Payables (payment proposal). No unified web workspace exists |
| Cannot safely extend CashbookEvidenceWorkspaceController | The CashbookEvidenceWorkspaceController is scoped to cash/cashbook evidence. Bank reconciliation is a Banking domain action with different model ownership, different operational evidence, and different reconciliation matching logic |

## Sprint 25 Activation Matrix Summary

| # | Capability | Status | Service | Permission | Controller Extension | Test |
|---|---|---|---|---|---|---|
| A1 | AP Settlement Allocation | DELIVERED Sprint 25 | `ApSettlementAllocationService::allocate()` | `finance.payables.ap-settlement.allocate` | `ApGrniSettlementControlWorkspaceController` | `ApSettlementAllocationWebActionTest` |
| A2 | Cash Execution Context Projection | DELIVERED Sprint 25 | Read-only model queries | N/A (read-only) | `CashbookEvidenceWorkspaceController` | `CashExecutionContextProjectionTest` |
| A3 | Cash Payment Execution | DELIVERED Sprint 26 | `PaymentExecutionService::recordCashExecution()` | `finance.general-cashier.payment.execute` | `CashbookEvidenceWorkspaceController` | `CashPaymentExecutionWebActionTest` |
| A4 | Bank Execution Context Projection | DELIVERED Sprint 25 | Read-only model queries | N/A (read-only) | `CashbookEvidenceWorkspaceController` | `BankExecutionContextProjectionTest` |
| A5 | Bank Payment Execution | DEFERRED | `PaymentExecutionService::recordConfirmedBankExecution()` | `finance.general-cashier.payment.execute` | — | — |
| A6 | Cash Reconciliation | DELIVERED Sprint 25 | `ManualCashReconciliationService::reconcile()` | `finance.general-cashier.cash-reconciliation.perform` | `CashbookEvidenceWorkspaceController` | `CashReconciliationWebActionTest` |
| A7 | Bank Reconciliation | DEFERRED | `ManualBankReconciliationService::reconcilePostedBankPayment()` | `finance.banking.reconciliation.manual` | — | `ManualBankReconciliationTest` |

## Phase Implementation Path Manifests

### Phase B — AP Settlement Allocation (A1 ACTIVATION-READY)

Allowed repository paths:
- `docs/architecture/adr/ADR-068-Supplier-Payment-and-Settlement-Operational-Workspaces.md`
- `routes/web.php`
- `Modules/Finance/Payables/Http/Controllers/ApGrniSettlementControlWorkspaceController.php`
- `resources/js/Pages/Ivorq/Finance/ApGrniSettlementControlWorkspace.tsx`
- `tests/Postgres/Finance/Payables/ApSettlementAllocationWebActionTest.php`
- `C:\Users\edigd\.ivorq-local\Invoke-IvorqPgApSettlementAllocationWebActionTest.ps1`

### Phase C — Cash Execution Context Projection (A2 CONTEXT-READY)

Allowed repository paths:
- `docs/architecture/adr/ADR-068-Supplier-Payment-and-Settlement-Operational-Workspaces.md`
- `routes/web.php` (GET only, no mutation route)
- `Modules/Finance/GeneralCashier/Http/Controllers/CashbookEvidenceWorkspaceController.php`
- `resources/js/Pages/Ivorq/Finance/CashbookEvidenceWorkspace.tsx`
- `tests/Postgres/Finance/GeneralCashier/CashExecutionContextProjectionTest.php`
- `C:\Users\edigd\.ivorq-local\Invoke-IvorqPgCashExecutionContextProjectionTest.ps1`

### Phase G — Cash Reconciliation (A6 ACTIVATION-READY)

Allowed repository paths:
- `docs/architecture/adr/ADR-068-Supplier-Payment-and-Settlement-Operational-Workspaces.md`
- `routes/web.php`
- `Modules/Finance/GeneralCashier/Http/Controllers/CashbookEvidenceWorkspaceController.php`
- `resources/js/Pages/Ivorq/Finance/CashbookEvidenceWorkspace.tsx`
- `tests/Postgres/Finance/GeneralCashier/CashReconciliationWebActionTest.php`
- `C:\Users\edigd\.ivorq-local\Invoke-IvorqPgCashReconciliationWebActionTest.ps1`

### Phase C (Sprint 26) — Cash Payment Execution (A3 ACTIVATION-READY)

See ADR-069 for full activation contract and confirmation policy.

Allowed repository paths:
- `docs/architecture/adr/ADR-068-Supplier-Payment-and-Settlement-Operational-Workspaces.md`
- `routes/web.php`
- `Modules/Finance/GeneralCashier/Http/Controllers/CashbookEvidenceWorkspaceController.php`
- `resources/js/Pages/Ivorq/Finance/CashbookEvidenceWorkspace.tsx`
- `tests/Postgres/Finance/GeneralCashier/CashPaymentExecutionWebActionTest.php`
- `tests/Postgres/Finance/GeneralCashier/CashExecutionContextProjectionTest.php`

## Deferred decisions

| Decision | Status |
|---|---|
| Exception resolution | Activated Sprint 25 |
| Invoice approval/rejection web routes | Activated Sprint 24 |
| Payment proposal approval/rejection web routes | Activated Sprint 24 |
| AP Settlement Allocation web route | ACTIVATION-READY Sprint 25 (this package) |
| Cash Execution Context Projection | CONTEXT-READY Sprint 25 (this package) |
| Cash Payment Execution web route | ACTIVATION-READY Sprint 26 — dedicated `cash-payment-execution` confirmation defined in ADR-069 |
| Bank Execution Context Projection | CONTEXT-READY Sprint 25 (this package) |
| Bank Payment Execution web route | DEFERRED — no execution workspace/controller; no confirmation convention; browser-supplied bank account/statement line selection; cross-domain complexity |
| Cash Reconciliation web route | ACTIVATION-READY Sprint 25 (this package) |
| Bank Reconciliation web route | DEFERRED — no banking web workspace controller; API-only banking module; cross-domain complexity |
| Payment scheduling | Deferred |
| Bulk payment execution | Deferred |
| External bank integration | Deferred |
| Bank statement import UI | Deferred — API exists |
| Supplier portal | Deferred |
| Payment batch file generation | Deferred |
| Invoice OCR | Deferred |
| Automated reconciliation | Deferred |
| Mobile approval | Deferred |

## Sprint 27 Supersession — Banking Operations Workspace and Controlled Bank Execution

**Supersedes**: Historical Sprint 23 wording that deferred Bank Payment Execution (A5/D7) and Bank Reconciliation (A7/D12) as "deferred — no banking web workspace controller; API-only banking module; cross-domain complexity."

**Current state (Sprint 27)**: The Banking module now has a source-proven web workspace controller (`BankingOperationsWorkspaceController`) as the Banking-owned action owner. The `bank-payment-execution` dedicated confirmation intent provides the execution confirmation convention. Bank Payment Execution and Manual Bank Reconciliation are now activation-ready with source-proven boundaries.

The following Sprint 23 deferred items are now superseded:

| Item | Sprint 23 Status | Sprint 27 Status |
|---|---|---|
| Bank Execution (D7) | DEFERRED | ACTIVATION-READY — Banking-owned workspace controller, `bank-payment-execution` confirmation, existing `PaymentExecutionService::recordConfirmedBankExecution()`, permission `finance.general-cashier.payment.execute` |
| Bank Reconciliation (D12) | DEFERRED | ACTIVATION-READY — Banking-owned workspace controller, existing `ManualBankReconciliationService::reconcilePostedBankPayment()`, permission `finance.banking.reconciliation.manual`, no confirmation required |

**Banking Operations Workspace boundary (Sprint 27)**:

- Banking owns the new operational workspace and any Banking mutation routes
- Existing Cashbook Evidence Workspace (`CashbookEvidenceWorkspaceController`) remains General Cashier-owned
- Existing Bank Context Projection (`projectBankExecutionContext()`) in Cashbook Evidence remains read-only historical/operational evidence; ownership is NOT moved
- The Banking workspace does not create a new Banking lifecycle
- All Bank targets are re-resolved server-side; browser values are never trusted for amount, currency, account, statement line, scope, lifecycle, or authority

**Bank execution confirmation (Sprint 27)**:

- `bank-payment-execution` is a narrow backward-compatible sixth intent extension of `SensitiveActionConfirmationService::REGISTERED_INTENTS`
- Uses existing server-owned TTL, actor/company/property/session binding, confirm/invalidate audit
- Grants no authority; has no automatic continuation
- Does not alter `finance-approval`, `cash-payment-execution`, `fx-break-glass`, or any other existing intent

**Bank reconciliation confirmation (Sprint 27)**:

- Manual Bank Reconciliation does NOT require confirmation
- Follows accepted Cash Reconciliation pattern (A6) — operational evidence recording, not an approval/finalization decision
- `ManualBankReconciliationService` does not call `SensitiveActionConfirmationService`; no confirmation enforcement is added

**Deferred (not in Sprint 27 scope)**:

- Automatic reconciliation
- Bank API / public API / external bank integration
- Cash ownership change
- GL posting ownership change
- Role/permission/schema/lifecycle-service change

**Implementation manifest (Sprint 27)**:

| Phase | Commit Subject | Scope |
|---|---|---|
| A | Sprint 27: Define banking operations activation boundary | ADR-068 update + ADR-070 |
| B | Sprint 27: Add banking operations workspace | `BankingOperationsWorkspaceController`, `BankingOperationsWorkspace.tsx`, route, `BankingOperationsWorkspaceTest` |
| C | Sprint 27: Add bank payment execution confirmation | `SensitiveActionConfirmationService` + `SensitiveActionConfirmationController` + `SensitiveActionConfirmationTest` extension |
| D | Sprint 27: Add controlled bank payment execution actions | Bank execute route + controller action + page action + `BankPaymentExecutionWebActionTest` |
| E | Sprint 27: Add bank reconciliation workspace | Reconciliation evidence projection + `BankReconciliationWorkspaceTest` |
| F | Sprint 27: Add bank reconciliation actions | Reconciliation route + controller action + page action + `BankReconciliationWebActionTest` |

Sprint 23 historical record preserved. Unrelated sections not reworded.
