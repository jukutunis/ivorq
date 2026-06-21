# ADR 025: Revenue Recognition & Tax Engine

## 1. Title
ADR-025: Revenue Recognition & Tax Engine

## 2. Status
Proposed

## 3. Context
Following the establishment of the Cost Ledger (ADR-012) and POS Sales Depletion (ADR-021), IVORQ controls exactly when and how expenses (COGS) hit the General Ledger. However, the Income Statement is only half complete. In enterprise hospitality, the timing of cash collection rarely aligns with the delivery of service. Guests pay massive deposits months in advance for banquets, OTA partners prepay for multi-night stays, and gym members pay annual fees upfront. Furthermore, every transaction is layered with complex Tax and Service Charge distributions. Without a rigorous, IFRS-compliant Revenue Recognition Engine, the hotel will illegally recognize cash as income, violating international accounting standards and massively distorting financial performance.

## 4. Problem Statement
If IVORQ records a $10,000 wedding deposit in January as "Banquet Revenue," the January P&L will show an artificial massive profit, while July (when the wedding actually occurs and the food is consumed) will show a massive loss (COGS with zero revenue). If POS transactions indiscriminately post the gross ticket amount ($121) to Revenue, the hotel will pay management/franchise fees on the $10 VAT and the $11 Service Charge, bleeding cash. Furthermore, external auditors actively hunt for "Cut-Off Errors"—situations where a hotel manipulates its P&L by holding periods open to drag next month's revenue into this month to hit budget targets.

## 5. Decision
IVORQ will implement an **Accrual-Based Revenue Recognition & Tax Engine** strictly compliant with IFRS 15. The core principle is "Cash is not Revenue." Revenue is recognized exclusively when control of the good or service transfers to the customer. Deposits and prepaid memberships will be quarantined into **Unearned Revenue (Deferred Liabilities)**. Tax and Service Charges will be automatically stripped from the gross transaction value and posted directly to Liability accounts. The engine will rely heavily on the PMS Night Audit as the absolute chronological boundary for recognizing daily room revenue.

## 6. Revenue Recognition Rules
1. **F&B Revenue (POS):** Recognized at the exact moment the POS check is closed (or posted to a room folio). The transfer of goods is immediate.
2. **Room Revenue:** Recognized incrementally via the PMS **Night Audit**. A 5-night prepaid stay recognizes 1/5th of the revenue each night. The revenue belongs to the business date it was slept in, regardless of when it was paid or billed.
3. **Banquet Revenue:** Recognized on the exact day of the Event Execution. 
4. **Membership Revenue:** Annual upfront payments (e.g., Spa Memberships) trigger an automated amortization schedule, recognizing 1/12th of the revenue during each Period Close (ADR-013).
5. **Cancellations:** If a non-refundable deposit is forfeited, the liability transfers to a specific `Cancellation Fee Revenue` account. It MUST NOT be recorded as Room Revenue, as this would falsely inflate Occupancy % and RevPAR (Revenue Per Available Room) KPIs.

## 7. Tax Rules
- **Gross vs Net:** Revenue is always recorded **NET of Tax**.
- **Execution:** When a $110 transaction occurs ($100 Food + $10 VAT):
  - `Dr Guest Ledger/Cash $110`
  - `Cr F&B Revenue $100`
  - `Cr VAT Payable (Liability) $10`
- **Exemptions:** The Tax Engine must support dynamic tax rules based on guest profile (e.g., Diplomats or NGOs exempt from VAT).

## 8. Service Charge Rules
- In many jurisdictions (Asia/Europe/Middle East), a mandatory Service Charge (e.g., 10%) is added to the bill.
- **Rule:** Service Charge is collected on behalf of the employees. It is **NOT** hotel revenue.
- **Execution:** Posted directly to `Cr Service Charge Payable (Liability)`. 

## 9. Deposit & Deferred Revenue Rules
- Any cash received prior to the delivery of service (e.g., OTA prepayments, Banquet deposits) is recorded strictly as:
  - `Dr Cash | Cr Unearned Revenue (Liability)`.
- *Ref ADR-018:* If the deposit is received in a foreign currency, the Unearned Revenue liability must be revalued at month-end until the service is provided and the revenue is recognized at the spot rate of the service date.

## 10. Reporting Requirements
1. **Unearned Revenue Aging Report:** Details all deposits held by the hotel, grouped by expected realization date (essential for cash flow forecasting).
2. **Tax Liability Ledger:** Proves to the government exactly how much VAT/GST was collected versus how much was paid on purchases (Input/Output VAT).
3. **Daily Revenue Report (Flash):** Summarizes Net Revenue, Tax, and Service Charge for the previous business day.

## 11. Audit Requirements
- **Cut-Off Integrity:** The system must cryptographically log the exact timestamp the PMS Night Audit fired. Any revenue recognized after the Night Audit rolls the date must strictly apply to the *new* business date.
- **Amortization Traceability:** Every $100 monthly membership recognition must link back to the original $1,200 master payment document.

## 12. Risks
- **Night Audit Failures:** If the PMS Night Audit crashes or is delayed until 10:00 AM the next day, the Revenue Engine will stall. If the hotel manually pushes transactions to keep operating, revenue might bleed across the midnight boundary, causing Cut-Off audit failures.
- **Multi-Tax Overlaps:** A transaction might be subject to 10% VAT, a 5% City Tax, and a 10% Service Charge. If the calculation is compounding (Tax on top of Service Charge) vs flat, rounding errors across thousands of transactions will cause the GL to drift from the physical cash collected.

## 13. Advantages
- Absolute compliance with IFRS 15 (Revenue from Contracts with Customers).
- Protects the hotel from paying management franchise fees (usually calculated on Gross Revenue) on Taxes and Service Charges.
- Ensures the P&L accurately reflects the operational performance of the specific month, free from cash-flow timing distortions.

## 14. Trade-Offs
- Forces a complex subledger dependency. The GL Revenue accounts are completely locked down; Finance cannot manually post a journal entry to "Room Revenue" to fix an error—they must fix the underlying PMS folio to trigger the automated recognition event.

## 15. Consequences
- The database requires a robust `TaxMatrix` and `ServiceChargeEngine` capable of intercepting and splitting every POS and PMS transaction before it reaches the General Ledger.
- The Period Closing Engine (ADR-013) must ensure the automated Deferred Revenue Amortization jobs have executed successfully before allowing a `SOFT CLOSE`.
