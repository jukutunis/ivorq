# ADR-069 — Controlled Cash Payment Execution Activation

**Status:** Accepted for controlled implementation
**Date:** 2026-07-06

## Context

Sprint 25.2 delivered Cash Execution Context Projection (read-only) and marked Cash Payment Execution (A3) as DEFERRED due to three missing source boundaries: no execution workspace/controller, no confirmation convention for operational execution, and browser-supplied session/instrument selection. ADR-068 deferred Cash Payment Execution pending a dedicated confirmation intent and a source-proven web action contract.

This ADR defines the controlled activation boundary for Cash Payment Execution as a web mutation action, using the existing `cash-payment-execution` sensitive confirmation intent.

## Source-proof activation inventory

### 1. Existing Cash execution lifecycle service

**Service**: `PaymentExecutionService::recordCashExecution(paymentProposalItemId, cashierSessionId, cashierPaymentInstrumentId, actor)`
**Path**: `Modules/Operations/GeneralCashier/Services/PaymentExecutionService.php:28-123`
**Return type**: `PaymentExecution`
**Domain owner**: General Cashier (`Modules/Operations/GeneralCashier`)
**Status**: Existing and unchanged. No modification to this service.

### 2. Existing Cash execution permission

**Permission**: `finance.general-cashier.payment.execute` (`PaymentExecutionService.php:22`)

### 3. Source-proven execution target identifiers

The browser may submit only three identifiers:

| Field | Type | Source |
|---|---|---|
| `payment_proposal_item_id` | ULID, 26 chars | Identifies the `PaymentProposalItem` to execute |
| `cashier_session_id` | ULID, 26 chars | Identifies the actor's OPEN `CashierSession` |
| `cashier_payment_instrument_id` | ULID, 26 chars | Identifies the active CASH `CashierPaymentInstrument` |

### 4. Server-side revalidation chain

The existing service performs full independent re-resolution and validation:

1. **Actor**: `resolveAuthorizedActor()` — must be active, must hold `finance.general-cashier.payment.execute`
2. **Payment Proposal Item**: `lockForUpdate()`, `assertApprovedProposalItem()` — must be active, proposal must be APPROVED, vendor and currency must match proposal scope
3. **Source Journal**: `lockForUpdate()`, `assertPostedApLiabilitySource()` — must be Posted, Payables/SupplierInvoice source, posting event `SupplierInvoiceGrniClearingApLiability`
4. **Cash Session**: `GeneralCashierOperationalFoundationService::resolveOperationalContext()` — must be OPEN, `cashier_user_id` must match actor, same property
5. **Cash Instrument**: Same resolution chain — must be active, type=CASH, same property as session
6. **Operational GL Account**: Must be active, same property, linked to instrument
7. **Idempotency**: `existingExecutionQuery()` catches duplicate items; `assertExistingExecutionMatches()` returns existing on identical replay, throws on conflict
8. **Segregation**: Session ownership enforced — `cashier_user_id` must equal actor (`resolveOperationalContext()` line 103-104)

### 5. Amount and currency derivation

Both amount and currency are derived server-side from the `PaymentProposalItem`:
- `source_amount = $this->paymentAmount($item)` where `paymentAmount()` returns `$item->requested_payment_amount ?? $item->source_amount` (`PaymentExecutionService.php:486-489`)
- `currency_code = $item->currency_code`

No browser-supplied amount, currency, or rate is accepted.

### 6. Property/company/team scope

- `assertActorCanAccessProperty()` verifies actor's active property membership on the proposal item's property
- `resolveOperationalContext()` enforces session and instrument belong to the same property
- Service internally verifies session property matches instrument property
- Operational account is verified against the session's property

### 7. Audit and traceability

- `PaymentExecution` model records `created_by`, `updated_by`, `executed_by`, `executed_at`
- Full `source_snapshot` JSON captures all source evidence (proposal, item, journal, session, instrument, account, amounts)

### 8. Downstream Cashbook evidence

`CashbookTransactionProjectionService` and `CashbookTransaction` model provide downstream Cashbook evidence creation from Payment Executions. This behavior is existing and unchanged.

### 9. Existing Cash Execution Context Projection

`CashbookEvidenceWorkspaceController::projectCashExecutionContext()` (line 77-142) already projects:
- Eligible payment proposal items (approved, active, not yet executed)
- Open cashier sessions for the current actor
- Active CASH payment instruments in the current property

This projection is read-only and property-isolated.

### 10. Existing controller extension point

`CashbookEvidenceWorkspaceController` (`Modules/Finance/GeneralCashier/Http/Controllers/CashbookEvidenceWorkspaceController.php`) is the source-proven extension point. It already hosts:
- `index()` — read-only Cashbook evidence + Cash/Bank execution context projection
- `reconcile()` — Cash reconciliation mutation action (Sprint 25.2)
- `projectCashExecutionContext()` — Cash execution context
- `projectBankExecutionContext()` — Bank execution context

### 11. Existing Sensitive Action Confirmation foundation

`SensitiveActionConfirmationService::REGISTERED_INTENTS` currently contains four intents (`SensitiveActionConfirmationService.php:15-20`):
- `finance-role-assignment`
- `finance-approval`
- `fx-break-glass`
- `administrative-sensitive-action`

