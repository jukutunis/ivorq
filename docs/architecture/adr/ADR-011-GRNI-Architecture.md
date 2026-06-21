# ADR 011: GRNI Architecture

## 1. Title
ADR-011: Goods Received Not Invoiced (GRNI) Architecture

## 2. Status
Proposed

## 3. Context
Following the stabilization of the Inventory Valuation Strategy (ADR-010) and Inventory Location Strategy (ADR-009), IVORQ must define the strict accounting bridge between physical receiving operations and Accounts Payable (AP). In hospitality, physical deliveries of perishables, linens, and supplies often precede the arrival of the vendor's financial invoice by days or weeks. This timing difference requires an architectural mechanism to ensure the hotel's Balance Sheet correctly reflects the sudden increase in physical assets without prematurely recognizing an AP liability.

## 4. Problem Statement
If IVORQ increases the Inventory Asset account at the time of receiving but fails to accrue a corresponding liability, the Balance Sheet will become fundamentally unbalanced. Conversely, waiting for the AP invoice to record the inventory receipt blinds the operational teams to physical stock levels and devastates daily Food & Beverage cost reporting. A robust GRNI (Goods Received Not Invoiced) mechanism is the mandatory solution to this accrual accounting gap.

## 5. Decision
IVORQ will implement a strict GRNI accrual architecture. Every inventory and direct-expense receipt will automatically trigger a GRNI liability accrual. AP Invoices will never interact directly with inventory assets or expenses; instead, they will strictly match against and clear the existing GRNI liability.

## 6. GRNI Principles
1. **Mandatory Accrual:** GRNI is not optional in IVORQ. It is absolute law for all accrual-based enterprise environments.
2. **Three-Way Matching:** AP Invoices match against Receipts (GRNs), not Purchase Orders. POs dictate *intent*; Receipts dictate *liability*.
3. **Quantity Integrity:** The `uninvoiced_quantity` on a Receipt Line is the ultimate governor of invoice matching.
4. **Valuation Fidelity:** GRNI is cleared at the exact cost it was accrued. Any discrepancy between Receipt Cost and Invoice Cost is routed to Purchase Price Variance (PPV).

## 7. Receiving Posting Model
At the exact moment a `ReceivingDocument` is finalized:
- **Inventory Ledger:** Increases stock quantity.
- **Cost Ledger / GL:** Debits Inventory Asset (or Department Expense for Direct Issues) and Credits GRNI Liability.
- **AP:** Remains entirely untouched.

## 8. Invoice Matching Model
When an `ApInvoice` arrives, it is processed via the `ThreeWayMatchingEngine`:
- **Scenario A (Perfect Match):** Receipt was $1000. Invoice is $1000.
  - Debit GRNI: $1000
  - Credit AP: $1000
- **Scenario B (Price Variance):** Receipt was $1000. Invoice is $1200.
  - Debit GRNI: $1000 (clearing the original accrual exactly)
  - Debit PPV (Variance): $200
  - Credit AP: $1200

## 9. Partial Receiving Rules
If a PO is for 100 units, but only 40 are received:
- GRNI accrues only for 40 units at the PO cost.
- The PO line remains open for the remaining 60 backordered units.
- AP can only match against the 40 units physically received.

## 10. Partial Invoice Rules
If 100 units are received, but the vendor sends Invoice #1 for 40 units and Invoice #2 for 60 units:
- The Matching Model operates at the **Receipt Line Level**.
- Invoice #1 matches against the receipt line, reducing its `uninvoiced_quantity` from 100 to 60. GRNI is partially cleared.
- Invoice #2 matches against the remaining 60 units, fully depleting the `uninvoiced_quantity` and clearing the GRNI liability to zero.

## 11. Over/Under Invoice Controls
- **Over-Invoice Protection:** An AP Invoice line attempting to match a quantity greater than the receipt's `uninvoiced_quantity` will trigger a **HARD BLOCK**. The vendor must issue a revised invoice, or the hotel must process a supplementary receipt for the undocumented goods. (Configurable minor value tolerances may be allowed via the Approval Engine).
- **Under-Invoice Protection:** Permitted by default as standard Partial Invoicing. If the vendor never invoices the remaining balance, the aging GRNI liability must be formally cleared via a manual "GRNI Write-Off" journal, adjusting PPV or COGS accordingly.

## 12. PPV Integration
*Re-affirming ADR-010:* The GRNI clearing transaction is the birthplace of the PPV entry. Because the Inventory AVCO was already frozen and calculated at Receiving time, the AP matching engine is solely responsible for detecting the price variance, isolating it, and dumping it into the PPV expense account during the GRNI clearance.

## 13. Month-End Treatment
- **Balance Sheet:** Aging, unmatched GRNI balances sit as a **Short-Term Current Liability** on the Balance Sheet.
- **P&L Impact:** GRNI itself never hits the P&L. However, if the receipt was a Direct Issue (bypassing inventory), the expense hit the P&L at receiving time, offset by the GRNI liability on the Balance Sheet.
- **Reporting:** Month-end financial packages must include a "GRNI Aging Report" detailing exactly which receipts remain uninvoiced.

## 14. Audit Requirements
- Every `ApInvoiceLine` must carry a foreign key directly to the `ReceivingLine` it consumed.
- The `ReceivingLine` must actively track `uninvoiced_quantity`.
- The GRNI Subledger must explicitly balance against the GL GRNI Control Account at all times.

## 15. Hospitality Considerations
- **Night Drops (Bread/Dairy):** F&B vendors often deliver physical goods with the physical invoice taped to the box. Operational workflow must allow Receiving to post the GRN, immediately followed by AP posting the Invoice, without frictional delays.
- **Direct Expense Purchases:** Engineering parts often bypass the storeroom and go straight to a Work Order. The GRNI architecture seamlessly handles this by debiting the Work Order Expense instead of Inventory Asset, while still accruing the GRNI liability to await the vendor invoice.

## 16. Risks
- **Stale GRNI Buildup:** If Receiving teams fail to communicate returns, or if AP fails to match invoices accurately, the GRNI liability account will bloat infinitely, causing massive month-end audit failures.
- **Currency Fluctuations:** If the receipt is in a foreign currency, exchange rate drift between Receipt Date and Invoice Date will create FX Variances alongside standard Purchase Price Variances.

## 17. Advantages
- Absolute adherence to GAAP and IFRS accrual accounting standards.
- Unlocks real-time daily food cost reporting because the expense/asset is recognized immediately upon physical delivery.

## 18. Trade-Offs
- Forces a strict administrative sequence. AP cannot simply "pay an invoice" for inventory without the warehouse staff having first executed a formal system receipt.

## 19. Consequences
- The Three-Way Matching engine (currently implemented) requires continuous regression testing to ensure it perfectly respects `uninvoiced_quantity` depletion.
- A "GRNI Clearance / Write-Off" workflow must be designed to allow Finance Directors to clean up ancient, abandoned GRNI balances.
