# ADR 016: Subledger Reconciliation Framework

## 1. Title
ADR-016: Subledger Reconciliation Framework

## 2. Status
Proposed

## 3. Context
IVORQ's architecture correctly distributes transactional volume across specialized subledgers (Inventory, Cost Ledger, AP, AR, GRNI) to protect the General Ledger (GL) from becoming bloated with operational noise. However, distributed architectures are vulnerable to "drift"—scenarios where the sum of the detailed subledger records no longer equals the summary balance in the GL. This drift can occur due to asynchronous queue failures, manual accounting overrides, or edge-case software bugs. To satisfy external auditors and ensure absolute financial integrity, IVORQ must define a rigorous, automated framework to prove that the subledgers and the GL are in perfect harmony.

## 4. Problem Statement
If the GL reports $1,000,000 in Inventory Assets, but the Cost Ledger only contains $950,000 of verifiable SKUs, the Balance Sheet is fraudulent. Without a systemic, forced reconciliation process, Finance teams often resort to "plugging" the difference with blind journal entries at month-end to force the books to balance. This masks operational failures and violates Sarbanes-Oxley (SOX) and international audit standards.

## 5. Decision
IVORQ will enforce a strict, systemic Subledger Reconciliation Framework. The GL "Control Accounts" for all subledgers will be **hard-locked** against manual journal entries. Automated reconciliation reports will execute daily, comparing the mathematical sum of the subledgers against the GL Control Accounts. Crucially, a Financial Period (ADR-013) will be absolutely prohibited from closing if any reconciliation variance exists.

## 6. Reconciliation Principles
1. **Zero Tolerance:** The acceptable variance between a subledger and its GL Control Account is exactly $0.00.
2. **Control Account Lockdown:** Humans cannot post manual journal entries directly to Control Accounts.
3. **As-Of Reporting:** Reconciliations must be capable of running retroactively for any given date in history.
4. **Hard Block on Close:** Unresolved reconciliation drift halts the month-end closing process.

## 7. Reconciliation Scope
The framework mandates reconciliation for the following domains:
- **Inventory:** Cost Ledger Balances vs GL Inventory Asset Accounts.
- **GRNI:** Uninvoiced Receiving Lines vs GL GRNI Liability Accounts.
- **Accounts Payable:** Unpaid AP Invoices vs GL AP Trade Liability Accounts.
- **Accounts Receivable:** Unpaid AR Folios/Invoices vs GL AR Trade Asset Accounts.
- **Fixed Assets:** FA Subledger Net Book Value vs GL FA Asset Accounts.

## 8. Reconciliation Frequency
- **Daily:** Automated background jobs will run "Flash Reconciliations" nightly to alert the Finance team of any immediate drift (e.g., a failed async posting queue).
- **Month-End:** Mandatory, formally signed-off reconciliation snapshots must be generated before the `SOFT CLOSED` and `CLOSED` states (ADR-013) can be activated.

## 9. Inventory Reconciliation
- **Formula:** `Sum(Current AVCO * Current Qty)` across all SKUs in the Cost Ledger MUST EQUAL the balance of the GL Inventory Control Account.
- **Mechanism:** The reconciliation engine must aggregate the detailed Cost Ledger entries per Property/Department and compare them against the GL trial balance.

## 10. GRNI Reconciliation
- **Formula:** `Sum(Uninvoiced Qty * Original Receipt Cost)` across all open Receiving Lines MUST EQUAL the GL GRNI Control Account.
- **Mechanism:** The report identifies exactly which POs/Receipts constitute the GRNI liability. Aging metrics (e.g., GRNI > 90 days) must be highlighted.

## 11. AP Reconciliation
- **Formula:** `Sum(Open Invoice Balances)` in the AP Subledger MUST EQUAL the GL AP Trade Control Account.

## 12. Exception Management
- **Drift Identification:** If a variance > $0.00 is detected, the system immediately flags the Control Account as `OUT_OF_BALANCE`.
- **Causes:** 
  1. *Timing:* A physical transaction occurred at 23:59, but the async GL posting queued at 00:01.
  2. *Software Bug:* An edge-case division-by-zero error in the Cost Posting Engine caused a subledger entry to fail, but the GL entry succeeded.
- **Resolution:** Manual journals to the Control Account are forbidden. The resolution *must* occur in the subledger (e.g., fixing the stuck queue, reversing and re-entering the flawed receipt).

## 13. Period Close Integration
*Ref ADR-013:*
The `FinancialPeriodService` will query the Reconciliation Engine during the `CLOSE` request. If any scoped Control Account is `OUT_OF_BALANCE`, the system will throw a hard exception, abort the close, and demand the Director of Finance resolve the drift.

## 14. Multi Property Rules
Reconciliations are executed strictly per `property_id`. Intercompany Control Accounts must also be reconciled cross-property (e.g., Property A's Intercompany AR from Property B MUST EQUAL Property B's Intercompany AP to Property A).

## 15. Reporting Requirements
1. **Subledger vs GL Report:** A high-level dashboard showing Subledger Balance, GL Balance, and Variance.
2. **GRNI Aging Report:** Detailed breakdown of the GRNI liability.
3. **AP/AR Aging Reports:** Detailed breakdown of trade liabilities/assets.
4. **Reconciliation Snapshot Archive:** Immutable PDFs or data snapshots generated at the exact moment of Period Close.

## 16. Audit Requirements
- External auditors require definitive proof that manual manipulation of Control Accounts is impossible. The GL rules engine must log any rejected attempt to post a manual journal to a Control Account.
- The Period Close Snapshot must carry the digital signature (ULID/Timestamp) of the Director of Finance.

## 17. Hospitality Considerations
- **High Transaction Volume:** A 1,000-room resort with dozens of POS outlets generates massive daily subledger activity (e.g., mini-bar depletions). Running a full SUM() across millions of Cost Ledger rows daily requires highly optimized, indexed database queries to prevent timeout failures.
- **Transient AR (Guest Ledger):** The PMS Guest Ledger (Checked-in guests) acts as a highly volatile subledger to the GL Guest Ledger Control Account. Night Audit usually handles this, but IVORQ must reconcile it daily.

## 18. Risks
- **Performance Bottlenecks:** Calculating "As-Of" historical subledger balances dynamically can overwhelm the database if the schema is not heavily optimized for point-in-time queries.
- **Deadlocks during Fixes:** If a bug causes drift, and the IT team must intervene directly in the database to fix a corrupted row, bypassing the application layer might further break the reconciliation if not done perfectly.

## 19. Advantages
- Bulletproof financial integrity. External audits become frictionless.
- Eliminates the toxic culture of "plugging" month-end numbers.
- Forces operational discipline and highlights software bugs immediately rather than months later.

## 20. Trade-Offs
- High administrative friction. A $0.01 rounding error bug in the AP module will halt the month-end close for the entire hotel until IT patches the bug.

## 21. Consequences
- The General Ledger module must implement a `is_control_account` boolean flag on the Chart of Accounts to hard-block manual journal entries.
- The reporting engine must be optimized to handle massive, high-speed SUM() aggregations across the Cost Ledger.
