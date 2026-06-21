# ADR 010: Inventory Valuation Strategy

## 1. Title
ADR-010: Inventory Valuation Strategy

## 2. Status
Proposed

## 3. Context
Following the approval of ADR-008 (Inventory Ledger) and ADR-009 (Location Strategy), IVORQ must formalize how financial value is attached to physical stock. Hospitality inventory encompasses highly volatile perishable goods (F&B), heavily amortized operational supplies (Housekeeping/Engineering), and dynamic retail environments. The valuation strategy dictates daily Cost of Goods Sold (COGS) reporting, General Ledger accuracy, and the handling of edge cases like negative stock, late vendor invoices, and backdated receiving.

## 4. Problem Statement
Without a rigorous, mathematically sound valuation strategy, the system is exposed to extreme cost fluctuations, negative asset values, and irreconcilable month-end financial statements. Standard operational anomalies—such as selling a bottle of wine before the receiving dock posts the receipt, or a vendor sending an invoice that differs from the original Purchase Order price—must be handled with absolute deterministic rules. 

## 5. Decision
IVORQ will utilize **Moving Average Cost (AVCO)** calculated at the **Property Level** as the primary valuation method. Negative stock will be operationally permitted at the Location level to prevent halting POS sales, governed by strict mathematical reset rules. Cost variances from late vendor invoices will be handled via Purchase Price Variance (PPV) write-offs rather than retroactive AVCO restatement.

## 6. Valuation Principles
1. **Property-Level Standardization:** A SKU holds a single, unified AVCO across all locations within a Property.
2. **Current-Moment Application:** AVCO is applied and adjusted strictly at the system posting time. Re-writing history is forbidden.
3. **Variance Isolation:** Price discrepancies identified after consumption are isolated into variance accounts to protect historical COGS.
4. **Decoupled Ledgers:** The Inventory Ledger tracks quantities; the future Cost Ledger tracks the financial impact of those quantities using this valuation strategy.

## 7. AVCO Strategy
AVCO recalculation is triggered exclusively by inbound value-adding events (primarily `RECEIPT`). 
- **Formula:** `New AVCO = ((Old Qty * Old AVCO) + (Receipt Qty * Receipt Cost)) / (Old Qty + Receipt Qty)`
- `ISSUE`, `TRANSFER`, and `ADJUSTMENT_OUT` events do not change AVCO; they simply consume stock at the current AVCO.
- `ADJUSTMENT_IN` requires an explicit user-defined cost or defaults to the current AVCO.

## 8. Negative Stock Policy
**Decision: Allow Negative Stock with Controls.**
- **Rationale:** In fast-paced hospitality environments (e.g., a busy lobby bar), POS depletion (`SALES_ISSUE`) often precedes back-of-house administrative receiving. Blocking sales would paralyze the operation.
- **Valuation Rule:** When stock is driven negative, issues are valued at the *last known AVCO*. 
- **Reset Rule:** When a new `RECEIPT` brings a negative stock balance back to positive or zero, the new receipt's cost completely overwrites the AVCO. Mathematical blending of negative quantities is prohibited, as it results in negative or wildly distorted unit costs.

## 9. Backdated Transaction Policy
**Decision: Freeze Costs chronologically by System Posting Date.**
- **Rationale:** If a receipt physically occurred on June 1 but is entered on June 5, recalculating historical AVCO for every issue that happened between June 1 and June 5 is computationally devastating and alters already-published daily flash-cost reports.
- **Rule:** A backdated receipt updates the *current* AVCO at the exact moment it is posted to the ledger. The transaction date is recorded for operational tracking, but financial valuation acts strictly linearly in system time.

## 10. Return Policy
- **Department Returns (Outlet to Main Store):** Processed at the *current AVCO*.
- **Vendor Returns (Main Store to Vendor):** Processed at the *Original PO/Receipt Cost* to ensure the Accounts Payable credit note matches perfectly. If the Original Cost differs from the Current AVCO, the difference is posted directly to an Inventory Variance expense account to keep the ledger balanced.

