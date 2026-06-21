# ADR 019: Payment & Bank Reconciliation Engine

## 1. Title
ADR-019: Payment & Bank Reconciliation Engine

## 2. Status
Proposed

## 3. Context
Following the establishment of the core ledgers, multi-currency strategies, and reconciliation frameworks (ADRs 008-018), IVORQ must define its ultimate financial gateway: Cash. The Payment & Bank Reconciliation Engine bridges the Accounts Payable (AP), Accounts Receivable (AR), and General Ledger (GL) modules with external physical reality (Bank Statements). Hospitality operations involve massive volumes of multi-currency OTA receipts, grouped vendor payments, and relentless bank fees. Without a rigid, highly-governed payment architecture and a bulletproof reconciliation engine, the system is exposed to extreme fraud, uncontrollable FX variance, and catastrophic audit failures.

## 4. Problem Statement
If IVORQ allows flexible, unstructured N-to-N matching between AP Invoices and Bank Statement Lines, the reconciliation process will devolve into a chaotic, untraceable web of fractional matches. Furthermore, foreign currency payments naturally induce bank fees and FX slippage; if these are not strictly apportioned during the payment settlement event, the GL Cash accounts will permanently drift from the physical bank balance, triggering the Subledger Reconciliation Framework (ADR-016) to hard-block period closures. 

## 5. Decision
IVORQ will implement a strict **Two-Tiered Payment & Reconciliation Architecture**. The first tier is the **Payment Allocation Engine**, which allows N-to-N allocation between Payment Documents and AP/AR Invoices. The second tier is the **Bank Reconciliation Engine**, which strictly enforces **1-to-1 matching** between a System Bank Transaction and a physical Bank Statement Line. Realized FX and Bank Fees will be mathematically resolved and locked *during the Payment Allocation phase*, ensuring the System Bank Transaction perfectly matches the net value on the bank statement.

## 6. Payment Principles
1. **Two-Tier Separation:** Invoice Allocation (N-to-N) is entirely separate from Bank Reconciliation (1-to-1).
2. **Absolute Segregation of Duties:** The user who authorizes an AP Payment cannot be the user who reconciles the Bank Statement.
3. **Cash Finality:** A payment document cannot be altered or deleted once it is matched to a bank statement line.
4. **Zero-Tolerance Allocation:** The total value of a payment must be perfectly distributed across Invoices, Fees, FX, and Unallocated Credits. No fractional pennies may disappear.

## 7. AP Rules
- **Multiple Invoice Settlements:** A single "Payment Batch" can settle 50 AP Invoices for the same vendor.
- **Partial Payments:** Allowed. The AP Subledger maintains the `remaining_balance` of the invoice.
- **Overpayments:** Allowed, but strictly governed. The excess amount is logged as an `Unallocated Vendor Credit` which can be applied to future invoices.

## 8. AR Rules
- **OTA Grouped Receipts:** A single bank wire from Expedia might clear 300 individual guest folios/AR invoices. The AR Receipt document handles this N-to-N allocation.
- **Short Pays (Underpayments):** If a corporate client pays $95 instead of $100, the AR Receipt must explicitly classify the $5 delta as a specific write-off (e.g., Bank Fee, Dispute, or Bad Debt) to close the invoice.

## 9. Bank Account Rules
- Bank Accounts map directly 1-to-1 to GL Cash Control Accounts.
- Each Bank Account has a single strictly defined Currency. 
- Multi-currency receipts must undergo cross-currency triangulation at the exact Spot Rate applied by the bank at the moment of deposit.

## 10. Allocation Rules
The mathematical allocation of a Payment must equal:
`Total Bank Withdrawal/Deposit = Sum(Invoice Base Values) + Sum(Bank Fees) +/- Sum(Realized FX Variance)`

## 11. Reconciliation Rules (ADR-007 Verdict)
**Decision: Bank Reconciliation REMAINS strictly 1-to-1.**
- *Review:* ADR-007 deleted `commitSplit` and `commitMerge` from the `ReconciliationCommitService` because the database enforces strict unique constraints.
- *Justification:* If 10 invoices are paid in a single bank transfer, the physical bank statement shows **one** line. To reconcile this, IVORQ generates **one** `SystemBankTransaction` (the Payment Batch). The Bank Reconciliation Engine then cleanly matches 1 `BankStatementLine` to 1 `SystemBankTransaction`. Introducing N-to-N matching at the bank reconciliation layer destroys auditability, makes automated matching impossible, and breaks the fundamental premise of a control account.

## 12. FX Settlement Rules
*Ref ADR-018:*
When an AP Invoice (EUR) is paid via a Bank Account (IDR):
1. The Payment Allocation Engine consumes the actual IDR value withdrawn from the bank.
2. It translates the cleared EUR invoice value into IDR at the historical invoice rate to determine the baseline.
3. The delta is posted immediately as a **Realized FX Gain/Loss** GL journal.
4. Any previously booked Unrealized FX is flagged for reversal.

## 13. Fraud Controls
- **"Ghost Vendor" Protection:** The system hard-blocks payments to vendors that lack verified, active bank details approved by the Director of Finance.
- **Approval Engine:** Payment Batches > $X threshold require multi-signature digital approval before the system generates the bank export file (e.g., ACH/SEPA).
- **Immutable Statements:** Uploaded MT940 / CSV bank statements are immutable. No user can edit or delete a statement line.

## 14. Audit Requirements
- The Bank Reconciliation report must provide a cryptographic or digitally signed snapshot at month-end, proving that:
  `GL Cash Balance + Unreconciled System Transactions = Physical Bank Statement Balance`.
- All manual reconciliation matches (where the auto-matching engine failed) must log the ULID of the accountant who forced the match.

## 15. Reporting Requirements
1. **Bank Reconciliation Report:** The core external audit document.
2. **Unallocated Cash Report:** Highlighting vendor credits or unassigned guest deposits.
3. **Cash Flow Forecast:** Based on AP Due Dates and AR Expected Receipts.

## 16. Risks
- **Bank Statement Lag:** If physical bank statement data takes days to arrive via API/MT940, the reconciliation process will always lag behind operations, creating a massive end-of-month scramble to match thousands of lines before the period closes.
- **Cross-Currency Triangulation:** If an invoice in EUR is paid from a USD bank account for an IDR hotel, the payment allocation engine must process an incredibly complex double-conversion. Minor rounding errors during this triangulation will cause the payment allocation to fail its zero-tolerance check.

## 17. Advantages
- Total protection against sophisticated cash fraud.
- Substantially reduces the complexity of Bank Reconciliation by keeping it 1-to-1.
- Perfect alignment with the Subledger Reconciliation Framework (ADR-016).

## 18. Trade-Offs
- Forces high administrative friction on the AP/AR teams. They must perfectly apportion bank fees and write-offs *during* the payment entry phase, rather than lazily fixing them later during bank reconciliation.

## 19. Consequences
- The development team must build a highly advanced `PaymentAllocationEngine` capable of absorbing invoices, bank fees, and FX variances into a single transaction object.
- The `ReconciliationCommitService` (ADR-007) is permanently affirmed as a 1-to-1 engine, requiring no further architectural changes.
