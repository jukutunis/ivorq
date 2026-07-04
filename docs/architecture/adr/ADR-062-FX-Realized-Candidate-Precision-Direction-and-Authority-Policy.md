# ADR-062: FX Realized Candidate Precision, Direction, and Authority Policy

**Status:** Accepted (Accepted for architecture boundary only)
**Date:** 2026-07-05

## Context

ADR-061 established the candidate boundary for realized foreign exchange (FX) adjustments, restricting candidate scope to posted supplier payment allocations. However, it deferred specific runtime decisions such as precision, arithmetic conventions, zero-difference outcomes, account direction, and the authorization model.

Before reattempting any realized FX candidate implementation, this ADR establishes the strict monetary policy, comparison rules, account mappings, double-entry direction, and the dedicated authority model governing realized FX candidates.

## Decision

### 1. Decimal Precision and Comparison Contract

All currency calculations for general ledger adjustments must run entirely on the server using database-persisted values. The system must adhere to the following arithmetic contract:
- **Canonical Scale**: Calculations must use a scale of exactly `2` decimal places, matching the schema definition of the posted general ledger lines (`decimal(15,2)` in `gl_journal_entry_lines`).
- **Precision Rules**: Future candidate logic must handle amounts exclusively as canonical decimal strings.
- **Float Prohibition**: The use of PHP floating-point numbers (`float`), float casts `(float)`, `floatval()`, or scientific notation is strictly prohibited.
- **Decimal Comparison**: All comparisons and subtractions must be executed using PHP `bcmath` functions (specifically `bcsub`, `bcadd`, and `bccomp`) configured with a scale parameter of exactly `2`.
- **Fail Closed**: If source ledger values cannot be parsed as valid decimal strings or fail scale constraints, candidate generation must fail closed.

### 2. Rounding Prohibition

Silent rounding of amounts or rates during candidate generation is prohibited:
- The system must not use `round()`, `number_format()`, or float-based division.
- If fractional base-currency cents occur during allocation comparison, the candidate engine must reject the transaction and fail closed.

### 3. Zero-Difference Outcome

When the carrying basis of the AP invoice equals the settlement basis of the payment at the canonical scale:
- No `JournalCandidate` shall be created.
- The generation service must return a controlled `ZERO_REALIZED_FX_DIFFERENCE` result code.
- A zero difference is treated as a successful operational bypass, not as a system error, and must not trigger candidate replay or balance adjustment.

### 4. Gain and Loss Direction Contract

Realized FX adjustments must align with established ledger conventions:
- **Carrying Basis (Invoice date)**: Recorded as a `CREDIT` to the `AP_CONTROL` account.
- **Settlement Basis (Payment execution)**: Recorded as a `DEBIT` to the `AP_CONTROL` account and a `CREDIT` to `CASH_AND_BANK`.
- **Realized Gain (Carrying Basis > Settlement Basis)**:
  - Debit `AP_CONTROL` (to clear the remaining credit liability).
  - Credit `FX_GAIN` (Revenue).
- **Realized Loss (Settlement Basis > Carrying Basis)**:
  - Debit `FX_LOSS` (Expense).
  - Credit `AP_CONTROL` (to offset the excess debit liability).
- **Direction Sanity Check**: If source posted journal lines do not match these expected debit/credit configurations, the candidate service must fail closed.

### 5. Dedicated Authority Model

A new, dedicated authorization permission is established:
```
finance.fx-adjustment-candidate.create
```
- This permission is required to create a realized FX `JournalCandidate`.
- **Scope Restriction**: This permission strictly authorizes candidate generation. It does not grant authority for reviewing candidates, materializing drafts, posting journal entries, approving exchange rates, executing payments, or performing cashier/banking tasks.
- **No Permission Reuse**: The system must not reuse the payment allocation permission (`finance.payables.ap-settlement.allocate`) or any other cashier/GL posting permission for candidate creation.

### 6. Fallback-Account Prohibition

