# ADR 020: Recipe & Production Yield Accounting

## 1. Title
ADR-020: Recipe & Production Yield Accounting

## 2. Status
Proposed

## 3. Context
Following the establishment of the Inventory Ledger, Cost Ledger, and Variance Governance architectures (ADRs 008-014), IVORQ must tackle the most volatile, complex, and high-risk domain in hospitality finance: the Kitchen. Food Cost is the most critical KPI in a hotel. Raw materials (e.g., whole salmon, bulk flour) are rarely sold in their purchased state. They are butchered, batched, baked, and yielded into finished goods (e.g., salmon fillets, bread). Without a rigorous architecture governing how physical quantities convert and how financial values transfer during production, "Theoretical Food Cost" becomes a fiction, and "Actual Food Cost" becomes an unmanageable black hole of untraceable shrinkage and yield loss.

## 4. Problem Statement
Chefs make mistakes. Recipes change weekly based on seasonality. A 10kg whole fish theoretically yields 6kg of fillets, but a sloppy butcher might only yield 5kg. If the system simply consumes 10kg of whole fish and assumes 6kg of fillets magically appeared, it creates ghost inventory (1kg of fillets that don't physically exist). If the system values the finished fillets using a "Standard Cost" rather than the "Actual AVCO" of the specific raw materials consumed, the Cost Ledger will drift massively from the General Ledger, violating the Subledger Reconciliation Framework (ADR-016).

## 5. Decision
IVORQ will implement an **Actual-Cost Production Engine**. Production Orders will explicitly log `PRODUCTION_OUT` (raw materials consumed) and `PRODUCTION_IN` (finished goods yielded). The actual, real-time AVCO of the consumed raw materials will sum together to establish the new, actual AVCO of the finished good. Yield inefficiencies (e.g., a chef wasting meat) will be fully absorbed into the AVCO of the finished good, rather than expensed immediately as variance, ensuring the true cost of production is passed down to the final POS sale.

## 6. Recipe Principles
1. **Actual Cost Supremacy:** Finished goods are valued based on the *actual* raw materials consumed in that specific batch, not theoretical recipe standards.
2. **Infinite Nesting:** Recipes must support infinite sub-recipe hierarchies (Raw → Prep Batch → Finished Plate).
3. **Immutable Production History:** Once a Production Order is closed, its financial and physical movements are frozen. Subsequent recipe updates do not alter past production runs.
4. **Conservation of Value:** The total financial value of `PRODUCTION_OUT` must exactly equal the total financial value of `PRODUCTION_IN`. Money cannot disappear during cooking.

## 7. Recipe Hierarchy
- **Raw Material:** Base SKU with no recipe (e.g., Flour, Whole Salmon).
- **Sub-Recipe (Prep):** A manufactured SKU (e.g., Pizza Dough, Fish Stock). Has its own AVCO and inventory balance.
- **Finished Good (Menu Item):** The final POS-linked SKU (e.g., Margherita Pizza). 
- **Corporate vs Local:** Recipes are mastered at the Tenant level to ensure brand consistency, but allow `Property-Level Overrides` for localized ingredient substitutions (e.g., local tomatoes vs imported).

## 8. Production Rules
To create prep items or butchered meats, the kitchen executes a **Production Order**.
1. **Declare Input:** Chef scans/issues the raw materials (e.g., 11kg Whole Salmon).
2. **Declare Output:** Chef weighs the final yield (e.g., 5.5kg Fillets).
3. **Execution:** The Inventory Ledger records `PRODUCTION_OUT` (11kg Salmon) and `PRODUCTION_IN` (5.5kg Fillets).

## 9. Yield Rules
- **Standard Yield:** The theoretical output defined in the recipe (e.g., 60%).
- **Actual Yield:** The physical output weighed by the chef (e.g., 50%).
- **Rule:** The system accepts the Actual Yield. The variance between Standard and Actual is logged for the Executive Chef's Yield Variance Report, but mathematically, the Actual Yield determines the inventory reality.

## 10. Waste Rules (By-Products vs True Waste)
- **True Waste:** Dropped food or spoiled ingredients during production. Handled via ADR-014 as an explicit `ADJUSTMENT_OUT` (Spoilage Expense) *before* the production order closes.
- **By-Products (e.g., Salmon Bones for Stock):**
  - *Decision:* By default, the Primary Yield (Fillets) absorbs 100% of the raw material cost. By-products generated from the trim are received into inventory at **$0.00 AVCO**. 
  - *Rationale:* Attempting to arbitrarily assign financial value to fish bones or vegetable peelings creates extreme audit risk and inflates the Balance Sheet with assets that will likely be thrown away. 

## 11. Costing Rules (Actual vs Standard)
- **Standard Cost:** Used *only* for Menu Engineering and predictive pricing. (Theoretical Qty * Current AVCO).
- **Actual Cost:** Used for the Cost Ledger and Inventory Ledger. 
- *Example:* Chef uses 11kg of Salmon (AVCO $10/kg = $110 total) to produce 5.5kg of Fillets. 
- *Math:* The entire $110 is transferred to the Fillets. New AVCO of Fillets = $110 / 5.5kg = **$20/kg**. The cost of the chef's sloppy butchery is permanently baked into the cost of the fillets.

## 12. Cost Ledger Integration
*Ref ADR-012:*
- The `ProductionEngine` emits a transaction.
- The Cost Ledger records:
  - `Cr Inventory Asset - Raw Material` ($110)
  - `Dr Inventory Asset - Finished Good` ($110)
- The net impact on the General Ledger Inventory Control Account is **$0.00**. Production is an asset-transfer event, not an expense event. The expense (COGS) only occurs when the Finished Good is sold or spoiled.

## 13. Banquet Rules
- Banquet Event Orders (BEOs) act as massive Production Orders. 
- If the BEO calls for 500 portions of chicken, the system generates a Production Order for 500 portions. 
- Overproduction (e.g., Chef makes 550 portions) must be explicitly weighed back into inventory as "Prepared Banquet Food" (with a newly established AVCO) or written off as Banquet Spoilage.

## 14. Reporting Requirements
1. **Yield Variance Report:** Compares Actual vs Standard Yield % by Chef/Shift to identify sloppy butchery or theft masked as trim.
2. **Menu Engineering Report (Menu Mix):** Compares Standard Cost vs POS Sale Price (Theoretical Margin).
3. **Actual vs Theoretical (AvT) Food Cost:** Compares what *should* have been consumed based on POS sales against what was *actually* consumed (Inventory Count depletion).

## 15. Audit Requirements
- Every Recipe Version must be immutable. If a recipe changes, it receives a new Version ID. Past Production Orders must retain their foreign keys to the exact Historical Version ID to prove *why* a specific amount of raw material was consumed.
- Massive yield variances (e.g., yielding 10% meat from a fish) must trigger an Approval Engine workflow (ADR-003) requiring the Executive Chef's override to prevent staff from stealing 40% of the fish and claiming it was "bad trim."

## 16. Risks
- **The "Garbage In, Garbage Out" Trap:** If chefs refuse to weigh their output and simply type the "Standard Yield" into the tablet every time, the system will generate ghost inventory that doesn't physically exist, leading to massive shrinkage write-offs at month-end.
- **Runaway AVCO:** If a chef accidentally drops half the raw materials on the floor during production, the finished good will absorb the cost, resulting in an astronomically high AVCO for the finished plate. When sold, the single plate will generate a massive COGS hit, distorting daily flash reports.

## 17. Advantages
- Closes the biggest loophole in hospitality finance (the kitchen black hole).
- Absolute mathematical integrity. Financial value is perfectly conserved across the production process.
- Enables true "Actual vs Theoretical" reporting, the holy grail of F&B management.

## 18. Trade-Offs
- Enforces an incredibly high operational burden on the kitchen. Chefs must actively use tablets to start/stop batches and weigh physical outputs, fundamentally changing kitchen culture.

## 19. Consequences
- The database must support a highly recursive `Recipe` and `RecipeIngredient` schema, with version control tracking.
- The UI must be explicitly designed for hostile kitchen environments (fast, tablet-friendly, grease-proof workflows) to ensure chefs actually enter Actual Yields rather than faking data.
