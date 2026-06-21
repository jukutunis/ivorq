# ADR 017: Event Driven Accounting & Queue Resiliency Strategy

## 1. Title
ADR-017: Event Driven Accounting & Queue Resiliency Strategy

## 2. Status
Proposed

## 3. Context
IVORQ's architecture decouples physical operations (Inventory Ledger) from financial operations (Cost Ledger, AP, GL) to maintain strict separation of concerns. This separation implies an Event-Driven Architecture, where an operational action (e.g., a Goods Receipt) publishes an event that downstream financial services consume asynchronously. In a distributed system, networks partition, queues crash, consumers die, and messages are delivered out-of-order or duplicated. Without a resilient messaging architecture, IVORQ will suffer silent financial data loss, rendering the Subledger Reconciliation Framework (ADR-016) permanently out-of-balance.

## 4. Problem Statement
If a chef issues 10 steaks, the Inventory Ledger records the physical reduction immediately. The system then sends an async message to the Cost Ledger to record the COGS debit. If the messaging broker drops this message, or if a bug causes the Cost Ledger to reject it, the GL never updates. The hotel's food cost is artificially low, and the balance sheet is fraudulent. Furthermore, if a "Receipt" event arrives at the Cost Ledger *after* an "Issue" event for the same item, the AVCO calculation will execute in the wrong chronological order, corrupting the financial valuation.

## 5. Decision
IVORQ will implement an Idempotent, Strongly Ordered, Event-Driven Accounting architecture. Physical operations will be fully decoupled from financial postings via a persistent message broker (e.g., Kafka / SQS FIFO). Strict per-item event ordering guarantees chronological AVCO execution. A robust retry mechanism backed by a Dead Letter Queue (DLQ) will ensure zero financial data loss, and the Period Closing Engine will be hard-blocked if any financial queues remain uncleared.

## 6. Event Principles
1. **Asynchronous by Default:** Operational users must never wait for the General Ledger to respond.
2. **Guaranteed Delivery (At-Least-Once):** Messages must be durably stored before acknowledging the operational user.
3. **Idempotency is Law:** Every financial consumer must gracefully handle processing the exact same message twice without double-posting.
4. **Ordered Processing:** Financial events for the same entity (Item/Location) must process strictly in the order they occurred physically.

## 7. Posting Strategy
- **Sync vs Async:** Operations (Inventory Ledger) write synchronously to ensure physical stock constraints (e.g., negative stock blocks). Financials (Cost Ledger, GL, AP) execute asynchronously by listening to domain events (`InventoryMoved`, `InvoiceMatched`).
- **Event Ordering:** The message broker must support partition keys. Events will be partitioned by `property_id` and `item_id`. This guarantees that all transactions for "Salmon at Resort A" process chronologically, protecting the AVCO math.

## 8. Retry Strategy
- If a consumer fails to process a message (e.g., database timeout), it will throw an exception.
- The broker will automatically execute an **Exponential Backoff Retry** (e.g., 5 seconds, 30 seconds, 5 minutes, 1 hour).
- While a specific partition is retrying, subsequent messages for that *exact same item* must block to preserve ordering.

## 9. Dead Letter Queue Strategy
- If a message fails after maximum retries (e.g., a hard code bug like a Null Pointer Exception), it is moved to a Dead Letter Queue (DLQ).
- Moving the toxic message to the DLQ unblocks the partition, allowing subsequent transactions for that item to process. 
- *Note:* This temporarily violates strict ordering, but prevents a single bad tomato receipt from halting the entire hotel's financial postings.

## 10. Replay Strategy
- The DLQ acts as a holding pen. The IT/Engineering team must investigate the toxic message, deploy a code fix, and execute a manual **DLQ Replay**.
- When replayed, the message processes through the fixed logic. The Cost Engine must be capable of inserting this "late" record and recalculating the financial state accordingly.

## 11. Monitoring Strategy
- **Queue Depth & Lag:** IT must monitor the time delta between event generation and consumer processing. If lag exceeds 5 minutes, alerts fire.
- **DLQ Alarms:** A single message entering the DLQ triggers a critical P1 alert to the Finance and IT teams. A non-empty DLQ indicates active financial drift.

## 12. Financial Integrity Controls
- **Idempotency Keys:** Every published event will contain a unique `idempotency_key` (e.g., the UUID of the `inventory_ledger_entry`). The Cost Ledger and GL must check an `idempotent_consumer_logs` table before processing. If the key exists, the message is silently dropped as a duplicate.

## 13. Reconciliation Integration
*Ref ADR-016:*
The Subledger Reconciliation Engine cannot trust its results if messages are in-flight. Before the nightly reconciliation report executes, it must query the message broker. If `Queue Depth > 0`, the reconciliation pauses and waits, or flags the report as "Pending Async Resolution."

## 14. Period Closing Integration
*Ref ADR-013:*
The Director of Finance cannot trigger a `SOFT CLOSE` or `CLOSED` state if the Dead Letter Queue contains *any* messages for that property. Allowing a close with a non-empty DLQ guarantees permanent financial drift, as those failed messages will inevitably belong to the period being locked.

## 15. Multi Property Rules
- Multi-tenancy must be respected at the broker level. A massive spike in POS depletions at Property A must not saturate the consumer pool and delay financial postings for Property B.
- Queues should ideally be isolated per tenant or heavily partitioned to ensure fair throughput.

## 16. Audit Requirements
- The `idempotent_consumer_logs` must be immutable, tracking exactly when an event was processed and by which consumer node.
- Manual DLQ replays must log the ULID of the IT admin triggering the replay.

## 17. Risks
- **AVCO Ordering Violation:** If a toxic `RECEIPT` event goes to the DLQ, and a subsequent `ISSUE` event processes successfully, the `ISSUE` will be valued at the *old* AVCO. When the toxic `RECEIPT` is eventually replayed, the Cost Ledger will have to retroactively calculate the variance caused by the out-of-order processing, heavily complicating the ledger math.
- **Message Payload Bloat:** If events carry the entire state of the transaction, the broker can choke. (Mitigation: Use "Event Notification" payloads that only carry the ID, forcing the consumer to fetch the fresh state from the database).

## 18. Advantages
- Infinite scalability. The operational database will never lock up waiting for complex financial accounting rules to execute.
- Ultimate resilience. Database outages in the Finance module will not stop the hotel from receiving or selling inventory.

## 19. Trade-Offs
- Severe architectural complexity. Distributed systems require specialized engineering skills (Kafka/RabbitMQ), extensive monitoring tools, and highly complex troubleshooting workflows for the Finance team.

## 20. Consequences
- The development team must implement an Idempotency middleware for all financial consumers.
- The UI must expose a "System Health" dashboard to the Director of Finance so they can see if their daily flash reports are fully caught up with physical operations.
