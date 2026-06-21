# ADR 024: Intercompany Consolidation & Elimination Engine

## 1. Title
ADR-024: Intercompany Consolidation & Elimination Engine

## 2. Status
Proposed

## 3. Context
As established in ADR-023, IVORQ supports multi-entity corporate structures where individual properties operate as distinct legal entities, executing legally binding intercompany sales (with Transfer Pricing markups). While this is legally required at the subsidiary level, international accounting standards (IFRS 10 / ASC 810) mandate that a parent company must present consolidated financial statements as if the entire group is a single economic entity. A hotel group cannot artificially inflate its global revenue or pad its balance sheet by endlessly buying and selling wine between its own properties. IVORQ must possess an engine capable of mathematically stripping out these internal transactions to reveal the true financial reality of the Group.

## 4. Problem Statement
Consolidation is not a simple addition problem (`Group = Property A + Property B`). If Property A (cost $80) sells wine to Property B (transfer price $100), Property A recognizes $20 profit, and Property B holds inventory at $100. If that wine sits unsold at month-end, simply adding their balance sheets results in the Group inflating its total inventory value by $20 and recognizing $20 of "Unrealized Profit." Furthermore, if Property A operates in IDR and Property B operates in SGD, translating both to a USD Group reporting currency creates massive Cumulative Translation Adjustments (CTA). Without a dedicated, automated Elimination Engine, the CFO's team will spend weeks in Excel, risking catastrophic audit failures.

## 5. Decision
IVORQ will implement an automated **Intercompany Consolidation & Elimination Engine**. The architecture will utilize a dedicated, virtual `ELIMINATION_ENTITY` at each level of the consolidation hierarchy. Instead of deleting subsidiary transactions, the engine will auto-generate explicit, auditable "Elimination Journal Entries" inside the Elimination Entity to wash out Intercompany Payables/Receivables, Revenues/Expenses, and Unrealized Inventory Profits. Consolidation will utilize strict IFRS translation rules (Average Rate for P&L, Closing Rate for Balance Sheet), automatically calculating Non-Controlling (Minority) Interest for partial ownerships.

## 6. Consolidation Principles
1. **Additive Transparency:** Group Balance = Sum(Subsidiaries) + Sum(Elimination Entities). Subsidiary ledgers are never altered by the consolidation process.
2. **Economic Reality:** A Group cannot make profit from itself. All internal markups sitting in unsold inventory must be purged.
3. **Currency Strictness:** Translation from Functional Currency to Group Reporting Currency must strictly delineate between P&L translation (Average rates) and Balance Sheet translation (Closing rates).
4. **Hierarchical Rollup:** Supports nested sub-groups (e.g., Bali Region consolidates first, then rolls up to APAC Region).

## 7. Elimination Rules
- **Due To / Due From (Balance Sheet):**
  - Elimination Entry: `Dr Intercompany AP (Due To) | Cr Intercompany AR (Due From)`. Net Group balance = 0.
- **Revenue / Expense (P&L):**
  - Elimination Entry: `Dr Intercompany Revenue | Cr Intercompany COGS`. Net Group P&L impact = 0.
- **Unrealized Profit in Inventory:**
  - *Scenario:* Wine sold internally for $100 (original cost $80) sits unsold in Property B.
  - *Requirement:* The Transfer Ledger (ADR-023) must pass the "Original Group Cost" alongside the Transfer Price.
  - Elimination Entry: `Dr Retained Earnings (or COGS) $20 | Cr Inventory Asset $20`. This returns the Group inventory value back to the true external cost of $80.

## 8. Currency Rules (Translation to Group USD)
*Ref ADR-018:*
- **P&L Accounts (Revenue/Expense):** Translated using the **Average Exchange Rate** for the month.
- **Balance Sheet (Assets/Liabilities):** Translated using the **Closing Spot Rate** on the last day of the month.
- **Equity:** Translated at the **Historical Rate**.
- **CTA:** Because the P&L and Balance Sheet are translated at different rates, the Trial Balance will no longer equal zero. The balancing figure is auto-posted to a specific Equity account: `Cumulative Translation Adjustment (CTA)`.

## 9. Ownership Rules (Minority Interest)
- If the Group owns 100% of Property A, and 80% of Property B.
- Consolidation includes 100% of Property B's Revenue, Assets, and Liabilities (assuming the Group exercises control).
- The Engine automatically extracts the 20% external ownership into:
  - `Non-Controlling Interest (Liability/Equity)` on the Balance Sheet.
  - `Income Attributable to Non-Controlling Interest` on the P&L.

## 10. Reporting Requirements
1. **Consolidating Trial Balance:** A massive matrix report showing rows of GL accounts, columns for each Property, a column for Eliminations, and a final column for the Group Consolidated Total.
2. **Intercompany Mismatch Report:** Flags any scenario where Property A's "Due From B" does not exactly match Property B's "Due To A" prior to elimination.
3. **Unrealized Profit Reserve Report:** Details exactly which SKUs are driving the $20 inventory elimination.

## 11. Audit Requirements
- The Elimination Entity must operate like a standard GL. Every elimination journal entry must have a trace ID linking it back to the exact subledger transfer (ADR-023) that necessitated the elimination.
- Big Four auditors will demand complete drill-down capability: Clicking a $1M Group Revenue number must unfold into $600K (Prop A) + $500K (Prop B) - $100K (Elimination).

## 12. Risks
- **The Transit Timing Imbalance:** If goods are in `IN_TRANSIT` at month-end, Property A has recognized the Intercompany AR/Revenue, but Property B hasn't recognized the AP/Inventory (Ref ADR-023). When the Elimination Engine attempts to wash the AR against the non-existent AP, it will fail, leaving an orphaned balance. 
- **Transfer Pricing Obfuscation:** If Property B transforms the received wine into a "Sangria Batch" (ADR-020), tracking that $20 of original unrealized profit through the recipe explosion engine becomes an extreme mathematical challenge.

## 13. Advantages
- Elevates IVORQ from a "Property Management System" to a true "Enterprise Financial ERP" capable of serving global hotel groups.
- Satisfies IFRS and GAAP consolidation standards out-of-the-box.
- Drastically accelerates the CFO's month-end reporting timeline by eliminating manual Excel consolidation.

## 14. Trade-Offs
- Massive computational load during the Period Close window. The system must generate thousands of elimination journals and translate millions of rows across multiple exchange rates simultaneously.

## 15. Consequences
- The General Ledger schema must explicitly support a `ConsolidationEntity` type, which behaves like a property but only accepts system-generated elimination journals.
- The `ChartOfAccounts` requires an explicit flag for `Is_Intercompany_Eliminating` to instruct the engine which accounts to sweep.
