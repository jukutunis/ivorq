# ADR 018: Foreign Currency Revaluation Strategy

## 1. Title
ADR-018: Foreign Currency Revaluation Strategy

## 2. Status
Proposed

## 3. Context
IVORQ operates as a multi-property hospitality ERP, where properties exist in diverse geopolitical jurisdictions (e.g., Indonesia, Maldives, Europe) but report to a centralized corporate entity (e.g., USA). Hotels frequently procure imported goods (Wine, Seafood) billed in foreign currencies, and they receive payments from International Online Travel Agencies (OTAs like Expedia) in foreign currencies. Because exchange rates fluctuate daily, the value of outstanding Accounts Payable (AP), Accounts Receivable (AR), and Goods Received Not Invoiced (GRNI) liabilities also fluctuates. Failing to govern how these fluctuations are recorded will violate IFRS/GAAP standards and result in massive, unexplainable discrepancies during bank reconciliation and period closing.

## 4. Problem Statement
If a Bali resort (IDR) receives 1,000 EUR of imported wine, the exchange rate on the day of receipt establishes the inventory value. If the AP invoice is paid 45 days later when the EUR has strengthened, the hotel must pay more IDR to clear the 1,000 EUR debt. If the system attempts to stuff this difference into Purchase Price Variance (PPV) or retroactively change the wine's AVCO, the inventory ledger will corrupt, and the F&B Director will be unfairly penalized for Treasury fluctuations. Furthermore, external auditors demand that any unpaid foreign debt at month-end be mathematically revalued to the current market rate before the period closes.

## 5. Decision
IVORQ will implement a strict Multi-Currency Architecture. Inventory (a non-monetary asset) will strictly freeze its valuation at the historical exchange rate of the Receiving date. All monetary liabilities and assets (AP, AR, GRNI) will maintain balances in both the Transaction Currency and Functional Currency. A mandatory Month-End Revaluation Engine will calculate and post **Unrealized FX Gains/Losses** before the period closes. Actual payments will trigger **Realized FX Gains/Losses**. FX Variances are explicitly banned from mixing with PPV.

## 6. Currency Principles
- **Transaction Currency:** The currency of the operational document (e.g., EUR on a Vendor Invoice).
- **Functional (Property) Currency:** The primary legal currency of the specific hotel (e.g., IDR for Bali). All GL and Cost Ledger postings MUST balance in this currency.
- **Reporting (Group) Currency:** The currency used for corporate consolidation (e.g., USD). Handled at the top-tier GL reporting level, outside subledger operations.

## 7. Exchange Rate Governance
- **Spot Rate:** A daily automated feed (e.g., xe.com, central bank API) provides the default spot rate for all daily transactions.
- **Month-End Rate:** A specific, manually confirmed rate utilized exclusively by the Revaluation Engine to value open balances on the last day of the period.
- **Manual Overrides:** Permitted on specific AP Invoices or AR Receipts to match exact bank quotes, but strictly audited.

## 8. Inventory Rules
**Crucial Decision:** Inventory AVCO is **NEVER** revalued due to FX fluctuations. 
- *Rationale:* IFRS/GAAP explicitly defines inventory as a *Non-Monetary Asset*, held at historical cost.
- *Rule:* The Functional Currency AVCO is locked using the Spot Rate on the exact date of Receiving.

## 9. GRNI Rules
- GRNI is a monetary liability. It is accrued in both Transaction Currency and Functional Currency.
- At Month-End, any open GRNI accrued in a foreign currency is revalued using the Month-End Rate. The delta is posted as an Unrealized FX Gain/Loss.

## 10. AP Rules
- **Month-End:** All unpaid foreign AP invoices are revalued. The delta posts to Unrealized FX Gain/Loss.
- **Payment:** When the invoice is finally paid, the system compares the actual bank withdrawal amount (Functional Currency) against the originally booked invoice value. The precise delta is posted as a **Realized FX Gain/Loss**, and any previously booked Unrealized FX is automatically reversed.

## 11. AR Rules
- Same mechanics as AP. Guest folios, corporate accounts, or OTA receivables billed in foreign currencies must be revalued at month-end to accurately reflect the true expected cash value of the asset.

## 12. Cost Ledger Rules
*Ref ADR-012:*
The Cost Ledger is completely shielded from FX volatility. It operates *exclusively* in the Functional Currency of the property. Because Inventory AVCO is locked at historical cost, the Cost Ledger never processes FX adjustments.

## 13. General Ledger Rules
The Chart of Accounts must mandate four distinct GL accounts for currency control:
1. Unrealized FX Gain (P&L - Non-Operating)
2. Unrealized FX Loss (P&L - Non-Operating)
3. Realized FX Gain (P&L - Operating / Finance)
4. Realized FX Loss (P&L - Operating / Finance)

## 14. Period Close Rules
*Ref ADR-013:*
The Financial Period CANNOT transition to `SOFT CLOSED` until the Director of Finance has explicitly executed the "Month-End FX Revaluation Engine." This engine sweeps the AP, AR, and GRNI subledgers, calculates the required unrealized adjustments, and auto-posts the GL journals.

## 15. Reporting Requirements
- **FX Exposure Report:** Shows total open liabilities vs. assets in each foreign currency, helping the Treasury decide if they need to hedge (buy currency forward).
- **Currency Revaluation Report:** A detailed audit trail showing exactly which AP Invoice/GRNI line caused which Unrealized FX GL posting at month-end.

## 16. Audit Requirements
- All exchange rate tables must be immutable. Modifying yesterday's exchange rate is forbidden.
- The Revaluation Engine must lock the rate it uses and tie it to the specific revaluation GL journal entry for external auditor trace-back.

## 17. Hospitality Considerations
- **International OTAs:** Expedia often pays hotels via Virtual Credit Cards (VCCs) in USD, while the hotel operates in EUR. The AR must accrue the VCC commission and FX variance correctly upon swiping the card.
- **Banquet Deposits:** A foreign wedding party may wire a 10,000 EUR deposit 12 months before the event. The Unearned Revenue liability must be revalued monthly until the event is executed.

## 18. Risks
- **Rate Source Manipulation:** If the central exchange rate API fails and users input incorrect rates, the entire P&L will skew heavily.
- **"Double-Dip" FX Errors:** Failing to reverse the Unrealized FX entry when the Realized payment is made will cause the GL to double-count the currency loss.

## 19. Advantages
- Total compliance with IFRS / GAAP standards for foreign currency translation.
- Protects operational department heads (Chefs, Purchasing Directors) from having their Food Cost % destroyed by global macroeconomic currency shifts.

## 20. Trade-Offs
- High systemic complexity. Every AP/AR/GRNI subledger row must dual-track balances (Transaction Currency vs Functional Currency) perpetually.

## 21. Consequences
- The database schema for all monetary subledgers must explicitly include `currency_code`, `exchange_rate`, `foreign_amount`, and `base_amount`.
- A background `RevaluationEngine` service must be built and integrated into the Period Closing gateway.
