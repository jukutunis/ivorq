# ADR 022: Actual vs Theoretical Variance Resolution Framework

## 1. Title
ADR-022: Actual vs Theoretical (AvT) Variance Resolution Framework

## 2. Status
Proposed

## 3. Context
Through ADRs 008 to 021, IVORQ has established a mathematically pure ecosystem for tracking inventory. We know what was purchased (ADR-011), what was produced (ADR-020), and what the POS claims was sold (ADR-021). However, the physical reality of a hotel hotel kitchen or bar is messy. Bartenders over-pour, steaks are dropped on the floor and thrown away without being logged in the system, and expensive wine is stolen. The gap between what the system calculates *should* remain in inventory ("Theoretical") and what is actually counted on the shelf ("Actual") is the ultimate measure of operational control. Without a rigorous, auditable framework for detecting, investigating, and resolving these variances, the hotel will bleed profit while the management remains completely blind to the root causes.

## 4. Problem Statement
If IVORQ simply accepts physical stock counts and automatically "adjusts" the inventory to match, it masks operational failure. If a bar is missing $5,000 of liquor, an auto-adjustment quietly moves that $5,000 to Cost of Goods Sold (COGS), hiding theft inside a generic expense account. Furthermore, if a variance is caused by a sloppy inventory counter or a broken POS recipe mapping (ADR-021), blindly writing off the variance corrupts the financial ledgers permanently. We must enforce a governed workflow that explicitly separates "Explained" operational losses from "Unexplained" shrinkage, forcing accountability onto the department heads.

## 5. Decision
IVORQ will implement an **Actual vs Theoretical (AvT) Variance Engine**. Variances will be strictly classified into **Explained Variance** (logged waste, comps, yield loss) and **Unexplained Variance** (shrinkage, theft, over-pouring). Any stock count resulting in an Unexplained Variance that exceeds predefined tolerance thresholds will be placed in a `PENDING_INVESTIGATION` state. The Cost Ledger will not post the financial write-off until the Department Head provides a Root Cause Analysis and the Director of Finance explicitly approves the resolution. Unresolved variances will hard-block the Period Close (ADR-013).

## 6. Variance Principles
1. **Theoretical is an Estimate; Actual is Reality.** The physical count dictates the final ledger balance. The POS depletion is merely the baseline for comparison.
2. **Guilty Until Proven Innocent.** All physical inventory deficits are classified as "Unexplained Shrinkage" until management provides an audited reason code.
3. **Favorable Variance is a Red Flag.** Using less inventory than theoretically required (e.g., a surplus of vodka) strongly indicates bartenders are under-pouring guests to pocket cash for off-book drinks, or recipes are fundamentally wrong.
4. **Zero Auto-Write-Offs.** Material variances require human investigation and digital signature approval.

## 7. Variance Categories
- **Theoretical Consumption:** POS Sales Qty × Recipe Standard Qty.
- **Actual Consumption:** Opening Stock + Receipts + Transfers In − Transfers Out − Closing Physical Count.
- **Gross Variance:** Actual Consumption − Theoretical Consumption.
- **Explained Variance:** The portion of Gross Variance already logged in IVORQ (e.g., explicit `WASTE_ISSUE`, `COMP_ISSUE`, or Production Yield Variances from ADR-020).
- **Unexplained Variance (Shrinkage):** Gross Variance − Explained Variance. This is the "Black Hole" metric (Theft, Over-pouring, Unrecorded Waste).

## 8. Investigation Workflow
1. **Count Execution:** User submits a physical stock count.
2. **Variance Calculation:** IVORQ instantly compares the count against the Theoretical system balance.
3. **Tolerance Check:** If the Unexplained Variance exceeds the Item Category threshold (e.g., > 1% for Liquor), the count for that specific item is flagged as `OUT_OF_TOLERANCE`.
4. **Recount Mandate:** A blind recount must be performed by a different user.
5. **Investigation:** If the recount confirms the variance, the Department Head (e.g., Bar Manager) must select a Root Cause (e.g., "Unrecorded Breakage", "Theft", "Recipe Mapping Error") and detail Corrective Actions.

## 9. Approval Rules
- **Micro Variances (Within Tolerance):** Auto-approved. The system generates the `ADJUSTMENT_OUT` movement.
- **Material Variances (Out of Tolerance):** Routes via the Approval Engine (ADR-003) to the F&B Director and Director of Finance for digital signature.

## 10. Resolution Rules
- **True Shrinkage/Theft:** Approved write-off. Cost Ledger posts: `Dr Inventory Shrinkage Expense | Cr Inventory Asset`.
- **Recipe/Mapping Error:** If the investigation proves the POS recipe (ADR-021) was wrong (e.g., back-flushing 2oz instead of 1.5oz), the system allows the F&B Controller to correct the recipe. *Crucial Rule:* IVORQ does **not** recalculate historical depletions. The variance is written off to `Dr Recipe Correction Variance | Cr Inventory Asset` to maintain ledger immutability.
- **Count Error:** Count is rejected and overwritten with a correct count.

## 11. KPI Framework
The F&B Dashboard must expose:
- **Shrinkage %:** (Unexplained Variance Cost / Total Outlet Revenue) × 100. Target: < 0.5%.
- **Waste %:** (Explained Waste Cost / Total Outlet Revenue) × 100.
- **AvT Gap:** Theoretical Margin % vs Actual Margin %.

## 12. Reporting Requirements
1. **AvT Exception Report:** Ranks SKUs by highest absolute variance dollar value (e.g., highlighting that 80% of the bar's missing value comes from just 3 premium tequilas).
2. **Department Head Accountability Log:** Tracks how long variances sit in `PENDING_INVESTIGATION`.
3. **Favorable Variance Report:** Highlights items where physical stock mysteriously grew (indicating theft/under-pouring or duplicate receiving).

## 13. Audit Requirements
- External auditors require absolute Segregation of Duties: The user performing the stock count cannot be the user approving the variance write-off.
- The Cost Ledger (ADR-012) must value all variance write-offs at the "Last Known AVCO" to accurately reflect the financial loss at the time of discovery.

## 14. Risks
- **Count Sabotage:** If staff know the tolerance limit is 2%, they may intentionally manipulate the physical count input to stay just under the threshold, successfully hiding 1.9% theft indefinitely without triggering an investigation.
- **Period Close Paralysis:** If department heads are lazy and leave 500 variances in `PENDING_INVESTIGATION`, the month-end close (ADR-013) is hard-blocked. Finance will be tempted to use a "Force Approve All" override to hit reporting deadlines, destroying the entire governance framework.

## 15. Advantages
- Transforms "Food Cost" from a reactive, backward-looking accounting metric into a proactive, investigative operational tool.
- Explicitly isolates uncontrollable market forces (PPV/FX) from controllable operational failures (Waste/Theft).
- Forces accountability down to the specific Outlet Manager level.

## 16. Trade-Offs
- High operational friction. Investigating a $50 missing bottle of rum takes expensive management time. Striking the balance on Tolerance Thresholds is critical to avoid paralyzing the hotel.

## 17. Consequences
- The database requires a dedicated `InventoryVariance` and `VarianceInvestigation` schema tightly coupled to the Stock Count module.
- The Period Closing Engine (ADR-013) must be updated to explicitly query the Variance Engine and reject any `CLOSE` attempt if `PENDING_INVESTIGATION` items exist.
