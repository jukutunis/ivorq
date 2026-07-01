# ADR-058: Partial and Sequential Supplier Payment Migration Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ supplier payment runtime currently treats a PaymentProposalItem and PaymentExecution as full-obligation evidence for one posted AP liability source. The accepted AP settlement allocation foundation now derives outstanding amount only from posted allocation evidence, but partial and sequential split payment remains blocked by historical full-obligation identity rules, active source selection, and durable no-double-payment assumptions.

Current behavior must be migrated without rewriting historical PaymentProposalItem records, backfilling fabricated allocations, weakening existing full-obligation evidence, mutating posted payment JournalEntries, mutating posted AP liability JournalEntries, changing historical PaymentExecution identity, or treating PaymentProposal selection as settlement.

This ADR is policy only. It does not implement models, migrations, services, tests, routes, controllers, UI, APIs, payment execution changes, payment proposal changes, allocation runtime changes, posting changes, AP mutation, cash mutation, bank mutation, Financial Period transition, or Business Date transition.

## Decision

### 1. Migration purpose

Partial and sequential supplier payment must be introduced through a controlled migration that preserves existing full-obligation evidence as historical truth.

Historical PaymentProposalItem source amounts remain immutable source obligation evidence. Historical PaymentExecution identity remains durable and must not be reinterpreted as a partial-payment identity after the fact. Existing posted supplier payment JournalEntries and posted AP liability JournalEntries remain immutable.

PaymentProposal selection is a payment intent workflow, not settlement. AP settlement occurs only through append-only AP Settlement Allocation evidence after the supplier payment JournalEntry is posted.

### 2. Amount model

Future partial-payment runtime must distinguish these amounts:

- Immutable original source obligation amount.
- Server-resolved requested payment amount.
- Posted payment amount.
- Posted allocation amount.
- Derived outstanding amount.

The source obligation amount remains immutable. Requested payment amount is not caller-trusted and must be server-validated against outstanding amount derived from posted allocations. Posted payment amount derives from the controlled payment lifecycle. Posted allocation amount derives only after the payment JournalEntry is posted. Outstanding amount derives only from posted allocations.

No mutable AP balance field may be introduced for this purpose. Outstanding must not be inferred from PaymentProposal, PaymentProposalItem, PaymentExecution, cashbook evidence, bank statement evidence, or an unposted intent.

### 3. Sequential-only initial scope

The first partial-payment scope is sequential, not parallel.

For one posted AP liability, the supported future runtime is:

- One active payment intent at a time.
- One property.
- One vendor.
- One currency.
- One source AP liability.
- One requested amount.
- One PaymentExecution.
- One controlled posting chain.
- One append-only allocation after posting.
- A next sequential payment only after remaining outstanding is derived from posted allocations.

The initial scope excludes generic batches, parallel reservations, simultaneous split proposals for the same AP liability, cross-vendor payment, cross-property payment, and cross-currency payment.

### 4. No-double-payment and concurrency migration

Historical full-obligation uniqueness remains intact.

Future PaymentExecution uniqueness must be tied to the specific payment instruction or proposal item identity, not only to the original full source obligation. The migration must introduce a durable partial-payment intent identity before execution so that historical full-obligation records and future sequential partial records can coexist without ambiguity.

One active payment intent per source AP liability prevents concurrent partial payment selection. Allocation creation must lock and validate the source AP liability's posted allocations before accepting an amount. The allocation service must reject over-allocation.

Identical semantic replay may return existing durable evidence only when every source identity and requested amount match. Conflicting replay fails controlled.

### 5. Allocation and reversal interaction

Initial partial/split runtime excludes reversal-linked payment evidence until an explicit allocation-reversal treatment is implemented.

A reversed supplier payment may not create or retain a usable allocation in the first partial-payment scope. Reversal handling must not rewrite allocation evidence, reopen AP liability evidence, perform partial reversal, or automatically correct allocation state.

Future allocation reversal behavior requires a later explicit architecture decision and implementation package.

### 6. Runtime sequence

Future partial and sequential supplier payment runtime must follow this sequence:

```text
Posted AP Liability
-> one active partial payment intent
-> PaymentExecution
-> JournalCandidate
-> review
-> JournalEntry Draft
-> finalization authorization
-> controlled posting
-> append-only AP Settlement Allocation
-> derived remaining outstanding
-> next sequential payment only when eligible
```

No payment allocation may occur before posting. No payment intent may be treated as settled AP.

### 7. Explicit non-goals

This ADR does not authorize:

- Parallel split batches.
- Cross-vendor payment.
- Cross-property payment.
- Cross-currency payment.
- Payment allocation before posting.
- Generic reservation engine.
- Generic allocation engine.
- Partial reversal.
- Allocation rewrite.
- FX.
- Tax.
- Withholding.
- Discount.
- Credit note.
- Debit note.
- Advance.
- Prepayment.
- Direct AP mutation.
- Direct cash mutation.
- Direct bank mutation.
- Direct GL posting.
- Financial Period transition.
- Business Date transition.

## Consequences

### Positive

- Preserves historical full-obligation supplier payment evidence.
- Creates a controlled path for future sequential partial payments.
- Keeps AP outstanding as a read-only derivation from posted allocation evidence.
- Prevents concurrent payment selection from becoming a double-payment source.
- Keeps allocation after controlled payment posting.

### Limitations

- Runtime partial payment remains blocked until a specific payment intent identity migration is implemented.
- Parallel split payment remains out of scope.
- Reversal-linked allocation treatment remains blocked pending a later decision.
- FX, tax, withholding, and discount remain governed by separate decisions.

## Related ADRs

- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.
- ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture.
- ADR-053: Supplier Payment Void, Reversal, and Correction Architecture.
- ADR-054: AP Settlement Allocation, Partial and Split Payment Architecture.
- ADR-055: Payment Currency, FX, Tax, Withholding, and Discount Architecture.
- ADR-057: Cash and Bank Payment Return Evidence Architecture.

---

**Implementation status:** Policy accepted; partial and sequential supplier payment runtime remains blocked until a controlled payment intent identity migration is implemented.
