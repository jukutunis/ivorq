# ADR 015: Landed Cost & Tax Apportionment Strategy

## 1. Title
ADR-015: Landed Cost & Tax Apportionment Strategy

## 2. Status
Proposed

## 3. Context
Following the approval of AVCO valuation (ADR-010), GRNI accruals (ADR-011), and the Cost Ledger (ADR-012), IVORQ must define the mathematical boundary of "Inventory Cost." In enterprise hospitality, particularly in remote resorts or international properties, the invoice price of an item (e.g., imported wine or premium seafood) represents only a fraction of its true cost. Freight, import duties, customs brokers, and non-recoverable taxes often inflate the final cost by 30-50%. If these additional costs are expensed generically to the P&L rather than capitalized into the inventory asset, Food & Beverage margins will appear artificially profitable, while generic operational expenses will inexplicably balloon.

## 4. Problem Statement
Failing to capitalize landed costs results in severely distorted gross margins and an understated Balance Sheet. However, capitalizing costs requires complex mathematical apportionment across mixed-SKU shipments. Furthermore, tax treatments vary globally; capitalizing a recoverable VAT constitutes financial fraud, while failing to capitalize a non-recoverable sales tax understates COGS. Finally, freight invoices often arrive weeks after the physical goods, creating a temporal mismatch with the AVCO engine.

## 5. Decision
IVORQ will implement a comprehensive Landed Cost Engine. "Directly Attributable" procurement costs (freight, insurance, non-recoverable taxes, import duties) will be strictly capitalized into the Inventory Asset (AVCO) at the moment of Receiving. Apportionment of mixed-shipment freight will default to **Allocation by Value**, with manual overrides. Late-arriving landed cost invoice variances will be absorbed by Purchase Price Variance (PPV), explicitly adhering to the frozen-AVCO rule established in ADR-010.

## 6. Landed Cost Principles
1. **True Gross Margin:** The Cost of Goods Sold (COGS) must reflect the total cost to acquire the goods and bring them to their present location and condition.
2. **Estimate at Receipt:** Landed costs must be estimated and accrued at Receiving to establish an accurate AVCO immediately. Waiting for the freight invoice is prohibited.
3. **Tax Legality:** Recoverable taxes belong to the Balance Sheet (Asset); non-recoverable taxes belong to the Inventory AVCO.
4. **Frozen AVCO:** Variances between estimated landed costs and actual landed cost invoices do not retroactively alter AVCO.

## 7. Cost Component Rules
- **Capitalized into AVCO:** Purchase Price, Freight, Shipping Insurance, Import Duties, Custom Duties, Non-Recoverable Taxes, Environmental/Handling Fees strictly tied to the PO.
- **Expensed to P&L (Not Capitalized):** Storage costs, generic warehouse overhead, demurrage (penalties for delayed customs clearance), and internal purchasing department salaries.

## 8. Tax Rules
- **Recoverable VAT / GST:** IVORQ will extract this from the PO line and accrue it directly to a `Recoverable Tax Asset` GL Account. It has zero impact on AVCO or COGS.
- **Non-Recoverable VAT / Sales Tax:** IVORQ will capitalize this directly into the item's AVCO.
- **Luxury Tax / Import Duty:** Capitalized into AVCO.

## 9. Allocation Rules
When a single $500 freight charge applies to a mixed PO containing $4,000 of Wagyu Beef and $1,000 of Carrots:
- **Default Strategy:** **Allocation by Value**. Freight is distributed proportionally based on the financial weight of the line items. (Beef absorbs $400 freight; Carrots absorb $100 freight).
- **Rationale:** Allocation by Quantity or Volume requires perfect, exhaustive master data (weight/dimensions for every single SKU), which hospitality procurement rarely maintains. Allocation by Value is mathematically robust and standard in tier-1 ERPs.
- **Override:** Procurement managers can manually force a specific allocation (e.g., assigning all dry-ice freight specifically to the seafood line).

## 10. AVCO Rules
At the moment of Receiving:
`AVCO = (Purchase Price + Apportioned Freight + Apportioned Duties + Non-Recoverable Tax) / Quantity`
This establishes the new, true unit cost in the Cost Ledger immediately.

## 11. GRNI Integration
Because freight and duties are often billed by different vendors (e.g., DHL vs. the Meat Supplier), GRNI must be split:
- **Receiving Posting:**
  - Dr Inventory Asset ($5,000 Meat + $500 Freight)
  - Cr GRNI - Meat Vendor ($5,000)
  - Cr GRNI - Freight Forwarder ($500 estimated)
- **AP Matching:** The AP module must allow matching a freight invoice against the `GRNI - Freight Forwarder` liability pool without requiring a physical SKU receipt. Variances between the $500 estimate and the actual $550 DHL invoice route directly to PPV (ADR-014).

## 12. Multi Property Rules
- If a Central Warehouse receives imported wine, the Landed Cost is capitalized into its AVCO.
- When transferring to Property A, the *Transfer Value* is the Central Warehouse's fully loaded AVCO.
- If inter-property transit incurs significant internal freight (e.g., a boat to a remote island resort), Property A may capitalize that internal freight to establish an even higher localized AVCO.

## 13. Reporting Requirements
1. **Landed Cost Analysis:** Comparing base vendor price vs. fully loaded AVCO to expose the true cost of importing vs. local sourcing.
2. **Tax Allocation Report:** Proving to tax authorities the split between Recoverable and Non-Recoverable taxes.
3. **Freight PPV Report:** Highlighting discrepancies between estimated freight at receiving and actual freight invoices.

## 14. Audit Requirements
- The system must retain the granular breakdown (Base Price vs. Freight vs. Duty) inside the Cost Ledger entry, even though the Inventory AVCO is a single blended number.
- Any manual overrides to the "Allocation by Value" algorithm must log the user ULID and timestamp.

## 15. Hospitality Considerations
- **Imported Wine / Liquor:** Import duties and luxury taxes on alcohol can easily exceed 100% of the base purchase price in certain jurisdictions (e.g., Indonesia, Maldives). Without Landed Cost capitalization, Beverage Cost % reporting is a mathematical fiction.
- **Emergency Air Freight:** If a crucial machine part is air-freighted at a massive premium, the engineering department must absorb that landed cost into their maintenance budget, preventing it from being hidden in a generic hotel shipping expense account.

## 16. Risks
- **Estimation Accuracy:** If procurement consistently fails to estimate freight accurately on POs, the resulting AVCO will be artificially low/high, and the Freight PPV account will absorb massive corrections at month-end.
- **Complex AP Matching:** AP Clerks will struggle to match aggregate monthly DHL invoices against dozens of individual GRNI Freight accruals without a highly optimized matching UI.
- **Cross-Currency Freight:** If goods are purchased in USD, freight is billed in EUR, and the hotel operates in IDR, the Receiving event must perform three simultaneous currency conversions using the spot rate of the *Receipt Date* to establish the IDR AVCO.

## 17. Advantages
- Accurate, true-to-life Food & Beverage margins.
- Clean Balance Sheet valuation that satisfies stringent external audits.
- Complete visibility into supply chain overhead costs per SKU.

## 18. Trade-Offs
- Substantially increases the complexity of PO creation (buyers must estimate freight/tax upfront) and AP matching (clerks must match multi-vendor liabilities).

## 19. Consequences
- The AP matching engine must be upgraded to support "Non-PO Invoice Matching against Landed Cost Accruals."
- The Purchase Order UI must be redesigned to allow adding "Landed Cost Headers" (Freight, Duty) and defining their apportionment strategy before the PO is approved.
