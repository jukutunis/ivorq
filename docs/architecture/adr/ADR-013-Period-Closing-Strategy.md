# ADR 013: Period Closing Strategy

## 1. Title
ADR-013: Period Closing Strategy

## 2. Status
Proposed

## 3. Context
Following the establishment of the Inventory, Valuation, GRNI, and Cost Ledger architectures (ADRs 008-012), IVORQ must define the temporal boundaries of these systems. In enterprise hospitality accounting, financial months must be finalized to produce P&L statements, Balance Sheets, and tax filings. If historical transactions could be indefinitely modified or backdated, these financial statements would be rendered legally and operationally useless. A rigorous Period Closing Strategy guarantees the integrity of statutory reporting.

## 4. Problem Statement
Hospitality operates 24/7, making the chronological end of a month blurry (e.g., a Banquet running until 3:00 AM on the 1st of the new month). Physical realities, such as late-arriving vendor invoices or multi-day physical stock counts, inevitably cross calendar boundaries. Without strict systemic rules governing how late or backdated transactions interact with locked financial periods, the General Ledger and Cost Ledger will permanently drift, and external auditors will fail the system.

## 5. Decision
IVORQ will implement an independent, multi-property Financial Period architecture with strict state transitions (`OPEN`, `SOFT CLOSED`, `CLOSED`). The Cost Ledger and General Ledger will absolutely reject any backdated financial posting targeting a `CLOSED` period. Any late-arriving operational transaction will record its historical physical date but will post its financial impact exclusively to the currently `OPEN` period.

## 6. Period Principles
1. **Financial Immutability:** A `CLOSED` period is a legally frozen snapshot. No financial transaction may alter it.
2. **Posting Date Supremacy:** The system `posting_date` dictates the financial period, regardless of the operational `transaction_date`.
3. **Sub-ledger Alignment:** The Inventory, Cost, and AP sub-ledgers must close synchronously with the GL to prevent reconciliation drift.
4. **Independent Properties:** In a multi-property tenant, Property A and Property B manage their period closures completely independently.

## 7. Period Types
To simplify the architecture while maintaining control, IVORQ will use a unified Financial Period mapped at the Property level, containing sub-module gates:
- **Operations/Inventory Gate:** Controls physical receiving, issuing, and counting.
- **AP/Finance Gate:** Controls invoice matching and GL journal entries.

## 8. Period States
1. **`OPEN`**: Normal operations. All transactions permitted.
2. **`SOFT CLOSED`**: The Operations/Inventory Gate is locked. Warehouse staff cannot post new receipts or issues into this month. The AP/Finance Gate remains open for the Finance team to process late invoices, accruals, and month-end adjustment journals.
3. **`CLOSED`**: All gates locked. The financial month is legally finalized.

## 9. Late Transaction Rules
- **Late Receiving:** If goods arrived on March 31 but the warehouse attempts to enter the receipt on April 3 (when March is `SOFT CLOSED` or `CLOSED`), the system records `transaction_date = March 31` but forces `posting_date = April 3`. The Inventory Asset and GRNI liability hit April.
- **Late AP Invoice:** If a receipt was posted in March, GRNI is accrued in March. If the invoice arrives in April, the AP match occurs in April. The original March GRNI remains untouched; the clearance and any PPV occur entirely in April.

## 10. Reopen Governance
- **Can periods be reopened?** Yes, but strictly as an exceptional event.
- **Who approves?** A Property Director of Finance (DoF) cannot reopen a `CLOSED` period. It requires elevated multi-tenant approval (e.g., Regional/Group DoF or System Administrator).
- **Audit Trail:** Every reopen action demands a mandatory reason code and creates an immutable audit log entry (User, Timestamp, Reason, Duration).

## 11. Inventory Count Governance
- Physical counts often bridge month-end (e.g., starting March 31, finishing April 2).
- **Rule:** The March period remains `OPEN` (or un-finalized) until the count is posted. Count variances must be posted with a `posting_date` within March to accurately reflect March COGS. Once the count is posted, the Director of Finance immediately triggers the `SOFT CLOSE`.

## 12. Cost Ledger Impact
*Ref ADR-012:* The Cost Ledger engine listens to the `posting_date`. If a user enters a backdated physical movement into a `CLOSED` operational period, the Cost Engine will identify the mismatch, adopt the current open period's `posting_date`, and apply the AVCO valuation as it stands *today*, protecting historical COGS.

## 13. General Ledger Impact
The General Ledger blindly trusts the Cost Ledger and AP modules. It will outright reject any journal entry attempt carrying a `posting_date` that falls within a `CLOSED` GL period.

## 14. Multi Property Rules
Each Property maintains its own `gl_financial_periods` table (or property-indexed rows). A central warehouse can close its period on the 1st of the month, while a satellite resort might leave its period `SOFT CLOSED` until the 5th. Intercompany transactions between the two will hit whichever period is actively open for the respective receiving/sending property.

## 15. Audit Requirements
External auditors require:
- A `period_state_logs` table tracking exactly when a period changed state and by whom.
- A report of all transactions where `transaction_date` differs from `posting_date` across a period boundary (e.g., physical March, financial April) to audit cut-off accuracy.

## 16. Hospitality Considerations
- **Month-End Banquet:** A banquet ending at 2:00 AM on April 1st operationally belongs to the March 31st business day. The PMS Night Audit controls this boundary. IVORQ will align its posting dates to the *Operational Business Date* determined by the PMS, rather than the literal server clock, until the Night Audit rolls the date.
- **Emergency Purchases:** Petty cash purchases on a weekend must be entered when Finance returns on Monday. If the month closed on Sunday, the expense must land in the new month.

## 17. Risks
- **Cut-Off Errors:** Operational staff forcing `posting_dates` into the next month to manipulate current-month food costs.
- **Night Audit Synchronization:** If the PMS Night Audit fails or is delayed, IVORQ's definition of "Today's Business Date" will drift from the physical calendar, potentially misaligning month-end automated closures.

## 18. Advantages
- Absolute statutory compliance for external audits.
- Eliminates "moving target" P&L reports. Once published, the numbers are locked.
- Provides a clean administrative window (`SOFT CLOSED`) for Finance to work without operational interference.

## 19. Trade-Offs
- High administrative friction. If an inventory clerk forgets to enter a receipt before the `SOFT CLOSE`, they must escalate to Finance to either reopen the period or accept the cost hit in the subsequent month.

## 20. Consequences
- The system must build a centralized `FinancialPeriodService` that acts as an interceptor/middleware for *every* write operation in the Inventory, Cost, and Finance modules.
- The UI must clearly display the "Active Financial Period" and "Active Business Date" to all operational users to prevent confusion.
