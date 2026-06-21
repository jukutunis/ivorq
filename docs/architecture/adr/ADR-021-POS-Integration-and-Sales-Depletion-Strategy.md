# ADR 021: POS Integration & Sales Depletion Strategy

## 1. Title
ADR-021: POS Integration & Sales Depletion Strategy

## 2. Status
Proposed

## 3. Context
Following the establishment of the Cost Ledger (ADR-012), Variance Governance (ADR-014), and Production Yield Accounting (ADR-020), IVORQ must define the final terminus of the hospitality supply chain: the Point of Sale (POS). Every day, thousands of transactions flow through the hotel's POS terminals (e.g., Micros, Simphony, Revel). Each sale represents the physical consumption of inventory and the realization of Cost of Goods Sold (COGS). Without a rigorous integration strategy, the gap between what the POS reports as sold and what the Kitchen physically consumes will create massive, untraceable variances, destroying the accuracy of the hotel's Food & Beverage profitability metrics.

## 4. Problem Statement
Hospitality POS systems are revenue-focused, not inventory-focused. A bartender pouring a "Mojito" does not execute an inventory issue for 2oz of rum. A guest eating at a breakfast buffet consumes an unknowable quantity of eggs per POS swipe. If IVORQ attempts to blindly "back-flush" (auto-deplete) inventory for every POS button pressed, it will generate massive ghost inventories for buffets and corrupt the AVCO math. Furthermore, operational realities like comped meals, dropped plates (voids), and combo menus require distinct financial routing to prevent COGS from being incorrectly penalized or hidden.

## 5. Decision
IVORQ will implement a hybrid **Sales Depletion & Direct Issue Architecture**. À la carte POS sales will trigger automated **"Back-Flushing" (Recipe Explosion)** to deplete raw materials dynamically. Buffets and Banquets will be strictly excluded from POS depletion, relying instead on physical Direct Issues (Requisitions) and actual consumption counts. Voids and Comps will route COGS to specific GL expense accounts (Waste / Entertainment) to protect the purity of the baseline Food Cost %.

## 6. POS Principles
1. **Revenue drives Theoretical; Reality drives Actual.** POS sales generate Theoretical COGS. Physical counts generate Actual COGS. The delta is the primary management KPI.
2. **Back-Flushing is an Estimate.** Recipe explosion assumes perfect execution (no over-pouring). It is a systemic approximation until the physical count proves otherwise.
3. **Buffets Defy Math.** Buffet and Banquet POS keys generate $0.00 in auto-depletion. Their costs are managed purely via bulk kitchen issues.
4. **COGS Follows the Plate.** The location where the POS ticket was rung up must map to the specific physical location (Outlet Store) from which the inventory is depleted.

## 7. Sales Depletion Rules
When a POS transaction is finalized:
- The IVORQ API receives the Product Mix (PMIX).
- The Depletion Engine identifies the mapped Recipe.
- The Engine creates an `ISSUE_SALES` inventory movement.
- **Cost Ledger:** Posts `Dr COGS (Department) | Cr Inventory Asset (Outlet Store)` using the current AVCO of the depleted ingredients.

## 8. Recipe Explosion Rules
*Ref ADR-020:*
- When a "Mojito" is sold, the engine explodes the recipe into its components (Rum, Lime, Mint, Soda).
- **The Stop Rule:** Explosion stops at the *first physically tracked SKU*. If the kitchen batches "Margarita Mix" via a Production Order, the POS depletion stops at the "Margarita Mix" SKU. It does *not* explode down to the raw tequila, because the tequila was already consumed during the Production Order.

## 9. Buffet Rules
- POS rings up "1x Adult Breakfast Buffet." 
- Revenue is recorded. **Zero inventory is depleted.**
- **Costing:** The kitchen issues 50kg of bacon to the Buffet Location via a physical Requisition. At 11:00 AM, 10kg of bacon is returned to the kitchen. The Net Issue (40kg) represents the Actual COGS for the buffet.

