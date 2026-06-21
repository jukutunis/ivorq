# ADR 012: Cost Ledger Architecture

## 1. Title
ADR-012: Cost Ledger Architecture

## 2. Status
Proposed

## 3. Context
With the Inventory Ledger (ADR-008), Location Strategy (ADR-009), Valuation Strategy (ADR-010), and GRNI Architecture (ADR-011) approved, IVORQ must now define how physical inventory movements translate into financial impact. In enterprise hospitality, calculating accurate Food Cost %, tracking Purchase Price Variance (PPV), and supporting month-end General Ledger (GL) reconciliations require an intermediary layer between physical stock counts and the corporate GL. This layer is the Cost Ledger.

## 4. Problem Statement
The Inventory Ledger tracks quantities and locations. If we force it to also track financial values, GL account mappings, PPV variances, and department cost centers, the schema will bloat, violating the Single Responsibility Principle. Furthermore, bypassing a sub-ledger and posting physical movements directly to the GL destroys item-level granular reporting (e.g., answering "How much did we spend specifically on Atlantic Salmon in the Lobby Bar this month?").

## 5. Decision
IVORQ will implement an immutable **Cost Ledger** that acts as the financial sub-ledger for inventory. An automated **Cost Posting Engine** will listen to Inventory Ledger movements and AP Invoice variances, translating them into double-entry financial records. The Cost Ledger will serve as the exclusive bridge to the General Ledger for all cost-of-goods and inventory asset valuations.

## 6. Cost Ledger Principles
1. **Decoupled Responsibilities:** Quantities belong to the Inventory Ledger. Values belong to the Cost Ledger.
2. **Immutability:** Cost Ledger entries are strictly append-only.
3. **Double-Entry Sub-ledger:** Every cost entry must balance (Debit = Credit) to ensure perfect eventual consistency with the GL.
4. **Current Period Posting:** Backdated operational movements must hit the Cost Ledger in the *currently open* financial period.
5. **FIFO Readiness:** The ledger must structurally support "Cost Layers" to seamlessly transition from AVCO to FIFO in the future.

## 7. Inventory Ledger vs Cost Ledger
| Domain | Inventory Ledger | Cost Ledger |
| :--- | :--- | :--- |
| **Responsibility** | Tracks physical reality. | Tracks financial impact. |
| **Primary Metric** | Quantity (`+` / `-`) | Value (Debit / Credit) |
| **Core Dimensions** | Item, Location, UOM | Property, Department, GL Account |
| **What It Must NOT Do** | Care about GL accounts or dollar values. | Care about physical bin locations or rack capacities. |

## 8. Cost Posting Model
The Cost Posting Engine evaluates inventory movements and applies rules:
- **`RECEIPT`**: Dr Inventory Asset | Cr GRNI Liability
- **`ISSUE`**: Dr Department COGS/Expense | Cr Inventory Asset
- **`STOCK_COUNT_LOSS`**: Dr Inventory Variance Expense | Cr Inventory Asset
- **`RETURN_TO_VENDOR`**: Dr GRNI (or AP) | Cr Inventory Asset

## 9. Cost Entry Governance
- **Immutability:** Absolute. No `UPDATE` or `DELETE` statements allowed.
- **Reversal Strategy:** Errors in the Inventory Ledger trigger an exact contra-entry in the Cost Ledger (e.g., reversing an Issue posts Dr Inventory Asset | Cr Department COGS), explicitly linked to the `reversed_cost_entry_id`.
- **Audit Strategy:** Every Cost Ledger entry must hold a foreign key to the `inventory_ledger_entry_id` or `ap_invoice_line_id` that spawned it.

## 10. Cost Source of Truth
- The **Inventory Ledger** is the source of truth for physical quantities.
- The **Cost Ledger** is the source of truth for item-level valuation, PPV, and departmental COGS.
- The **General Ledger** is the source of truth for corporate reporting. The sum of the Cost Ledger balances *must exactly match* the GL Inventory Control Account.

