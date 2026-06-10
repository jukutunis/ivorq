# General Ledger Implementation Plan

## 1. Architecture
- **Module:** `Modules/Finance/GeneralLedger`
- **Role:** Single Source Of Truth for all financial transactions in IVORQ.
- **Boundaries:** Completely isolated service layer interacting with Subledgers (AP, Banking, etc.) strictly through well-defined APIs or events.
- **Dependencies:** Foundation Module, Property Module.
- **Ownership:** `Finance` domain.
- **Extensibility:** Built using Service and Repository patterns, allowing new subledgers to integrate without modifying the core Ledger Engine.

## 2. COA (Chart of Accounts) Design
- **Structure:** Hotel enterprise COA optimized for USALI (Uniform System of Accounts for the Lodging Industry).
- **Core Elements:** `Property ID` -> `Department` -> `Account` -> `Sub-Account`.
- **Classification:** Assets, Liabilities, Equity, Revenue, Cost Of Sales, Expenses.
- **Multi-Property Support:** A Master COA acts as a global template. Each property will have its localized Chart of Accounts mapped to the Master COA to ensure standardized consolidated reporting.
- **Multi-Company Support:** Future-proofed by ensuring rollups map not just to properties but to an owning corporate entity (Company ID).

## 3. Journal Engine Design
- **JournalEntry (Header):**
  - ULID Primary Key.
  - Date (Transaction Date & Posting Date).
  - Reference / Source Document.
  - Description.
  - Status (Draft, Pending Approval, Posted, Voided).
- **JournalEntryLine (Detail):**
  - ULID Primary Key.
  - JournalEntry ID.
  - Account ID.
  - Debit Amount.
  - Credit Amount.
  - Memo.
- **Double-Entry Rules:**
  - Strict enforcement: `SUM(debit) == SUM(credit)`.
  - Service Layer Validation: A journal **cannot** be posted if it is out of balance. Database level constraint (or trigger) as a failsafe.

## 4. Account Structure Review
- **Standard Types:** Asset, Liability, Equity, Revenue, Cost Of Sales, Expense.
- **Categories:** Current Asset, Fixed Asset, Current Liability, Long Term Liability, Retained Earnings, Operating Revenue, Departmental Expense, Undistributed Operating Expense, Fixed Charges.
- **Missing Classifications Identified:**
  - **Statistical Accounts:** Essential for hotel operations (e.g., Rooms Available, Rooms Occupied, Covers Sold). These are non-financial but necessary for calculating KPIs like ADR and RevPAR within financial reports.
  - **Clearing / Suspense Accounts:** Critical for integrations like Banking and POS where transactions might temporarily reside before being fully allocated.

## 5. Property Isolation Review
- **Property Ownership:** All GL entities (Accounts, Journals, Lines, Balances) must include `property_id` (ULID) and strictly adhere to Laravel Global Scopes and Spatie permissions to ensure tenant isolation.
- **Cross-Property Restrictions:** A single Journal Entry cannot cross boundaries natively. Inter-property transactions must generate balancing "Due To / Due From" entries in both property ledgers to maintain strict isolation while balancing at the corporate level.
- **Consolidation Support:** Facilitated through mapping Property COA to a Master/Corporate COA, allowing roll-up reporting without mixing transactional data.

## 6. Audit & Compliance Review
- **Immutability:** Once a Journal Entry's status is `Posted`, it is cryptographically/logically sealed. Updates or deletions are strictly prohibited.
- **Reversal Strategy:** Erroneous entries must be corrected by issuing a **Reversing Journal Entry** with reference to the original ULID, followed by a new correct entry if applicable.
- **Posting Audit Trail:** Full tracking of the `user_id`, `subledger_source`, and timestamp of the posting event.
- **Correction Strategy:** Standardized workflow for Month-End Adjusting Journal Entries (AJEs) with distinct approval workflows.

## 7. Dependency Review (Integration Strategy)
- **Subledgers:** Purchasing, AP, Banking, Inventory, POS, Payroll.
- **Integration Strategy:**
  - Subledgers will NOT write directly to GL tables.
  - **Event-Driven / API Approach:** Subledgers dispatch domain events (e.g., `PaymentProcessed`, `InvoiceApproved`) or call a `GeneralLedgerService->postSubledgerJournal()` contract.
  - The GL Engine processes these requests, validates the double-entry math, and generates the final Journal Entry.

## 8. Risk Analysis

| Risk Level | Risk | Mitigation Strategy |
| :--- | :--- | :--- |
| **Critical** | Unbalanced Journal Entries saving to the DB | Enforce strict `debit == credit` validation in the Service Layer. Use database transactions to ensure Header and Lines commit atomically. |
| **High** | Concurrency issues updating running Account Balances | Do not store running balances directly on the line. Use a dedicated `LedgerBalance` table updated via asynchronous queue or pessimistic locking during posting. |
| **Medium** | Performance degradation with massive journal volumes | Implement indexing on `property_id`, `account_id`, and `date`. Design for read-replicas for heavy financial reporting. |
| **Low** | Subledger mapping to incorrect GL Accounts | Implement a strict configuration UI for subledgers ensuring only active, valid, and property-specific accounts can be mapped. |

## 9. Testing Plan
- **Unit Tests:** Service layer validation of double-entry logic, ensuring out-of-balance entries throw `JournalOutOfBalanceException`.
- **Integration Tests:** End-to-end testing of subledger-to-GL posting (e.g., AP Invoice posting creates correct GL lines).
- **Security Tests:** Verify property isolation (User A from Property 1 cannot view or post to Property 2).
- **Concurrency Tests:** Load testing multiple simultaneous journal postings to the same account to ensure accurate balance aggregation without deadlocks.