## 10. Banquet Rules
- Identical to Buffets. BEO sales do not back-flush recipes. The cost is derived purely from the bulk Production Orders (ADR-020) and Direct Issues dedicated to that specific Banquet Event.

## 11. Complimentary Rules
- Manager comps a $150 Tomahawk Steak for a VIP.
- The steak was cooked and consumed. Inventory must be depleted.
- **Cost Ledger:** Instead of routing to standard COGS, the engine routes the cost to `Dr Complimentary/Entertainment Expense | Cr Inventory Asset`. This ensures the Executive Chef's Food Cost % isn't punished for the General Manager's PR decisions.

## 12. Refund & Void Rules
- **Pre-Make Void:** Waiter rings a steak, instantly voids it. (No inventory impact).
- **Post-Make Void (Return):** Guest eats one bite, hates it. Waiter voids the check.
  - The inventory was physically destroyed. The POS void message must trigger a prompt or auto-mapping to a `WASTE_ISSUE` movement.
  - **Cost Ledger:** `Dr Spoilage/Waste Expense | Cr Inventory Asset`.

## 13. Cost Ledger Integration
*Ref ADR-012:*
Every POS depletion event generates an asynchronous message to the Cost Ledger. To prevent paralyzing the database during peak dinner service (ADR-017), POS tickets can be aggregated (e.g., hourly batches or Night Audit bursts) to post summarized `ISSUE_SALES` journals, unless real-time flash reporting is strictly enabled by the property.

## 14. Reporting Requirements
1. **Actual vs Theoretical (AvT) Report:** The holy grail of F&B. Compares `(Opening Stock + Purchases - Closing Stock)` against `(POS Sales * Recipe Quantities)`. Identifies exactly where bartenders are over-pouring or chefs are stealing.
2. **Menu Engineering (Menu Mix):** Analyzes POS velocity vs Standard Cost margin to categorize items as Stars, Plowhorses, Puzzles, or Dogs.
3. **Comp & Void Variance Report:** Highlights managers abusing comp privileges to mask kitchen errors.

## 15. Audit Requirements
- The POS Mapping Engine must maintain a strict, immutable log of which POS PLU ID mapped to which IVORQ Recipe ID at the exact time of the sale. If a mapping is changed mid-month, historical depletions must not recalculate.
- Negative inventory generated by back-flushing (e.g., selling 50 steaks when the system thinks we have 0) must trigger an audit alert, utilizing the "Last Known AVCO" rule defined in ADR-010.

## 16. Risks
- **Mapping Decay:** If the POS manager creates a new "Summer Cocktail" button but forgets to map it to an IVORQ recipe, sales will generate revenue but zero COGS. Theoretical food cost will appear artificially amazing, while Actual counts will show massive "unexplained" shrinkage.
- **Over-Pouring & Negative Stock:** Bartenders notoriously over-pour. The POS assumes a 1.5oz pour. The bartender pours 2.0oz. The system will eventually hit zero stock while the physical bottle is already empty, destroying the AvT metric if counts are not performed weekly.

## 17. Advantages
- Isolates controllable kitchen waste (Actual) from systemic assumptions (Theoretical).
- Protects the P&L by correctly routing comps, voids, and buffet consumption to their respective GL accounts.
- Scales infinitely across multi-outlet resort properties.

## 18. Trade-Offs
- Enforces extreme administrative maintenance. The F&B Cost Controller must relentlessly audit the POS-to-Recipe mapping matrix every time the menu changes.

## 19. Consequences
- The development team must build a resilient `POSPollingEngine` capable of ingesting high-volume PMIX data via APIs (or flat files) from diverse POS vendors (Oracle Simphony, InfoGenesis).
- A "Mapping Exception Dashboard" is mandatory to loudly alert users when unmapped POS PLUs are sold.
