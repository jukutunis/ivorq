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
| D1 | Payables | Approve Supplier Invoice | `SupplierInvoiceApprovalService::approve()` | No | No controller |
| D2 | Payables | Reject Supplier Invoice | `SupplierInvoiceApprovalService::reject()` | No | No controller |
| D3 | Payables | Resolve Match Exception | `SupplierInvoiceExceptionReviewService::resolveException()` | No | No controller |
| D4 | Payables | Approve Payment Proposal | `PaymentProposalApprovalService` | No | No controller |
| D5 | Payables | Reject Payment Proposal | `PaymentProposalApprovalService` | No | No controller |
| D6 | GeneralCashier | Record Cash Execution | `PaymentExecutionService::recordCashExecution()` | No | No controller |
| D7 | GeneralCashier | Record Confirmed Bank Execution | `PaymentExecutionService::recordConfirmedBankExecution()` | No | No controller |
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
- Server-projected capability gating

**Not delivered**: Payment proposal approval/rejection (D4, D5 — deferred).

## Supplier Invoice / Three-Way Match / Exception workspace boundary

**Deliverable**: Evidence-first read-only operational view showing:
- Supplier invoices with match status, match details, GRNI/AP evidence
- Three-way match results (matched vs. exception)
- Exception queue with variance details

**Not delivered**: Invoice approve/reject (D1, D2 — deferred), exception resolution (D3 — deferred), invoice creation (no source-proven route).

## Cash payment execution workspace boundary

**Deliverable**: Read-only projection of existing Cash execution evidence and Cashbook transactions.

**Not delivered**: Cash execution recording (D6 — deferred), cash count (D8 — deferred), cash reconciliation (D9 — deferred).

## Bank payment execution workspace boundary

**Deliverable**: Read-only projection of existing Bank execution evidence and bank statement lines.

**Not delivered**: Bank execution recording (D7 — deferred), bank account registration (D10 — deferred), bank reconciliation (D12 — deferred).

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

Only actions D1-D5 (invoice approval/rejection, proposal approval/rejection) would require `finance-approval` confirmation IF web routes existed. Since they are deferred, no new confirmation enforcement is added in this package. Existing confirmation enforcement for GRNI approve/reject/finalize and FX review/finalize remains unchanged.

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
|---|---|---|---|
| Invoice approval | Yes | No | No controller |
| Invoice rejection | Yes | No | No controller |
| Exception resolution | Yes | No | No controller |
| Proposal approval | Yes | No | No controller |
| Cash execution | Yes | No | No controller |
| Bank execution | Yes | No | No controller |
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
2. **No new lifecycle**: All mutation actions call existing services only. No new state machine is introduced.
3. **Deferred web actions**: 12 source-proven services remain without web exposure. Future packages may add controllers for these.
4. **No confirmation expansion**: Since no new approve/reject/finalize web actions are added, no new `finance-approval` confirmation enforcement is required.

## Deferred decisions

| Decision | Status |
|---|---|
| Invoice approval/rejection web routes | Deferred — service exists, no controller |
| Payment proposal approval/rejection web routes | Deferred — service exists, no controller |
| Cash execution web route | Deferred — service exists, no controller |
| Bank execution web route | Deferred — service exists, no controller |
| Cash/bank reconciliation web routes | Deferred — services exist, no controllers |
| Payment scheduling | Deferred |
| Bulk payment execution | Deferred |
| External bank integration | Deferred |
| Bank statement import UI | Deferred — API exists |
| Supplier portal | Deferred |
| Payment batch file generation | Deferred |
| Invoice OCR | Deferred |
| Automated reconciliation | Deferred |
| Mobile approval | Deferred |
