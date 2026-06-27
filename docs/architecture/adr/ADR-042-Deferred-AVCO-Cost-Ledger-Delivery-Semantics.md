# ADR-042: Deferred AVCO Cost Ledger Delivery Semantics

## Status
Proposed — Owner Approval Required

## Date
2026-06-27

## Context
IVORQ uses a Transactional Outbox pattern (as approved in ADR-041) to decouple the synchronous Inventory posting module from derived ledgers such as the Cost Ledger.
During an Inventory transaction, an `InventoryTransaction` is written and a corresponding pending `OutboxMessage` is persisted in the same transaction.
The outbox payload is permanently restricted to `{"transactionId":"<ULID>"}`.
The future `CostControl Outbox Consumer` must consume these outbox messages, execute the Average Cost (AVCO) valuation planner, append the resulting entry to the Cost Ledger, and mark the outbox message as delivered.

Because AVCO calculations are cumulative and sequence-sensitive, delayed or concurrent delivery can lead to out-of-order valuation, resulting in incorrect weighted average costs. Additionally, accounting boundaries (closed Business Dates and closed Financial Periods) must be strictly enforced.

## Problem Statement
How do we ensure that the deferred, asynchronous delivery of outbox messages to the Cost Ledger is semantically correct, preserves chronological AVCO ordering, prevents concurrent race conditions, handles closed accounting windows safely, and maintains absolute idempotency without introducing complex queue orchestration or violating module boundaries?

## Decision
We propose establishing a strict prerequisite accounting architecture for the Cost Ledger outbox consumer. This decision governs:
1. The exact valuation scope.
2. A deterministic sequence allocated at posting time.
3. Persistent valuation evidence.
4. Scoped state records and row-level serialization.
5. Historical posting window enforcement.
6. Structured consumer outcome mappings.
7. Structured idempotency checks.

No implementation of the outbox consumer, state models, or queue/scheduler workers is authorized by this ADR.

## Decision Details

### 1. Valuation Scope
* **Conceptual Scope**: The conceptual AVCO valuation scope is defined as:
  ```text
  property identity + immutable inventory location/store identity + item identity
  ```
* **Scope Integrity**: Every component of the scope must be immutable and durably stored.
* **Prerequisite Path**: The exact existing domain term and physical persistence path for the location/store component remain an implementation prerequisite and must be verified before any schema or consumer implementation begins. No specific database column is assumed to exist.
* **No Inference**: The consumer must never infer the valuation scope from current mutable stock balances or dynamic configurations at delivery time.

### 2. Valuation Sequence
To ensure chronologically correct AVCO calculations, a deterministic sequence must be introduced:
* **Definition**: It is a strictly increasing, immutable numeric sequence allocated per valuation scope.
* **Allocation**: The sequence must be allocated during controlled Inventory posting inside the same database transaction that creates the `InventoryTransaction`.
* **Independence**: The sequence must NOT be derived from ULID order, timestamps, queue arrival order, or Cost Ledger insertion order.
* **Consumer Processing**: The outbox consumer may only process a message if its valuation sequence matches the next expected valuation sequence recorded by the durable AVCO state.
* **Out-of-Order Guard**: Out-of-order messages cannot calculate AVCO and must not be delivered. Instead, they are failed with a safe durable reason, recoverable only through future controlled recovery, and not delivered.

### 3. Immutable Valuation Evidence
* **Evidence Ownership**: Immutable source valuation evidence is owned by the Inventory source-ledger boundary and is captured atomically with controlled Inventory posting. CostControl consumes this evidence read-only. CostControl owns derived AVCO state and Cost Ledger entries, but does not own or mutate the source evidence record.
* **Durable Sources**: The evidence must contain at least:
  * property identity
  * inventory item identity
  * inventory location/store identity
  * currency code
  * valuation scope
  * valuation sequence
  * quantity delta
  * valuation basis evidence
  * occurred-at timestamp
  * business date
  * financial-period reference or equivalent historical eligibility evidence
  * approval status
  * approval reference
  * source InventoryTransaction identity
* **Prerequisites**: The exact implementation form (e.g., immutable `InventoryTransaction` fields or an Inventory-owned immutable one-to-one valuation-evidence record), valuation basis evidence, and all other evidence fields must have durable immutable sources before consumer implementation begins. Its exact durable fields and source remain an owner-approved implementation prerequisite.

### 4. AVCO Durable State and Serialization
A CostControl-owned durable AVCO state record must be maintained per valuation scope containing:
* `on_hand_quantity`
* `carrying_value`
* `weighted_average_unit_cost`
* `last_valuation_sequence`

* **Serialization**: The consumer must load the state record using a row-level database lock (`lockForUpdate`) within its processing transaction.
* **Atomicity**: The planner execution, Cost Ledger append, AVCO state update, and Outbox `markDelivered` must occur atomically in the same database transaction.
* **Sequence Guard**: No message of sequence $N$ may be processed before sequence $N-1$ is successfully recorded in the state record.
* **Reconstruction**: The state record must never be dynamically reconstructed from mutable `InventoryStock`.

