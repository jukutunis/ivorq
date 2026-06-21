# ADR 023: Intercompany Accounting & Transfer Ledger

## 1. Title
ADR-023: Intercompany Accounting & Transfer Ledger

## 2. Status
Proposed

## 3. Context
IVORQ's architecture supports multi-property operations. While ADR-009 defined the physical location hierarchy (Central Warehouse to Outlet), it did not address the complex financial realities of moving goods across **Legal Entity boundaries**. In a hospitality group, Property A (Legal Entity 1) might borrow 50 bottles of champagne from Property B (Legal Entity 2) for a massive New Year's Eve banquet. Because these are distinct corporate entities, this is not a simple physical transfer; it is a legally binding sale that must navigate transfer pricing laws, cross-border multi-currency ledgers, physical transit delays, and stringent consolidation audits.

## 4. Problem Statement
If IVORQ treats inter-company transfers like simple intra-property transfers, the system will violate tax laws by failing to generate formal invoices and recognize transfer revenue. If inventory is instantly teleported from Property A to Property B in the system, but the physical truck takes 3 days to arrive, the inventory ledgers for both properties will be physically incorrect, triggering false variance alarms (ADR-022). Furthermore, if Property B disputes the quantity received (e.g., 2 bottles broke in transit), the resulting discrepancy in the Due To / Due From accounts will cause the entire Group's month-end consolidation to fail the Subledger Reconciliation Framework (ADR-016).

## 5. Decision
IVORQ will implement a rigid **Intercompany Transfer & Transit Ledger**. The architecture distinguishes between Intra-Company (same legal entity) and Inter-Company (cross-entity) transfers. Inter-company movements will auto-generate mirror Intercompany AR ("Due From") and Intercompany AP ("Due To") documents. Inventory will never teleport; it must pass through an explicit `IN_TRANSIT` state. The Sending Property retains financial ownership and write-off liability for the inventory until the Receiving Property explicitly executes the receipt. All Intercompany balances must reconcile to exactly $0.00 before the Period Close (ADR-013) is permitted.

## 6. Entity Principles
1. **Intra-Company:** Transfer within the same Legal Entity. Purely a location move. Cost Ledger transfers the AVCO directly. No AP/AR generated.
2. **Inter-Company:** Transfer across Legal Entities. Triggers a formal internal sale. Generates Intercompany AP, Intercompany AR, Intercompany Revenue, and Intercompany COGS.

## 7. Transfer Rules
1. **Shipment (Dispatch):** Property A dispatches 50 bottles. The Inventory Ledger moves 50 bottles from Property A `MAIN_STORE` to Property A `IN_TRANSIT`.
2. **Receipt:** Property B physically receives the goods and logs it in IVORQ. The system moves 50 bottles from Property A `IN_TRANSIT` to Property B `MAIN_STORE`.
3. **The Mirror Event:** The instant Property B hits "Receive", the system automatically generates:
   - Approved AR Invoice in Property A.
   - Approved AP Invoice in Property B.

## 8. Pricing Rules
- **Transfer Pricing:** Corporate tax laws often forbid transferring goods "at cost" across borders to prevent profit shifting. IVORQ must support **Cost + Markup** transfer pricing profiles.
- **Financial Flow:**
  - Property A COGS (Debit) = Actual AVCO
  - Property A AR (Debit) = Transfer Price
  - Property A Intercompany Revenue (Credit) = Transfer Price
  - Property B Inventory Asset (Debit) = Transfer Price (This becomes Property B's new AVCO).
  - Property B AP (Credit) = Transfer Price

## 9. Transit Rules (Lost in Transit)
- **Scenario:** Property A ships 50 bottles. Property B receives 48 bottles (2 broken in the truck).
- **Resolution:** 
  - Property B receives 48. The mirror AP/AR is generated *only for 48 bottles*.
  - 2 bottles remain stuck in Property A's `IN_TRANSIT` location.
  - Property A is forced to execute an `ADJUSTMENT_OUT` (Shrinkage/Breakage) from the `IN_TRANSIT` location, absorbing the financial loss (ADR-014). The receiving property never pays for what it didn't physically receive.

## 10. Reconciliation Rules
*Ref ADR-016:*
The Subledger Reconciliation Framework enforces a strict rule: `Sum(Due From Entity X to Entity Y) == Sum(Due To Entity Y from Entity X)`. Because the AP and AR documents are systemically auto-generated from the exact same Receiving Event, discrepancies are architecturally impossible unless manual journal entries are maliciously posted to the Control Accounts.

## 11. Consolidation Rules
- At the Group reporting level, all Intercompany AP, AR, Revenue, and COGS accounts are mapped to designated "Elimination Accounts." When the consolidated financial statements are run, these accounts wash each other out, ensuring the Group does not artificially inflate its total revenue by selling champagne to itself.

## 12. Multi Currency Rules
*Ref ADR-018:*
- If Property A (IDR) ships to Property B (SGD). The Transfer Price is established in the agreed Transaction Currency (e.g., SGD).
- Both the Intercompany AP and AR subledgers are subject to the standard Month-End FX Revaluation Engine (ADR-018) to calculate Unrealized FX Gains/Losses before Period Close.

## 13. Reporting Requirements
1. **Transit Aging Report:** Highlights shipments that left Property A > 5 days ago but haven't been received by Property B.
2. **Intercompany Out-of-Balance Matrix:** A grid showing Due To / Due From matches across all entities.
3. **Lost In Transit Variance Report:** Identifies which transport routes or shipping managers are causing the most breakage.

## 14. Audit Requirements
- The system must prevent Property A from unilaterally pushing inventory into Property B's ledger. A transfer is a two-key system: Dispatch (Key 1) and Receive (Key 2).
- Transfer Pricing markups must be tied to a centrally governed `TransferPricingProfile` to satisfy tax auditor inquiries into corporate profit shifting.

## 15. Risks
- **The Transit Black Hole:** If Property B forgets to hit "Receive" on the tablet, the goods remain in `IN_TRANSIT` indefinitely. Because they aren't in Property B's active inventory, Property B cannot legally sell them through the POS (ADR-021), leading to negative stock warnings and massive Theoretical vs Actual variances.
- **Cross-Border Customs Delays:** An international transfer might sit in a customs warehouse for 3 weeks. The `IN_TRANSIT` ledger will hold massive value, potentially distorting Property A's working capital ratios.

## 16. Advantages
- Absolute legal compliance for multi-tenant, multi-entity corporate structures.
- Eliminates the number one cause of month-end consolidation nightmares: misaligned Intercompany AP/AR.
- Mathematically protects the receiving property from being forced to pay for goods broken in transit.

## 17. Trade-Offs
- Forces a highly rigid "Receive" workflow on the destination property. They cannot bypass the system; if they want to sell the champagne tonight, they *must* execute the systemic receipt to move it out of Transit.

## 18. Consequences
- The database schema requires an explicit `IN_TRANSIT` logical location inherently tied to the *Sender's* property ID.
- The Period Closing engine (ADR-013) must add a hard-block rule: A period cannot `SOFT CLOSE` if any shipments dispatched *to* or *from* that property are older than N days in the `IN_TRANSIT` state.
