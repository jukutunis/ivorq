# ADR-056: Banking Legacy Isolation and Manual Evidence Coexistence Architecture

**Status:** Accepted
**Date:** 2026-07-01

## Context

IVORQ has accepted ADR-052 for Banking source-of-truth, BANK payment, and bank reconciliation architecture. Targeted source discovery also shows existing legacy Banking structures that include balance-bearing BankAccount fields, statement import flows, imported statement lines, reconciliation sessions, and auto-match recommendation logic.

Those legacy structures predate the controlled BANK payment and reconciliation path. They cannot safely be treated as authoritative external source evidence for the ADR-052 path without an explicit migration decision.

This ADR is policy only. It does not implement models, migrations, services, tests, routes, controllers, UI, bank payment execution, bank reconciliation, import, auto-match, legacy migration, GL posting, or balance mutation.

## Decision

### 1. Legacy Banking isolation

Existing legacy Banking balance-bearing, import, and auto-match structures are not authoritative source evidence for the ADR-052 controlled BANK payment or bank reconciliation path unless a later ADR explicitly migrates them.

Runtime code for the new controlled path must not:

- Read legacy Banking balances as source authority.
- Treat imported legacy statement lines as controlled external evidence.
- Backfill new evidence from legacy Banking records.
- Auto-link new evidence to legacy Banking records.
- Dual-write to legacy and new evidence records.
- Use legacy auto-match results as reconciliation evidence.

### 2. Coexistence boundary

New Banking source records must coexist with legacy Banking without modifying, reinterpreting, importing, balancing, migrating, or auto-linking legacy records.

The new foundation must use separate narrow identity and evidence records. These records are owned by Banking and are limited to operational bank-account identity and manually registered external statement-line evidence.

### 3. Controlled BankAccount identity

A Banking-owned operational BankAccount maps to one active property-scoped GL bank control account.

The BankAccount mapping:

- Is not a balance ledger.
- Does not mutate the mapped GL account.
- Does not hold current balance, reconciled balance, or opening balance truth.
- Does not create bank transactions.
- Does not create JournalEntries.
- Does not imply payment confirmation by itself.

Only source-proven active property and account compatibility can make the mapping eligible for future BANK payment confirmation.

### 4. Manual external statement-line evidence

A manual immutable external statement-line record must preserve:

- Property.
- BankAccount.
- External reference.
- Source or evidence reference.
- Statement date.
- Direction.
- Exact amount.
- Currency.
- Recorder actor.
- Recorded timestamp.
- Durable technical identity.

Optional vendor reference may be recorded only when it is externally evidenced.

PaymentExecution, JournalEntry, PaymentProposal, PaymentProposalItem, or General Cashier BANK instrument may never fabricate or become the origin of external statement evidence.

### 5. Initial exclusions

The new coexistence foundation excludes:

- Legacy Banking migration.
- Legacy Banking reconciliation.
- Bank balance calculation.
- Bank statement import.
- Automatic matching.
- Automatic reconciliation.
- Bank payment execution.
- Bank payment posting.
- Direct bank mutation.
- Direct General Ledger posting.
- Dual-write.
- Source backfill.
- UI, API, routes, and controllers.

### 6. Future transition

Any future transition from coexistence to migration requires another explicit ADR.

That ADR must define source authority, migration eligibility, immutable provenance, duplicate handling, legacy balance treatment, reconciliation treatment, rollback policy, audit evidence, and operational cutover boundaries before runtime migration work begins.

## Consequences

### Positive

- Allows ADR-052-compliant source evidence to proceed without relying on unsafe legacy Banking records.
- Prevents balance-bearing or imported legacy records from becoming new controlled source authority by accident.
- Preserves a clear Banking ownership boundary for future BANK payment confirmation and bank reconciliation.
- Keeps manual external evidence narrow, immutable, and audit-ready.

### Limitations

- Existing legacy Banking records remain outside the new controlled source path.
- BANK payment confirmation cannot consume legacy Banking evidence until a later migration ADR explicitly authorizes it.
- No import, auto-match, reconciliation, balance, migration, or payment runtime capability is authorized by this ADR.

## Related ADRs

- ADR-004: Finance Module Boundary Architecture.
- ADR-019: Payment and Bank Reconciliation Engine.
- ADR-048: Payment Proposal, General Cashier, and AP Settlement Architecture.
- ADR-049: Payment Approval, General Cashier Posting, and Reconciliation Architecture.
- ADR-052: Banking Source-of-Truth, Bank Payment, and Bank Reconciliation Architecture.
- ADR-055: Payment Currency, FX, Tax, Withholding, and Discount Architecture.

---

**Implementation status:** Policy accepted; a separate controlled Banking source foundation may coexist with legacy Banking, but legacy migration, import, auto-match, reconciliation, bank payment execution, and bank posting remain unauthorized.
