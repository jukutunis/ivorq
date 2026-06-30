# ADR-047: GRNI Clearing and AP Liability Architecture

**Status:** Accepted
**Date:** 2026-06-30

## Context

IVORQ now has accepted controlled behavior for receiving-derived GRNI accrual and supplier invoice review up to, but not beyond, Finance approval.

Current accepted behavior is:

1. Receiving / Inventory Receipt produces controlled inventory and Cost Ledger evidence.
2. GRNI accrual proceeds through `JournalCandidate`, Finance review, `JournalEntry` draft materialization, finalization authorization, and controlled General Ledger posting.
3. Supplier Invoice registration persists Payables-owned invoice header and line evidence.
4. Three-Way Match persists match outcome and line-level match evidence.
5. Exception review and invoice approval/rejection are invoice-local Payables decisions and stop without AP liability, GRNI clearing, payment, or GL posting.

The next future slice will connect approved supplier invoices with posted outstanding GRNI evidence. That connection is a durable cross-module accounting boundary across Purchasing, Receiving, Payables, Inventory / Cost Control, and General Ledger. It must be governed before runtime implementation begins.

## Decision

### 1. Domain ownership

The ownership boundary is:

- **Purchasing** owns Purchase Order and PO line commercial commitment evidence.
- **Receiving** owns `ReceivingDocument`, `ReceivingLine`, GRN identity, and receipt quantity evidence.
- **Payables** owns Supplier Invoice registration, invoice lines, Three-Way Match outcomes, exception review, and invoice approval / rejection.
- **Inventory / Cost Control** remains the source of truth for inventory movement and valuation evidence.
- **General Ledger** owns `JournalCandidate`, `JournalEntry`, finalization authorization, controlled posting, Financial Period guard, Business Date guard, and GL balance mutation.

AP liability recognition does not transfer ownership of PO, Receiving, Inventory, Cost Ledger, or GRNI source evidence into Payables. Payables may reference and validate source evidence, but it must not rewrite it, backfill it, or become its owner.

### 2. GRNI clearing trigger

A future GRNI clearing / AP liability workflow may begin only when all of the following are true:

- The Supplier Invoice is `APPROVED`.
- The invoice belongs to the active property scope.
- Vendor, PO, receiving evidence, and invoice provenance are valid.
- Relevant GRNI accrual source evidence is posted and traceable.
- The invoice and GRNI evidence can be reconciled using approved rules.
- Unsupported currency, tax, variance, or allocation conditions fail closed.

No automatic AP liability posting is permitted merely because an invoice is registered, matched, exception-resolved, or approved. Approval makes an invoice eligible for controlled future clearing analysis; it does not itself clear GRNI or recognize AP liability.

### 3. GRNI clearing and AP liability boundary

The future accounting intent is:

- **GRNI Clearing** settles eligible posted GRNI liability evidence.
- **AP Liability** recognizes an approved supplier obligation.

The first implementation must create a controlled candidate first. It must not create a direct `JournalEntry`, direct GL posting, direct GRNI clearing entry, or direct AP liability posting.

The implementation must reuse the existing controlled Finance lifecycle:

1. Candidate.
2. Review / approval where required.
3. `JournalEntry` Draft.
4. Finalization authorization.
5. Controlled GL posting.

No parallel Payables-specific posting workflow is authorized.

### 4. Allocation and partial-document rule

Clearing is evidence-based and allocation-aware:

- A Supplier Invoice line may clear only eligible outstanding GRNI evidence.
- No over-clearing is permitted.
- Partial invoice and partial receipt situations must preserve outstanding balances.
- One invoice line may require multiple GRNI source allocations.
- One GRNI source may be cleared across multiple approved invoice lines.
- Allocation identity must be idempotent, traceable, and immutable after posting.
- Duplicate invoice registration or replay must not clear GRNI twice.

This ADR does not prescribe table names, schema, allocation indexes, or exact persistence design. Those belong to a later implementation specification after source-evidence discovery.

### 5. Variance, tax, foreign currency, and credit-note rule

The initial scope is deliberately conservative:

