# ADR 027: Fixed Asset & Depreciation Architecture

## 1. Title
ADR-027: Fixed Asset & Depreciation Architecture

## 2. Status
Proposed

## 3. Context
Hotels are inherently capital-intensive businesses. The building, the Furniture, Fixtures & Equipment (FF&E), the industrial kitchen machinery, and the IT infrastructure represent the vast majority of the company's Balance Sheet. These long-term assets slowly consume their value over years of operation. While IVORQ's previous ADRs brilliantly govern short-term, fast-moving F&B inventory and daily operational revenue, they do not address capital expenditure (CapEx). Without a dedicated Fixed Asset Register and automated Depreciation Engine, the hotel's P&L will either be crushed by massive one-off equipment purchases (wrongly expensed) or artificially inflated by failing to recognize the slow decay of its physical infrastructure.

## 4. Problem Statement
If a hotel buys a $50,000 industrial oven and expenses it immediately, the Food & Beverage department will show a catastrophic loss for that month, destroying the Executive Chef's KPIs. Conversely, if the oven is correctly capitalized as an asset but the system forgets to execute the monthly $400 depreciation journal, the Balance Sheet will permanently overstate the hotel's net worth, leading to severe IFRS/GAAP audit failures. Furthermore, hotel renovations (which can take 6 months) require accumulating costs in a temporary state before depreciation begins. Finally, if Property A transfers a half-depreciated oven to Property B at a markup, the Intercompany Consolidation Engine (ADR-024) will fail to properly eliminate the unrealized profit embedded in the ongoing depreciation schedules.

## 5. Decision
IVORQ will implement an **IFRS-Compliant Fixed Asset Register (FAR) & Depreciation Engine**. A strict Capitalization Threshold will enforce the boundary between operational expenses and CapEx. The architecture will support Construction In Progress (CIP) for multi-month renovations, and enforce Component Accounting for complex assets. The Period Closing Strategy (ADR-013) will be hard-blocked unless the monthly Automated Depreciation Routine executes successfully.

## 6. Asset Classification Rules
- **Component Accounting (IFRS requirement):** A "Hotel Building" cannot be a single asset. It must be broken down into components with distinct useful lives (e.g., Structure: 50 years, Roof: 15 years, Elevators: 20 years).
- **Categories:** Assets are strictly classified into standard hospitality buckets (Land, Buildings, IT Equipment, Kitchen Equipment, Vehicles, FF&E). Each category defines a default Useful Life and Depreciation Method.

## 7. Capitalization Rules
- **Capitalization Threshold:** A configurable systemic limit (e.g., $1,000). Any PO line item below this value is automatically forced into an operational Expense account. Any item above it is routed to a Capital Clearing Account pending creation in the FAR.
- **Construction In Progress (CIP):** Costs for renovations accumulate in a CIP Asset account. CIP assets **do not depreciate**. Once the renovation is complete, the Director of Finance executes a "Put into Service" event, which moves the value from CIP to the active Fixed Asset category and initiates the depreciation schedule.

## 8. Depreciation Rules
- **Method:** Straight-Line Depreciation is the system default. 
- **Execution:** Executed automatically during the Period Close gateway (ADR-013).
- **Posting:** `Dr Depreciation Expense (P&L) | Cr Accumulated Depreciation (Contra-Asset)`. 
- **Net Book Value (NBV):** `Original Cost - Accumulated Depreciation = NBV`. Assets can never depreciate below their designated Salvage Value (default $0).

## 9. Transfer Rules (Intercompany)
*Ref ADR-023 & ADR-024:*
- **Intra-Company:** Property A transfers an oven to Property A's satellite location. The Original Cost and Accumulated Depreciation transfer seamlessly.
- **Inter-Company:** Property A sells an oven to Property B. 
  - If sold at NBV: Clean transfer.
  - If sold at a Markup (e.g., NBV $5,000, Transfer Price $6,000): Property A recognizes a $1,000 Gain on Disposal. Property B capitalizes a new $6,000 asset.
  - **Consolidation Impact:** The Group Elimination Engine (ADR-024) must eliminate the $1,000 gain, return the asset value back to $5,000, and mathematically adjust Property B's ongoing depreciation journals to reflect the original Group cost basis.

## 10. Disposal Rules
- An asset is scrapped, sold, or destroyed.
- The FAR completely removes the Original Cost and the Accumulated Depreciation from the GL. 
- The difference between the NBV and the cash received (if sold) is posted to `Gain/Loss on Disposal of Assets`.

## 11. Impairment Rules
- If a flood destroys the lobby FF&E, its economic value drops instantly.
- The Director of Finance executes an Impairment run. The system writes down the NBV to the new Recoverable Amount and posts an immediate `Impairment Loss` to the P&L. Future depreciation schedules are automatically recalculated based on the new, lower carrying value.

## 12. Reporting Requirements
1. **Fixed Asset Roll-Forward:** The ultimate audit document. `Opening Balance + Additions - Disposals +/- Transfers - Depreciation = Closing Balance`.
2. **CapEx vs Budget Report:** Tracks CIP spending against approved renovation budgets.
3. **Impairment Log:** Details historical write-downs.

## 13. Audit Requirements
- Every manual change to an asset's Useful Life or Salvage Value must log the user ULID, timestamp, and a mandatory reason code. Changing a useful life from 5 to 10 years artificially halves the monthly depreciation expense, which is a classic fraud vector to inflate current-period profits.

## 14. Risks
- **The "Expense Hiding" Exploit:** A maintenance manager routinely buys $900 AC parts and expenses them. Over a year, they effectively rebuild the entire $10,000 AC system, bypassing the CapEx budget and destroying the operational P&L. The Capitalization Threshold must include logic to flag highly repetitive purchases of similar components.
- **Orphaned CIP:** A $500k restaurant renovation finishes in June, but the Finance Director forgets to "Put it into Service." The $500k sits in CIP and does not depreciate for 6 months. In December, external auditors discover the error, forcing a massive 6-month catch-up depreciation hit that wipes out the hotel's Q4 profit.

## 15. Advantages
- True IFRS/GAAP compliance for long-term capital structures.
- Prevents massive CapEx purchases from distorting operational department (F&B/Rooms) performance metrics.
- Seamlessly handles multi-month hotel renovations via CIP workflows.

## 16. Trade-Offs
- High administrative burden during Accounts Payable invoice matching. AP Clerks must correctly decide whether an invoice line item belongs to operational expense, CIP, or direct CapEx.

## 17. Consequences
- The database schema must separate the `FixedAssetRegister` from the `InventoryLedger` entirely, as they follow fundamentally different valuation and depletion rules.
- The Accounts Payable / PO module must integrate a `CapitalizationEngine` to route high-value purchases into the correct holding accounts.