### 5. Delayed Business Date and Financial Period Policy
A deferred Cost Ledger delivery must never silently append into a closed Financial Period or bypass a closed Business Date control.
* **Source-Time Posting Eligibility**: Immutable evidence proves the Inventory posting was authorized under Business Date and Financial Period controls at posting time.
* **Deferred Cost Ledger Delivery Eligibility**: If the intended derived-ledger target is closed or otherwise ineligible when delivery is attempted, consumer delivery fails closed.
* **Correction Policy**: Historical eligibility does not authorize silent back-posting into a closed period. A future explicitly authorized correction or reopen workflow is required before a failed deferred delivery may be posted to another period or correction target.

### 6. Outbox Outcome Mapping
The `CostControl Outbox Consumer` must update the outbox message state based on the following mapping:

| Consumer Outcome | Required Outbox Result | Description |
| :--- | :--- | :--- |
| Valid plan, append succeeds | `delivered` | Success path. Message marked delivered. |
| Duplicate append matches exact equivalence | `delivered` | Idempotent success path. No new entry created. |
| Malformed topic or payload | `failed` | Permanent failure. Payload lacks ULID or is not JSON. |
| Source `InventoryTransaction` missing | `failed` | Permanent failure. Transaction does not exist in DB. |
| Evidence incomplete or unavailable | `failed` | Permanent failure. Required evidence cannot be resolved. |
| Planner rejected | `failed` | Permanent failure. Ineligible transaction context. |
| Planner pending or deferred | `failed` | Recoverable failure. Recoverable only through a future controlled recovery capability. |
| Out-of-order valuation sequence | `failed` | Recoverable failure. Recoverable only through a future controlled recovery capability. |
| Closed/ineligible posting window | `failed` | Recoverable failure. Awaiting correction workflow or reopen. |
| Unexpected infrastructure failure (completed write) | `failed` | Recoverable failure. Message becomes failed with a safe reason. |
| Unexpected process interruption (write interrupted) | `pending` | Incomplete execution. Message remains pending. |

* **Interruption Policy**: When an unexpected infrastructure failure is caught and failure-state writing can complete durably, the message becomes failed with a safe reason. When execution is interrupted before failure-state writing can complete, the message may remain pending. This represents incomplete execution, not a completed business decision, and requires future controlled recovery.
* **Immutability**: `delivered` is terminal.
* **No Silent Pending**: A completed consumer business decision must never silently remain pending.
* **No Auto-Retry**: No automatic retry loop is introduced in the consumer.

### 7. Idempotency and Crash Recovery
Cost Ledger append is idempotent. A crash after append but before `markDelivered` must be recoverable by checking structured equivalence.
* **Structured Verification**: Equivalence must compare at least:
  * `property_id`
  * `idempotency_key`
  * `entry_sequence`
  * `source_inventory_transaction_id`
  * `value_delta`
* **No Exception Matching**: Exception text parsing (e.g., matching database conflict strings) is prohibited to infer success.

---

## Consequences
* **Decoupled Validation**: Outbox messages can be written synchronously without waiting for valuation calculations.
* **Strict Ordering**: Enforcing valuation sequence guarantees the mathematical correctness of AVCO at the cost of requiring sequential delivery per valuation scope.
* **Observability**: Every processing outcome is durably tracked on the outbox record, making errors instantly observable.

---

## Implementation Prerequisites
1. Inventory-owned immutable valuation-evidence persistence path, atomically captured with controlled Inventory posting.
2. Deterministic valuation-sequence allocation per approved valuation scope, allocated inside the same Inventory posting transaction.
3. CostControl-owned durable AVCO state and scope-level serialization.
4. A later authorized consumer composition slice after the prerequisites are implemented and validated.

---

## Explicit Non-Goals
This ADR does not authorize:
* CostControl Outbox Consumer implementation.
* AVCO state migration.
* AVCO model or repository.
* InventoryTransaction schema changes.
* valuation evidence mapper.
* Outbox payload changes.
* publisher.
* queue worker.
* scheduler.
* event.
* afterCommit dispatch.
* listener registration.
* automatic retry.
* manual replay command.
* Cost Ledger correction workflow.
* General Ledger integration.
* UI or route work.

---

## Rejected Alternatives
* **Using ULID or Timestamps for Ordering**: Rejected because concurrent transaction commits can result in identical timestamps or ULID order that diverges from physical stock allocation order.
* **Reconstructing AVCO from InventoryStock**: Rejected because `InventoryStock` only tracks current balances and does not capture historical unit cost changes.
* **Processing Messages in Queue Arrival Order**: Rejected because queue arrival is non-deterministic and will break AVCO math.
* **Allowing Late Delivery to Bypass Closed Periods**: Rejected because it violates basic accounting audit and control principles.
* **Matching Exception Message Text**: Rejected because database error messages are locale-dependent and unsafe for business logic.
* **Reconstructing AVCO without Sequence**: Rejected because out-of-order execution breaks AVCO math.

---

## Owner Decisions Required
1. Conceptual AVCO valuation scope: property + immutable inventory location/store identity + item.
2. Inventory-source ownership of immutable valuation evidence, with CostControl as read-only consumer.
3. Deterministic valuation-sequence allocation during controlled Inventory posting.
4. Fail-closed policy for closed or ineligible deferred posting windows.
5. CostControl-owned AVCO state with scope-level row serialization.
