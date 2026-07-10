# ADR 028: Accounts Receivable & Guest Ledger / City Ledger Strategy

## 1. Title
ADR-028: Accounts Receivable & Guest Ledger / City Ledger Strategy

## 2. Status
Proposed

## 3. Context
In enterprise hospitality, the collection of revenue is highly fragmented. A guest might pay for a drink with cash (immediate settlement), charge a dinner to their room (Guest Ledger), have their room rate billed directly to their company (City Ledger), and have the original booking prepaid by an Online Travel Agency (Unearned Revenue). Following the establishment of Revenue Recognition (ADR-025), Payment Reconciliation (ADR-019), and FX Revaluation (ADR-018), IVORQ must define the strict boundaries of its Accounts Receivable (AR) architecture. Without a governed separation between active guests and institutional debtors, the hotel's working capital will become paralyzed by untraceable outstanding debts, uncollected OTA virtual cards, and massive bad debt write-offs.

## 4. Problem Statement
The "Accounts Receivable" of a hotel is fundamentally split into two distinct operational realities. The **Guest Ledger** tracks guests currently sleeping in the hotel; it is highly volatile, updating every minute via POS integrations and nightly room posts. The **City Ledger** tracks institutional debt (Corporations, OTAs, Travel Agents) that is billed on 30/60/90-day terms. If these ledgers are merged, the Finance team cannot accurately age their institutional debt. Furthermore, if the system cannot handle "Split Billing" (where the company pays for the room, but the guest pays for the minibar), the Front Desk will be unable to check guests out, leading to chaotic manual journal entries that destroy the Subledger Reconciliation Framework (ADR-016).

## 5. Decision
IVORQ will implement a strict **Two-Ledger AR Architecture**. The **Guest Ledger** acts exclusively as a volatile clearing account for "In-House" folios. The **City Ledger** serves as the formal Accounts Receivable subledger for institutional debt and post-checkout collections. A highly governed "Direct Bill" workflow will transfer balances from the Guest Ledger to the City Ledger at checkout, utilizing Split Billing to segregate personal charges. The system will enforce strict Credit Limits on both ledgers, and formal Bad Debt write-offs will require executive approval via the Approval Engine (ADR-003).

## 6. Guest Ledger Rules
- **Scope:** Accounts for all charges and payments for guests currently Checked-In or Pending Check-Out.
- **Posting:** POS charges (ADR-021) and Night Audit room charges (ADR-025) post directly to the Guest Folio, increasing the Guest Ledger asset balance.
- **Deposit Offsetting:** Pre-paid deposits held in `Unearned Revenue` (Liability) are applied against the Guest Folio, offsetting the balance.
- **Checkout:** A Guest Folio must balance to exactly $0.00 to execute a Check-Out.

## 7. City Ledger Rules (Accounts Receivable)
- **Scope:** Accounts for all institutional debt. Corporate accounts, Online Travel Agencies (OTAs), Wholesalers, and Credit Card merchant clearing accounts.
- **Invoicing:** City Ledger invoices are generated against specific "Company Profiles." They are formal AR documents subject to payment terms (e.g., Net 30).
- **Payment & Bank Rec:** Payments received against the City Ledger are processed through the Payment Allocation Engine (ADR-019).

## 8. Split Billing & Transfer Rules
- **The "Direct Bill" Event:** When a corporate guest checks out, their room charges must be billed to their company.
- **Split Folio Execution:** The Front Desk splits the folio into `Window 1 (Company)` and `Window 2 (Guest)`.
- **Settlement:** 
  - `Window 2` is settled by the guest's physical credit card.
  - `Window 1` is settled via the "Direct Bill" payment method.
- **Ledger Impact:** The Direct Bill payment posts: `Cr Guest Ledger (clearing the folio) | Dr City Ledger (creating the AR Invoice for the Corporation)`.
- **Accepted Transfer Boundary:** Clarified by ADR-088. PMS Guest Ledger owns the guest balance before an accepted Direct Bill / AR transfer. Accounting / AR owns the City Ledger receivable only after the transfer is accepted. A requested, pending, failed, rejected, or reversed transfer is not settlement evidence by itself.

## 9. Credit Rules
- **Guest Credit Limits:** In-house guests have a "Floor Limit" (e.g., $500). If the Guest Ledger balance exceeds this, the POS Integration API hard-blocks any further room charges until the guest provides a mid-stay payment.
- **City Ledger Credit Limits:** Corporate profiles have formal credit limits (e.g., $50,000) and aging limits (e.g., Block if any invoice > 90 days). If breached, IVORQ hard-blocks the creation of new reservations flagged for Direct Bill under that profile.

## 10. Collection Rules (Chargebacks & Bad Debt)
- **Chargebacks:** If a guest disputes a credit card charge after checkout, the bank forcibly withdraws the funds. The Finance team must re-open a City Ledger AR invoice against the guest profile to track the missing cash while the dispute is fought.
- **Bad Debt Write-Off:** If an OTA goes bankrupt, the outstanding AR must be written off. This requires an approved journal: `Dr Bad Debt Expense | Cr City Ledger AR`. 

## 11. Multi Currency Rules
*Ref ADR-018:*
- OTA Virtual Cards (VCC) or direct bills are often issued in foreign currencies (e.g., USD) while the hotel operates in IDR.
- The City Ledger AR invoice is held in USD and revalued at the Month-End Closing Rate. When the OTA payment clears the bank (ADR-019), any difference is posted as Realized FX Gain/Loss.

## 12. Reporting Requirements
1. **AR Aging Report:** 30/60/90/120+ days grouped by Company Profile.
2. **Guest Ledger Trial Balance:** A list of all in-house guest balances, essential for the Night Audit.
3. **High Balance Report:** Alerts management to in-house guests approaching their credit limits.
4. **Credit Risk Exposure:** Total AR + Upcoming Future Reservations mapped against approved credit limits.

## 13. Audit Requirements
- Moving a balance from the Guest Ledger to the City Ledger requires the destination Company Profile to have an active, signed "Direct Bill Authorization" on file, preventing front desk agents from dumping unpaid folios into fake corporate accounts to hide cash theft.
- Bad Debt write-offs must carry the digital signature of the Director of Finance and the General Manager.

## 14. Risks
- **OTA Virtual Card Expiration:** OTAs (like Expedia) issue Virtual Credit Cards that only work for the exact amount on the exact day of checkout. If the Front Desk forgets to charge the card, it expires, and the hotel loses the revenue entirely. IVORQ must implement automated VCC charging during the Night Audit.
- **Credit Limit Evasion:** If a corporate account hits its $50,000 limit, a sales manager might maliciously create a duplicate profile ("Company XYZ 2") to keep booking rooms, exposing the hotel to massive financial risk.

## 15. Advantages
- Perfect separation between volatile operational ledgers and formal institutional debt.
- Hardened credit controls prevent catastrophic revenue loss from defaulting wholesalers.
- Fully supports complex hospitality billing scenarios (Weddings, Corporate Groups, Split Folios).

## 16. Trade-Offs
- Forces the Front Desk to perfectly execute Split Billing at checkout. If they accidentally Direct Bill the guest's $500 minibar tab to IBM's corporate account, IBM will reject the invoice, creating a massive reconciliation nightmare for the Finance team 30 days later.

## 17. Consequences
- The PMS module must implement a highly robust `FolioRoutingEngine` capable of splitting charges by category (e.g., Room vs F&B) automatically based on pre-configured Group/Corporate routing instructions.
- The General Ledger must separate the Guest Ledger Control Account from the City Ledger Control Account, subjecting both to the strict Subledger Reconciliation Framework (ADR-016).