There are no fallback or dummy GL account assignments:
- If `FX_GAIN` or `FX_LOSS` mappings are missing, inactive, cross-property, expired, or ambiguous at the payment date, candidate creation must fail closed.
- The system must not write to fallback, default, or caller-provided accounts.

### 7. Full-Settlement Boundary

Candidate generation remains strictly restricted to one-to-one, fully settled allocations where the allocation amount matches the invoice grand total. Any partial or split settlement scenarios are excluded.

### 8. Source Ownership and Immutability

The General Ledger module owns candidate generation.
- All calculations must rely strictly on property-base-currency values retrieved from the posted GL journal lines.
- Caller-supplied exchange rates, carrying amounts, debit/credit assignments, GL accounts, or snapshots must not be accepted.
- Candidates are saved with status `PENDING_REVIEW` and cannot mutate posted ledger balances.

### 9. Idempotency and Non-Mutation

Realized FX candidate creation must be strictly idempotent and govern identical and conflicting replays as follows:

- **Canonical Source Tuple**: The candidate is bound to a canonical source tuple defined as:
  `property_id` + `ap_settlement_allocation_id`
  This tuple is entirely source-owned and server-resolved; no caller may supply, replace, or reinterpret it.

- **Identical Replay**: If candidate generation is requested for a canonical source tuple that already has a candidate, and all candidate-relevant evidence is identical to the existing initial candidate evidence, the system must return the existing candidate reference. This applies only when all of the following are identical:
  - Canonical source tuple.
  - Source invoice identity.
  - Source payment execution identity.
  - AP journal identity.
  - Payment journal identity.
  - Approved exchange-rate evidence identity.
  - FX gain/loss mapping identities.
  - Canonical source snapshots.
  - Derived direction.
  - Derived decimal amount.
  For an identical replay, the service must:
  - Return the existing candidate reference.
  - Create no second candidate.
  - Mutate no existing candidate.
  - Create no new `JournalCandidateLine` records.
  - Create no new financial evidence.
  - Perform no automatic retry.

- **Conflicting Replay**: If the same canonical source tuple is requested but any candidate-relevant source evidence differs (e.g., rate change, amount change, or different mappings), the transaction must:
  - Fail controlled.
  - Preserve the existing candidate unchanged.
  - Create no replacement candidate.
  - Create no duplicate candidate.
  - Create no new journal lines.
  - Require manual investigation or an explicitly approved future correction workflow.

- **Advanced Candidate State (Fail Closed)**: A candidate is not replayable once it has entered any lifecycle state or outcome beyond its original initial candidate-creation state. This includes:
  - Any review decision.
  - Any rejection decision.
  - Any draft materialization.
  - Any finalization activity.
  - Any posting-related activity or reversal/correction relationship.
  - Any other downstream lifecycle evidence.
  For an advanced candidate, the service must:
  - Fail closed.
  - Do not return the existing candidate as an idempotent replay.
  - Do not overwrite the existing candidate.
  - Do not recreate the existing candidate.
  - Do not bypass the applicable future correction or reversal workflow.

- **Implementation Constraint**: Exact status names and transition checks must be source-proven by the future implementation. No future candidate service may invent status semantics, or use a generic "existing candidate" shortcut. Candidate idempotency does not authorize modification, replacement, retry, review, draft, finalization, or posting.
- **No Direct Posting**: No direct posting is allowed; all candidates must undergo human review before transitioning to drafts.

## Consequences

- Prevents rounding anomalies by enforcing strict `bcmath` checks.
- Strengthens security by partitioning candidate creation behind a dedicated permission rather than reusing cashier allocation permissions.
- Prevents database level balancing issues by prohibiting dummy/fallback accounts.

## Explicit Exclusions & Deferred Decisions

The following areas are explicitly excluded and deferred:
- Allocation basis rules for partial, split, or multi-allocation realized FX.
- Unrealized FX remeasurement and period-end revaluation.
- Reversal/void accounting flow.
- Multi-currency triangulation where the property base currency is not part of the pair.
- Statutory tax, withholding, or discount interactions.
- Historical corrections and backfills.
- Authorization rules for candidate review, draft materialization, and posting.