The service uses server-owned 15-minute TTL, actor/company/property/session binding, encrypted password verification, audit logging on confirm/invalidate, fail-closed behavior on missing/expired/mismatched confirmation.

The `SensitiveActionConfirmationController` uses `REGISTERED_INTENTS` for intent validation in `index()`, `store()`, and `destroy()` methods via `in_array()` checks and `implode()` join. Adding a fifth string to the array automatically extends validation in all three methods.

The `intentLabel()` method and `postConfirmationRoute()` method use hardcoded maps that do NOT include the new intent — the new intent will fall through to defaults (raw intent string as label, null post-route → redirect to confirmation index). No existing intent behavior changes.

## Cash Payment Execution Confirmation Policy

### Intent: `cash-payment-execution`

This is a dedicated operational execution confirmation intent. It is distinct from:

- `finance-approval` — scope is approval/rejection/finalization decisions (ADR-067)
- `finance-role-assignment` — scope is FX operational role assignment
- `fx-break-glass` — scope is FX break-glass activation
- `administrative-sensitive-action` — scope is broad administrative actions

### Confirmation enforcement order

In the execution controller action:

1. Authentication and active-property context (middleware)
2. Action permission authorization (`finance.general-cashier.payment.execute`)
3. Property-scoped target resolution (proposal item, session, instrument)
4. **`cash-payment-execution` confirmation check** — `hasValidConfirmation($actor, 'cash-payment-execution', $companyId, $propertyId)`
5. Cash execution service invocation (`recordCashExecution()`)
6. Controlled redirect/domain feedback

### Fail-closed behavior

Missing, expired, wrong-intent, wrong-actor, wrong-property, or wrong-company confirmation must:
- Invoke no `PaymentExecutionService::recordCashExecution()`
- Create no `PaymentExecution` record
- Create no `CashbookTransaction` record
- Create no Cash session or instrument mutation
- Create no journal, allocation, Financial Period, or Business Date mutation
- Create no Cash execution audit evidence
- Return controlled redirect to the confirmation page

### Non-goals

Cash payment execution confirmation:
- Does NOT automatically continue execution after confirmation
- Does NOT accept browser return URL, Referer, continuation target, route name, amount, account, session, instrument, or authority
- Does NOT grant any role, permission, Cash session, Cash instrument, payment, journal, Cashbook, Banking, GL, or allocation authority
- Does NOT change the semantics of existing confirmation intents
- Does NOT alter FX break-glass behavior

### Browser input contract

Browser may submit only:
- `payment_proposal_item_id` (ULID, required)
- `cashier_session_id` (ULID, required)
- `cashier_payment_instrument_id` (ULID, required)

Browser must NEVER supply: amount, currency, rate, property, company, team, actor, supplier, invoice, proposal status, payment status, cash session state, cash instrument state, cash balance, cash account, GL account, allocation, journal, mapping, audit payload, execution authority, confirmation authority.

## React capability and context rules

1. React may display server-projected Cash execution capability only
2. React may render only source-proven server-projected eligible context from `projectCashExecutionContext()`
3. React must not calculate amount, currency, balance, eligibility, account mapping, Cash session state, Cash instrument state, or execution authority
4. React must not expose execution button when server capability is false
5. React performs no financial, rate, or eligibility calculation

## Explicit non-goals (unchanged)

- Bank Payment Execution remains deferred
- Bank Reconciliation remains deferred
- Partial/split/multi-payment are deferred
- Payment void/reversal/refund are deferred
- FX/tax/withholding/discount are deferred
- Controlled GL posting is deferred
- Public API is deferred
- Browser financial calculation is prohibited

## Ownership preservation

| Domain | Owns | Unchanged |
|---|---|---|
| Payables | Payment Proposal, Payment Proposal Item, AP settlement | Yes |
| General Cashier | Cash session, Cash instrument, Payment Execution, Cashbook, Cash reconciliation | Yes |
| General Ledger | Journals, Financial Period, Business Date, controlled posting | Yes |
| Banking | Bank account, Bank statement, Bank reconciliation | Yes |

## Phase C allowed repository paths

- `docs/architecture/adr/ADR-068-Supplier-Payment-and-Settlement-Operational-Workspaces.md`
- `routes/web.php`
- `Modules/Finance/GeneralCashier/Http/Controllers/CashbookEvidenceWorkspaceController.php`
- `resources/js/Pages/Ivorq/Finance/CashbookEvidenceWorkspace.tsx`
- `tests/Postgres/Finance/GeneralCashier/CashPaymentExecutionWebActionTest.php`
- `tests/Postgres/Finance/GeneralCashier/CashExecutionContextProjectionTest.php`

## Consequences

1. **Controlled Cash execution activation**: Cash execution is exposed as a web mutation action for the first time, protected by dedicated `cash-payment-execution` confirmation
2. **No service modification**: `PaymentExecutionService::recordCashExecution()` remains unchanged
3. **No permission change**: Existing `finance.general-cashier.payment.execute` permission is sufficient
4. **No ownership movement**: General Cashier retains Cash execution ownership
5. **Backward-compatible confirmation extension**: Adding `cash-payment-execution` to `REGISTERED_INTENTS` is a data-only change — a fifth string in an array
6. **Confirmation does not execute**: The confirmation only proves the human intends to proceed; the service invocation follows confirmation
7. **Bank execution remains deferred**: A3 activation does not activate A5
