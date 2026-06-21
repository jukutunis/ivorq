# ADR 014: PPV & Variance Governance

## 1. Title
ADR-014: Purchase Price Variance (PPV) and Variance Governance

## 2. Status
Proposed

## 3. Context
Following the establishment of the Inventory, Valuation, GRNI, Cost Ledger, and Period Closing architectures (ADRs 008-013), IVORQ has a robust framework for recording inventory movements and financial costs. However, physical reality rarely perfectly matches systemic expectations. Vendor prices fluctuate between PO and Invoice (PPV), exchange rates shift (FX Variance), plates are dropped (Waste), and stock goes missing (Shrinkage). Without stringent governance over how these variances are classified, approved, and posted, IVORQ’s General Ledger will balance, but its P&L will hide massive operational inefficiencies, theft, and procurement failures.

## 4. Problem Statement
If IVORQ blindly auto-posts all discrepancies, management loses visibility into profit erosion. If FX Variances are mixed with PPVs, the Purchasing Director is unfairly penalized for Treasury fluctuations. If unexplained shrinkage is mixed with explained spoilage, theft becomes invisible. IVORQ requires an enterprise-grade variance governance architecture to classify, approve, and isolate every cent of discrepancy before it impacts the GL.

## 5. Decision
IVORQ will implement an isolated, strictly categorized Variance Governance framework. PPV will be explicitly separated from FX Variance. Operational variances (Waste/Spoilage) will be segregated from Unexplained Variances (Shrinkage). A systemic Tolerance Engine will automatically approve micro-variances (e.g., rounding pennies, minor PPV) while routing material discrepancies through the Approval Engine (ADR-003) based on rigid thresholds and segregation of duties.

## 6. Variance Principles
1. **Categorical Isolation:** Variances must never be mixed. A pricing error is not a currency error, and a dropped plate is not a stolen bottle.
2. **Accountability Mapping:** Every variance type is owned by a specific department (e.g., Procurement owns PPV; Treasury owns FX; Operations owns Waste).
3. **Threshold Governance:** Immaterial variances are auto-cleared; material variances are hard-blocked pending approval.
4. **Audit Trail Mandate:** Every manual variance adjustment requires a reason code, user ULID, and timestamp.

## 7. PPV Governance
- **Behavior:** Triggered during the AP Three-Way Match when Invoice Cost ≠ Receipt Cost. 
- **Ownership:** The Purchasing/Procurement Department owns PPV, as it reflects PO accuracy and vendor negotiation.
- **Review & Clearance:** If the PPV exceeds the tolerance threshold, the AP Invoice is placed in an `ON_HOLD` status. It routes to the Purchasing Director for approval. Once approved, the variance posts to the PPV Expense account, which clears to the P&L at month-end.

## 8. FX Variance Governance
**Decision: FX Variance must be strictly separated from PPV.**
- **Rationale:** If a hotel buys wine in EUR but operates in USD, the PO and Receipt may be €100 ($110). If the exchange rate shifts before the AP Invoice is paid, the payment might be $115. The €100 price never changed. The $5 difference is an FX Variance, not a PPV.
- **Posting:** FX Variances are owned by Finance/Treasury and post directly to a "Realized/Unrealized FX Gain/Loss" GL account, keeping the Purchasing Director's PPV metrics pure.

## 9. Inventory Variance Governance
When System Stock ≠ Physical Stock:
- **Explained Variance (Spoilage/Waste):** Logged actively during the shift. (e.g., A chef drops a salmon). Posts to `Dr Operational Waste Expense | Cr Inventory Asset`. Owned by the Outlet Manager.
- **Unexplained Variance (Shrinkage):** Discovered during physical stock counts. Posts to `Dr Inventory Shrinkage Expense | Cr Inventory Asset`. Owned by the Director of Operations / General Manager. Requires investigation.

