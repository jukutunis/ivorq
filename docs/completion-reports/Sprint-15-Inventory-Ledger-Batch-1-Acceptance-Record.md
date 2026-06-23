# Sprint 15: Inventory Ledger Batch 1 Acceptance Record

## 1. Title and status

Status: BATCH_1_ACCEPTABLE_WITH_DEFERRED_CONCURRENCY_EVIDENCE

Accepted:
Inventory Ledger Batch 1 implementation and automated PostgreSQL evidence.

Deferred:
True independent multi-process PostgreSQL lock-wait validation.

Not authorized:
Operational activation, automatic posting, Cost Ledger execution,
General Ledger, Accounts Payable, GRNI, FinancialPeriod mutation,
and InventoryStock valuation usage.

## 2. Scope accepted

The implemented source files and automated tests proving:
- Business Date state transition logic (Open to Closed).
- Tenant isolation of records.
- Rollback mechanisms on execution failure.
- One-attempt execution logic.
- Expected row-lock intent across transactions via `lockForUpdate`.

## 3. Explicit exclusions

- no automatic InventoryTransaction to Cost Ledger posting;
- no Cost Ledger execution activation;
- no General Ledger posting;
- no Accounts Payable or GRNI behavior;
- no operational controller, command, job, queue, listener, observer,
  scheduler, or route caller;
- no manual two-session harness implementation;
- no production activation.

## 4. Architecture decisions verified

- InventoryTransaction remains the canonical Inventory Ledger source.
- InventoryStock remains a projection and is not a Cost Ledger or provenance source.
- Controlled-entry and idempotency protections are covered by automated PostgreSQL evidence.
- InventoryTransaction corrections remain append-only.
- Business Date transition is Open to Closed only.
- closed_at is server-side.
- closed_by is server-resolved from authenticated actor.
- missing actor fails closed.
- current execution locks PropertyBusinessDate only.
- current execution does not lock FinancialPeriod or InventoryStock.
- future platform lock order remains:
  PropertyBusinessDate → FinancialPeriod → InventoryStock.
- InventoryPostingControlCoordinator remains one-attempt only.
- automatic retries remain prohibited.
- no operational caller exists for the coordinator or Business Date execution services.

## 5. Automated evidence

- E10_SMOKE: PASS
- E11_BATCH1: PASS (Tests: 25, Assertions: 4595, Failed: 0, Errors: 0)
- E13_BUSINESS_DATE_CLOSE_EXECUTION: PASS (Tests: 7, Assertions: 1260, Failed: 0, Errors: 0)

## 6. Deferred concurrency evidence

True independent multi-process PostgreSQL lock-wait proof is deferred.

Reason:
The current PostgreSQL suite uses RefreshDatabase transaction isolation.
That isolation prevents a separate child PHP process from safely observing
parent-created fixtures without bypassing established test lifecycle controls.

This is an evidence-depth limitation, not an identified implementation defect.

A future concurrency harness requires separate architecture approval and
dedicated test-infrastructure design.

No invalid E14 or E15 result is used as acceptance evidence.

## 7. Prohibited operational activation

Operational activation is not authorized.

## 8. Checkpoints

3c06c91 Sprint 15: add Business Date close transition contract
8fbc218 Sprint 15: add one-attempt Inventory posting coordinator
50c06ac Sprint 15: align InventoryStock enum lock assertions
b470e3f Sprint 15: repair Foundation factory model bindings
8af2892 Sprint 15: add Business Date close execution boundary

## 9. Final acceptance decision

The implementation and automated evidence for Batch 1 are officially accepted. True multi-process verification remains deferred.

## 10. Future follow-up item

Separate test-infrastructure design for process-launching and safe cleanup to build an enterprise-safe concurrency harness.
