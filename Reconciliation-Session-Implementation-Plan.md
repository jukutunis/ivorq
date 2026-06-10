# IVORQ Sprint 10.4C: Reconciliation Session Foundation

## 1. Architecture
**Module Ownership:** `Modules/Finance/Banking`
**Core Entities:**
- `ReconciliationSession`: Represents a single period of reconciliation for a specific bank account.
- `ReconciliationMatch`: Represents the linking of a `BankStatementLine` to an internal system record (e.g., `PaymentVoucher`).

**Layered Design:**
- **Controllers:** `ReconciliationSessionController`
- **Services:** `ReconciliationSessionService` (handles lifecycle: open, complete, cancel).
- **Repositories:** `ReconciliationSessionRepository` (handles data access and lock queries).
- **Policies:** `ReconciliationSessionPolicy` (enforces property isolation).

## 2. Dependencies
**Required Modules:**
- `Modules/Finance/Banking` (BankAccount, BankStatementLine)
- `Modules/Finance/Payables` (PaymentVoucher, VendorInvoice)
- `Modules/Foundation/Property` (Property isolation)
- `Modules/Foundation/Audit` (Audit logs)

**Deferred Integration:**
- Advanced matching engines (Auto Matching) and General Ledger posting are deferred to subsequent sprints.

## 3. Business Rules
- **BR-001 (Singleton Session):** Only one active session (Open, InProgress, Review) is permitted per `bank_account_id`.
- **BR-002 (Immutability):** A session marked as `Completed` or `Cancelled` cannot be edited.
- **BR-003 (Balance Update):** Completing a session updates the `reconciled_balance` on the related `BankAccount`.
- **BR-004 (Match Locking):** Completing a session flags all associated `ReconciliationMatch` records as locked (`is_locked = true`).
- **BR-005 (Audit Requirement):** Session cancellation must record the user and timestamp into the enterprise audit trail.
- **BR-006 (Property Isolation):** A session must belong to the exact same property as its parent Bank Account.

## 4. Database Design

### `reconciliation_sessions`
| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | ULID | Primary Key | |
| `property_id` | ULID | Foreign Key, Indexed | Property isolation |
| `bank_account_id` | ULID | Foreign Key, Indexed | Parent account |
| `statement_date_start` | Date | Required | |
| `statement_date_end` | Date | Required | |
| `opening_balance` | Decimal(15,2) | Default 0 | |
| `reconciled_balance` | Decimal(15,2) | Default 0 | |
| `unreconciled_balance` | Decimal(15,2) | Default 0 | |
| `status` | String | Indexed | Open, InProgress, Review, Completed, Cancelled |
| `completed_at` | Timestamp | Nullable | |
| `completed_by` | ULID | Nullable | User ID |
| `cancelled_at` | Timestamp | Nullable | |
| `cancelled_by` | ULID | Nullable | User ID |
| Audit & Timestamps | | | created_at, updated_at, deleted_at, created_by, updated_by |

**Indexes:**
- Unique partial index on `(bank_account_id)` where `status IN ('Open', 'InProgress', 'Review')` to strictly enforce BR-001 at the DB level (PostgreSQL partial index).

### `reconciliation_matches`
| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | ULID | Primary Key | |
| `property_id` | ULID | Foreign Key | Property isolation |
| `reconciliation_session_id` | ULID | Foreign Key | Parent session |
| `bank_statement_line_id` | ULID | Foreign Key | The imported bank line |
| `matchable_type` | String | Indexed | Morph (e.g., PaymentVoucher) |
| `matchable_id` | ULID | Indexed | Morph ID |
| `amount_matched` | Decimal(15,2)| Required | |
| `is_locked` | Boolean | Default false | BR-004 |
| Audit & Timestamps | | | standard audit columns |

## 5. API Proposal
```text
POST /api/v1/banking/reconciliations (Create Session)
GET  /api/v1/banking/reconciliations (List Sessions)
GET  /api/v1/banking/reconciliations/{id} (View Session)
POST /api/v1/banking/reconciliations/{id}/status (Transition Status: Complete/Cancel)
```

## 6. Security Design
- API protected via Sanctum middleware.
- Actions protected via Spatie permissions: `banking.reconciliation.view`, `banking.reconciliation.create`, `banking.reconciliation.manage`.
- `ReconciliationSessionPolicy` validates `X-Property-Id` header against `property_id` column.

## 7. Audit Design
- `HasAuditColumns` trait handles basic trace (created_by, updated_by).
- Model registered in `AuditServiceProvider` to track all attribute mutations.
- Specific domain events (`ReconciliationSessionCompleted`, `ReconciliationSessionCancelled`) will be dispatched for complex audit logs (including capturing the balance snapshots).

## 8. Concurrency Design
- **DB Constraints:** The PostgreSQL partial unique index ensures no race condition can create two active sessions for the same bank account.
- **Application Locking:** Standard `DB::transaction()` and pessimistic locking (`lockForUpdate()`) on the `BankAccount` when closing/completing a session to safely update `reconciled_balance`.

## 9. Risks
1. **Concurrency Risk (Double Reconciliation):** Mitigated by the partial unique index.
2. **Balance Corruption:** Mitigated by `bccomp` / safe decimal math and DB transactions wrapping the `status -> Completed` transition.
3. **Property Leakage:** Enforced by mandatory `property_id` checks on `Matchable` polymorphism.
4. **Audit Gaps:** Addressed by enforcing immutability constraints at the repository level; any update to a `Completed` record throws an Exception before hitting DB.

## 10. Testing Plan
- `test_cannot_create_multiple_active_sessions_for_same_account` (Validates BR-001)
- `test_completing_session_updates_account_reconciled_balance` (Validates BR-003)
- `test_completing_session_locks_matches` (Validates BR-004)
- `test_completed_session_is_immutable` (Validates BR-002)
- `test_cancelled_session_leaves_audit_trail` (Validates BR-005)
- `test_session_isolated_by_property` (Validates BR-006)