- No implicit price variance posting.
- No implicit quantity variance posting.
- No implicit FX gain or loss posting.
- No implicit tax, withholding, or service-charge posting.
- No implicit credit-note or debit-note treatment.

When an approved invoice contains a condition that cannot be reconciled through a source-proven supported clearing rule, the future clearing slice must fail closed or create controlled exception evidence without posting.

Variance, tax, FX, credit note, debit note, and retrospective correction workflows require explicit future accounting decisions before implementation.

### 6. Provenance, audit, and retry policy

Future runtime evidence must preserve the chain:

`Supplier Invoice line -> PO line -> Receiving evidence -> posted GRNI source -> clearing allocation -> AP liability candidate`.

The future implementation must enforce:

- Property-scoped and vendor-scoped validation.
- Real active database-backed actor evidence.
- Immutable reviewer, approver, materializer, authorizer, and poster evidence.
- No fabricated legacy provenance.
- No backfill or mutation of existing posted GRNI evidence.
- Zero automatic retries.
- One controlled request attempt.
- A retry is a new request using stable source identity.
- Idempotent replay returns existing durable evidence only when semantically identical.
- Conflicting replay fails controlled.

### 7. Financial Period and Business Date rule

Supplier Invoice registration, Three-Way Match, exception review, and invoice approval do not change Financial Period or Business Date.

Candidate creation does not close a period or business date.

Actual `JournalEntry` posting continues to require the existing Financial Period and Business Date controls through the accepted General Ledger posting path. This ADR does not invent a new Financial Period or Business Date lifecycle.

## Non-Goals

This ADR does not authorize:

- Payment proposal.
- Payment approval.
- Cash disbursement.
- General Cashier.
- Bank reconciliation.
- Payment allocation.
- AP aging implementation.
- Supplier statement reconciliation.
- AP close.
- Month-end close.
- Generic approval engine.
- Generic workflow framework.
- Direct AP posting.
- Direct GRNI clearing posting.
- Changes to existing receipt GRNI posting.
- Changes to existing Inventory or Cost Ledger valuation.
- Source code, migration, test, route, UI, controller, API, service, model, or posting implementation.

## Future Implementation Sequence

The future implementation sequence is:

1. GRNI / AP clearing source-evidence discovery and allocation contract.
2. Approved Supplier Invoice to controlled GRNI Clearing / AP Liability Candidate.
3. Candidate review / authorization only where source-proven required.
4. Existing `JournalEntry` Draft, authorization, and posting lifecycle reuse.
5. Separate future decisions for variance, tax, FX, credit notes, payment, and reconciliation.

This sequence is intentionally high level. It is not a migration design, table design, test script, service contract, or implementation plan.

## Consequences

### Positive

- Preserves cross-module source ownership while enabling future AP liability recognition.
- Prevents Supplier Invoice approval from becoming an implicit posting trigger.
- Reuses the accepted General Ledger candidate-to-posting lifecycle instead of creating a Payables-specific posting path.
- Keeps unsupported accounting conditions fail-closed until explicit future decisions exist.
- Protects posted GRNI and valuation evidence from retrospective mutation.

### Limitations

- No GRNI clearing or AP liability runtime capability exists after this ADR.
- Approved invoices remain stopped at invoice approval until a later implementation slice is authorized.
- Allocation, variance, tax, FX, credit-note, debit-note, and correction details remain future decisions.
- Future work must prove source evidence, idempotency, locking, actor authority, and posting controls before runtime activation.

## Related ADRs

- ADR-001: Multi-Tenant Hierarchy Architecture.
- ADR-002: Audit Trail Strategy.
- ADR-003: Approval Engine Architecture.
- ADR-004: Finance Module Boundary Architecture.
- ADR-011: Goods Received Not Invoiced (GRNI) Architecture.
- ADR-034: Night Audit and Hospitality Business Date Architecture.
- ADR-044: Inventory Reversal v1 Contract.
- ADR-046: Inventory Reversal v1 Eligible Original Transaction Matrix.

---

**Implementation status:** Policy accepted; GRNI clearing, AP liability creation, and runtime posting are not authorized by this ADR.