## 11. Revaluation Policy
**Scenario:** 100 units received at $10. Three weeks later, the AP Invoice arrives at $12.
**Decision:** Do not retrospectively revalue the AVCO.
- **Rationale:** In hospitality, food cost reports run daily. Retrospectively changing AVCO alters past daily food costs, causing chaos for F&B Directors.
- **Rule:** The $200 variance is posted directly to a **Purchase Price Variance (PPV)** expense account during the Three-Way Match in AP. The Inventory AVCO remains unaffected by late AP invoices.

## 12. Property-Level Valuation Strategy
*Re-affirming ADR-009:*
- **Advantages:** Moving a bottle of vodka from the Main Store to the Bar has zero financial impact. It simplifies inter-location transfers entirely.
- **Risks:** If the Banquet department buys a premium batch of steaks, and the Restaurant buys a cheaper batch of the same SKU, their costs blend.
- **Mitigation:** Distinctly priced items intended for isolation must be set up as distinct SKUs (e.g., `Steak - Banquet` vs `Steak - Restaurant`).

## 13. Future FIFO Compatibility
To guarantee future compatibility with FIFO (First-In-First-Out), the upcoming Cost Ledger must not just store a single AVCO floating integer. 
- **Cost Layering:** The Cost Ledger must physically record "Cost Layers" (discrete receipt events). 
- **AVCO Implementation:** In v1, the AVCO engine will mathematically blend these layers. When FIFO is activated in v2, the engine will simply stop blending and begin depleting discrete layers sequentially.

## 14. Hospitality Considerations
- **Banquet Estimates:** BEOs are priced months in advance using current AVCO. Planners must be aware that actual AVCO at the time of the event execution will dictate the true COGS.
- **Mini Bars:** Given par-level replenishment, Mini Bar issues are expensed immediately at current AVCO rather than carrying asset value inside the guest rooms.
- **Recipes/Yields:** `PRODUCTION_IN` of finished goods (e.g., butchered meat) creates a new AVCO for the finished SKU based on the sum of the AVCOs of the raw ingredients consumed (`PRODUCTION_OUT`).

## 15. Month-End Governance
- **Hard Close:** Once the General Ledger period is closed, no backdated inventory transactions are permitted that carry a transaction date within the closed period.
- **Late AP Invoices:** Hit the PPV account in the *currently open* period, regardless of when the original receipt occurred.
- **Stock Counts:** Physical inventory counts post `STOCK_COUNT_GAIN` or `LOSS` at the exact AVCO at the moment of posting, bridging the final gap between theoretical and physical valuation before period lock.

## 16. Risks
- **Negative Stock Distortion:** Chronic negative stock (due to poor receiving discipline) relies heavily on the "Last Known AVCO," which may be stale, causing inaccurate daily COGS until corrected.
- **PPV Bloat:** Bypassing retroactive AVCO adjustment pushes all vendor pricing discrepancies into the PPV account. If purchasing managers are inaccurate on POs, the PPV account will swell, requiring manual manual investigation by the Finance team.

## 17. Advantages
- Computationally highly performant (no recursive historical AVCO recalculations).
- Operationally forgiving (allows hospitality POS systems to run uninterrupted via negative stock).
- Protects historical daily flash reports from retroactive alteration.

## 18. Trade-Offs
- Sacrifices absolute granular unit-cost perfection (e.g., retroactively fixing AVCO for an AP invoice) in favor of system stability and reporting predictability.

## 19. Consequences
- The Three-Way Matching engine in AP must be explicitly wired to generate PPV journal entries when Invoice Price != Receipt Price.
- The Inventory Ledger must mathematically handle the `Negative -> Positive` AVCO reset edge case perfectly, requiring extensive unit testing.
- A dedicated Cost Ledger architecture (ADR-011) must now be designed to implement these valuation rules as financial journal entries.
