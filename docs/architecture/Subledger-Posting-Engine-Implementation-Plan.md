# Subledger Posting Engine Implementation Plan

## 1. Architecture Review Result
- **Module Placement:** `PostingProfile`, `PostingRule`, and `SubledgerPostingService` will belong to the `Modules/Finance/GeneralLedger` module. Placing them in the General Ledger ensures that subledgers (like Payables or Banking) do not dictate core GL structure and mapping. The GL module acts as the authoritative center for how transactions translate into journal entries.
- **Service Interaction:** The `SubledgerPostingService` will act as an adapter/orchestrator. When a subledger triggers an event (e.g., `AccountPayableApproved`), the `SubledgerPostingService` will lookup the appropriate `PostingProfile`, generate the Draft `JournalEntry` array containing the correct debits/credits based on `PostingRule` configuration, and dispatch it to the `GeneralLedgerService` for posting and balance updates.
- **Source Tracing:** `source_module`, `source_type`, and `source_id` on the `gl_journal_entries` table will guarantee exact bidirectional traceability and will be utilized to enforce a strict unique constraint to prevent double-posting.

## 2. Database Design (New Tables)

### `gl_posting_profiles`
Defines the mapping framework for a specific event within a module.
- `id` (ULID)
- `property_id` (ULID)
- `module` (e.g., 'Payables')
- `event` (e.g., 'AccountPayableApproved', 'PaymentVoucherPosted')
- `description`
- `is_active` (boolean)

### `gl_posting_rules`
Defines the actual GL accounts to use for debit and credit sides of a posting profile.
- `id` (ULID)
- `property_id` (ULID)
- `posting_profile_id` (ULID)
- `account_role` (e.g., 'AP_Liability', 'Cash_Account', 'Expense_Account')
- `account_id` (ULID) - The GL account to map to.
- `department_id` (ULID, nullable)

### `gl_posting_logs`
An audit trail for posting attempts to assist with troubleshooting failed postings.
- `id` (ULID)
- `property_id` (ULID)
- `source_module`
- `source_type`
- `source_id`
- `status` (Success, Failed)
- `error_message` (text, nullable)

## 3. Business Rules
- **BR-001**: A subledger document may only post once to GL.
- **BR-002**: Posting must create balanced journal entries (`debit == credit`).
- **BR-003**: Posting requires active GL accounts.
- **BR-004**: Posting rules and resulting journals must belong to the same property.
- **BR-005**: AP documents in Exception or Cancelled status cannot be posted.
- **BR-006**: Payment Vouchers in Draft or Cancelled status cannot be posted.
- **BR-007**: Posted GL journal is immutable.
- **BR-008**: Duplicate posting must be strictly blocked by a unique constraint on `source_module` + `source_type` + `source_id` within the GL journal entries.
- **BR-009**: Cross-property posting is blocked (Subledger property must match GL property).
- **BR-010**: All posting events must be auditable via `gl_posting_logs`.

## 4. Journal Generation Design
**For AccountPayable:**
- `source_module` = Payables
- `source_type` = AccountPayable
- `source_id` = account_payable.id
- **Debit:** Expense / Cost Account (dynamically pulled from the AP Line or Vendor default).
- **Credit:** Accounts Payable Liability Account (pulled from `gl_posting_rules` for the 'AP_Liability' role).

**For PaymentVoucher:**
- `source_module` = Payables
- `source_type` = PaymentVoucher
- `source_id` = payment_voucher.id
- **Debit:** Accounts Payable Liability Account (offsetting the AP liability).
- **Credit:** Bank / Cash Account (pulled from the Payment Voucher's Bank Account mapping).

## 5. Duplicate Prevention Strategy
- **Application Level:** `SubledgerPostingService` will check `gl_journal_entries` for an existing record with the identical `source_module`, `source_type`, and `source_id` before processing.
- **Database Level:** A unique index on `(property_id, source_module, source_type, source_id)` in the `gl_journal_entries` table (where reversal flag is null) to guarantee duplicate prevention during high-concurrency race conditions.

## 6. Security & Audit Design
- **Immutability:** Existing `GeneralLedgerService` already enforces immutability.
- **Posting Logs:** Every attempt to post from a subledger will create a `gl_posting_logs` record ensuring failed postings (e.g., due to an inactive GL account or missing rule) are captured and visible to Finance Admins.
- **Policies:** Only automated system processes or authorized Finance roles can trigger the `SubledgerPostingService`. Manual journal entry bypass of subledgers must be restricted by role.

## 7. Risk Matrix

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| Duplicate Posting | Critical | Enforce DB Unique Constraints on `source_module`, `source_type`, `source_id`. Service level checks before posting. |
| Out of Balance | Critical | Leverages existing `GeneralLedgerService` which hard-fails on out-of-balance entries. |
| Missing/Wrong GL Mapping | High | `SubledgerPostingService` will validate rule completeness before constructing the journal. Fails gracefully and logs error in `gl_posting_logs` if missing. |
| Cross-Property Leakage | High | Strict `property_id` enforcement in Subledger, Posting Rules, and GL lines. Mismatch throws an exception immediately. |

## 8. Testing Plan
- **Unit Tests:** Verify `PostingProfile` and `PostingRule` creation constraints.
- **Integration Tests:**
  - Test Account Payable approval triggers successful GL Journal creation with correct Dr/Cr amounts.
  - Test Payment Voucher posting triggers successful GL Journal creation with correct Dr/Cr amounts.
  - Test duplicate posting attempt throws `DuplicatePostingException`.
  - Test missing mapping rule fails gracefully and writes to `gl_posting_logs`.

## 9. Open Questions
1. **Trigger Mechanism:** Will the Subledger Posting be triggered via asynchronous Jobs/Events (e.g., `AccountPayableApproved` event), or synchronously at the exact moment the user clicks "Approve/Post" in the UI?
2. **Reversal Handling:** If an AP Invoice is voided after being posted to GL, should the Subledger Engine automatically generate a Reversing Journal Entry, or is that a manual process in Phase 1?
3. **Expense Account Source:** Does the AP Invoice line explicitly define the GL Account ID to use for the debit, or does the engine need to derive it from a Vendor Profile or Item Category?

> [!NOTE]
> Please review this architecture plan. Awaiting your feedback and CTO approval to proceed with implementation.
