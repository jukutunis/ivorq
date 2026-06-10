# Auto Matching Engine Implementation Plan

## 1. Architecture

The Auto Matching Engine will be implemented as a dedicated service: `AutoMatchingService`.
It sits adjacent to `ReconciliationSessionService` but specifically handles the heuristics of pairing `BankStatementLine` with matchable entities (currently limited to `PaymentVoucher`).

**Components Required:**
- `AutoMatchingService`: Core engine executing the matching rules.
- `AutoMatchingController`: Exposes the API endpoint to trigger the engine and retrieve recommendations.
- `ReconciliationMatchService`: Handles the actual persistence of user-confirmed matches.

**Ownership Boundaries:**
- **Matching Heuristics:** Exclusively owned by `AutoMatchingService`.
- **Match Persistence:** Exclusively owned by `ReconciliationMatchService` (ensures `ReconciliationMatch` creation constraints and snapshots are respected).
- **Session State:** Exclusively owned by `ReconciliationSessionService`.

## 2. Matching Rules Strategy

The CTO directive restricts matching to strict exact/tolerance rules without AI or ML. The service will process unmatched `BankStatementLine` records for the session and search for unmatched `PaymentVoucher` records.

### Rule 1: Exact Match (Highest Priority)
- **Amount:** `bccomp(line.amount, voucher.total_amount, 2) === 0`
- **Reference:** Exact string match (`line.reference` === `voucher.reference_no`)
- **Action:** If matched, record is added to proposals.

### Rule 2: Date Tolerance Match (Secondary Priority)
- **Amount:** `bccomp(line.amount, voucher.total_amount, 2) === 0`
- **Date:** `voucher.payment_date` is within ±2 days of `line.transaction_date`.
- **Action:** If matched and not already matched by Rule 1, added to proposals.

### Rule 3: Manual Match
- User selects an unmatched `BankStatementLine` and an unmatched `PaymentVoucher` manually from the UI.
- Skips rules and directly persists a `ReconciliationMatch`.

### Ambiguity & Tie-Break Rules
- **Multiple matches:** If a single `BankStatementLine` matches multiple `PaymentVouchers` on amount/date, the engine will **skip auto-matching** for that line to prevent false positives. It will be left for Manual Match.
- **Directional matching:** `BankStatementLine` amounts are generally positive/negative. `PaymentVoucher` is usually an outgoing payment. The engine must ensure signs align properly depending on bank statement formatting.

## 3. Database Impact Review

**1. Do existing tables support matching?**
Yes. `BankStatementLine` has `amount`, `reference`, `transaction_date`. `PaymentVoucher` has `total_amount`, `reference_no`, `payment_date`.

**2. Are new tables required?**
No.

**3. Are new columns required?**
No.

**Why?**
The Auto Match feature creates *recommendations only*. These recommendations are transient and should not pollute the database until explicitly confirmed by the user. 
When the user clicks "Auto Match" in the UI, the API returns a JSON array of proposed matches. The user reviews them, optionally alters them, and clicks "Confirm". At that point, the frontend submits the final array to be persisted directly into the existing `reconciliation_matches` table.

**4. Are snapshots sufficient?**
Yes. The current snapshot fields in `reconciliation_matches` (`matchable_reference`, `statement_amount`, etc.) fully support the audit requirements for confirmed matches.

## 4. Concurrency Design

- **Duplicate Matching Prevention:** The engine must filter out any `BankStatementLine` or `PaymentVoucher` that is already matched (`exists in reconciliation_matches`).
- **Race Conditions:** When persisting the confirmed matches, the system must wrap the insertions in a `DB::transaction()`. It must use `lockForUpdate()` on the `ReconciliationSession` to ensure the session status is still `Open` or `InProgress`.
- **Database Constraints:** `reconciliation_matches` already has unique constraints on `bank_statement_line_id` and `[matchable_type, matchable_id]` (BR-007, BR-008). This guarantees database-level protection against race conditions allowing double matching.

## 5. Workflow

1. User opens a Reconciliation Session (`status: Open`).
2. User clicks "Run Auto Match".
3. Frontend calls `GET /api/v1/banking/reconciliations/{session}/auto-match`.
4. `AutoMatchingService` runs Rules 1 and 2 in memory.
5. API returns `ProposedMatches[]`.
6. User reviews the list on screen. User unchecks bad matches or manually adds missing ones (Rule 3).
7. User clicks "Confirm Matches".
8. Frontend calls `POST /api/v1/banking/reconciliations/{session}/matches` with the approved pairs.
9. `ReconciliationMatchService` validates they are still unmatched and persists them.

## 6. Business Rules Additions

- **BR-011:** Auto Match creates recommendation only. (Met via transient API response).
- **BR-012:** Recommendation is not final. (Met via transient API response).
- **BR-013:** User must confirm matches. (Met via two-step workflow).
- **BR-014:** Manual Match overrides recommendation. (User can modify the JSON payload before submission).
- **BR-015:** Completed sessions cannot rerun matching. (API endpoint will enforce `status` checks).
- **BR-016 (New):** If an Auto Match heuristic finds multiple identical candidates (e.g., two identical $50.00 payments on the same day without a reference), it will skip auto-matching to prevent incorrect assignment.

## 7. API Proposal

**Generate Recommendations:**
`GET /api/v1/banking/reconciliations/{session}/auto-match`
Returns:
```json
{
  "data": [
    {
      "bank_statement_line_id": "01H...",
      "matchable_type": "Modules\\Finance\\Payables\\Models\\PaymentVoucher",
      "matchable_id": "01H...",
      "rule_applied": "ExactMatch"
    }
  ]
}
```

**Confirm Matches:**
`POST /api/v1/banking/reconciliations/{session}/matches`
Payload:
```json
{
  "matches": [
    {
      "bank_statement_line_id": "01H...",
      "matchable_type": "Modules\\Finance\\Payables\\Models\\PaymentVoucher",
      "matchable_id": "01H..."
    }
  ]
}
```

## 8. Risks and Mitigation

- **False Positives (Wrong voucher matched):** Mitigated by the exact amount requirement AND skipping ambiguous (duplicate) identical matches.
- **False Negatives (Missed matches):** Expected and acceptable. The user will use Manual Match (Rule 3) for anything the engine misses.
- **Performance Issues:** Loading all unmatched vouchers could be slow for massive properties. Mitigated by querying only vouchers within the `[session->statement_date_start - 30 days, session->statement_date_end + 30 days]` window.

## 9. Testing Plan

We will create `AutoMatchingEngineModuleTest` with the following scenarios:
1. `test_engine_matches_exact_amount_and_reference`
2. `test_engine_matches_exact_amount_and_date_tolerance`
3. `test_engine_skips_ambiguous_matches`
4. `test_engine_ignores_already_matched_lines_and_vouchers`
5. `test_saving_matches_enforces_session_status`
6. `test_saving_matches_creates_proper_snapshots`