## 11. Transfer Costing Rules
*(Ref ADR-009: Valuation by Property)*
- **Intra-Property Transfers (e.g., Main Store to Bar):** Because AVCO is property-wide, moving goods within the same property has *zero* financial impact. The Cost Ledger posts **NOTHING**.
- **Inter-Property Transfers (e.g., Hotel A to Hotel B):** Ownership changes. The Cost Ledger posts Dr Intercompany AR | Cr Inventory Asset at the sending property's AVCO.

## 12. PPV Integration
When AP matches an invoice to a receipt with a price discrepancy (ADR-011), it triggers an event. The Cost Posting Engine receives this event and posts the variance directly to the Cost Ledger:
- Dr GRNI (Receipt Cost)
- Dr/Cr PPV Expense (Variance)
- Cr AP Liability (Invoice Cost)
This keeps the PPV tightly linked to the specific Item and Vendor in the sub-ledger for later procurement analysis.

## 13. Cost Period Governance
- The Cost Ledger strictly obeys the active GL Financial Period. 
- If an inventory count from March 31st is entered on April 2nd (after the March Cost Period is locked), the physical date is logged as March 31, but the Cost Ledger posts the financial variance into the open April period. **Closed periods can never be altered.**

## 14. Cost Dimensions
To support granular hospitality reporting, Cost Ledger entries mandate:
- `property_id`
- `department_id` (Cost Center)
- `item_id` (SKU)
- `item_category_id` (e.g., Food, Beverage, Cleaning Supplies)
- `event_id` (Optional: BEO reference for Banquet tracking)

## 15. Hospitality Considerations
- **Daily Flash Reporting:** Food & Beverage Directors rely on daily COGS numbers. The automated Cost Posting Engine ensures that the moment a steak is issued to the kitchen, the daily F&B Cost % report updates in real-time.
- **Banquet Allocations:** Allocating costs directly to specific BEOs allows profitability tracking per event, a massive competitive advantage over standard ERPs.

## 16. Future FIFO Compatibility
To allow FIFO tomorrow, the Cost Ledger schema must include a `cost_layer_id` (or `receipt_reference_id`) on all depletion entries (Issues, Variances). In AVCO (v1), this field may be null or point to an aggregated average. When FIFO activates, the engine will explicitly link an Issue's Cost Ledger entry to the specific Receipt Cost Layer it consumed.

## 17. Reporting Dependencies
The Cost Ledger exclusively powers:
1. Daily Flash Food & Beverage Cost %
2. Sub-ledger to GL Reconciliation Reports
3. Purchase Price Variance (PPV) Analytics by Vendor/Item
4. Inventory Shrinkage/Variance Financial Impact Reports

## 18. Risks
- **Eventual Consistency Lag:** If the Cost Posting Engine is heavily queued asynchronously, operational managers might see physical stock drop before the Finance Director sees the COGS hit, causing momentary report mismatch.
- **Zero-Value Transactions:** Handling zero-value receipts (e.g., free vendor samples) requires the Cost Ledger to accept entries with $0.00 value to maintain the quantity/value bridge without throwing division-by-zero errors in AVCO recalculations.

## 19. Advantages
- Absolute separation of physical warehouse operations from strict financial governance.
- Protects the General Ledger from massive transactional volume (GL only needs summarized daily/monthly journals, not per-tomato granularity).
- Provides tier-1 ERP sub-ledger analytics natively tailored to hospitality.

## 20. Trade-Offs
- Substantially increases architectural complexity. An entire translation engine must be built and maintained to convert inventory events into double-entry accounting records.

## 21. Consequences
- The development team must build a robust `CostPostingEngine` service.
- Strict unit testing is required to ensure that the sum of all Cost Ledger entries always equals the active Inventory Asset value.
- A reconciliation tool must be built to prove the Cost Ledger matches the General Ledger at month-end.