## 10. Rounding Variance Governance
- **Behavior:** Cost allocations (e.g., splitting a $10.00 freight charge across 3 departments) often yield fractional pennies ($3.33 x 3 = $9.99, leaving $0.01).
- **Governance:** The Cost Ledger automatically forces the *final* distribution line to absorb the rounding penny (e.g., $3.34) to ensure the journal entry balances to exactly 0.00. Alternatively, micro-variances below $0.05 are auto-swept to a "Rounding Variance" GL account.

## 11. Write-Off Governance
- Expired, damaged, or obsolete inventory requires a formal `ADJUSTMENT_OUT` movement.
- **Approvals:** Governed by ADR-003 (Approval Engine). For example, write-offs < $50 are auto-approved. Write-offs > $500 require the Director of Finance and General Manager's digital signature before the Cost Ledger will post the credit to the Inventory Asset.

## 12. Tolerance Rules
- **PPV Tolerance:** Configurable per property. Example: Auto-approve PPV if variance is ≤ 2% of total line value OR ≤ $10.00 absolute value.
- **Inventory Variance Tolerance:** Configurable by Item Category. (e.g., Bulk flour may have a 1% count tolerance. Premium Champagne has a 0% tolerance—every missing bottle requires an explanation).

## 13. Month-End Rules
- **Hard Stop:** A Financial Period cannot transition to `SOFT CLOSED` if there are any `ON_HOLD` invoices awaiting PPV approval or unposted Physical Stock Counts.
- **Sweep:** All variance accounts (PPV, FX, Shrinkage, Waste) are P&L nominal accounts. They reflect the month's operational performance and reset for the new period.

## 14. Multi Property Rules
- Variances are absolutely isolated by `property_id`. Property A's P&L and PPV reports must never bleed into Property B. The Tolerance Engine thresholds can be configured differently per property (a luxury resort may have tighter thresholds than a budget brand).

## 15. Reporting Requirements
The system must provide distinct reports to enforce this governance:
1. **PPV Analytics:** By Vendor and by Item (to identify consistently inaccurate vendors).
2. **Waste & Spoilage Report:** By Department and Reason Code.
3. **Shrinkage Report:** Highlighting high-variance SKUs post-count.
4. **Rounding & FX Variance Log:** For the Finance team.

## 16. Audit Requirements
- External auditors require proof of Segregation of Duties: The user executing the physical stock count *cannot* be the user approving the write-off for the missing items.
- The Approval Engine must log the exact timestamp and IP of the manager approving any out-of-tolerance PPV or Shrinkage.

## 17. Hospitality Considerations
- **Mini Bar Shrinkage:** Often exceeds 10% due to guest disputes and unrecorded consumption. This should be mapped to a specific "Mini Bar Cost of Sales" rather than generic "Shrinkage" to avoid triggering false alarms for the F&B Director.
- **Recipe Yield Variances:** When a kitchen butchers a whole fish, the actual yield (usable meat) may vary from the standard recipe. This "Yield Variance" impacts the true AVCO of the finished good and must be tracked to monitor butcher efficiency.

## 18. Risks
- **Tolerance Abuse:** If PPV thresholds are set too loosely, procurement teams may become lazy with PO accuracy, allowing vendors to slowly creep prices up unchecked.
- **Reporting Overload:** If every dropped napkin requires an explicit waste entry, operational staff will bypass the system. Waste logging must be frictionless (e.g., tablet-based POS integration).

## 19. Advantages
- Absolute transparency into profit erosion.
- Fair accountability (Purchasing owns PPV, Operations owns Waste, Finance owns FX).
- Bulletproof audit trails for external compliance.

## 20. Trade-Offs
- Enforcing strict PPV approvals will inevitably delay Accounts Payable from paying invoices on time if Purchasing Directors are slow to review variances.

## 21. Consequences
- The AP Three-Way Match Engine must be updated to integrate with the Approval Engine (ADR-003) to trigger the `ON_HOLD` status for out-of-tolerance invoices.
- The UI must provide a dedicated "Variance Resolution Dashboard" for department heads.
