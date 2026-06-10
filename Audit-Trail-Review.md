# Audit Trail Review

## Scope
Review of the Audit Trail mechanisms across Create, Update, Delete, Approval, and Posting events for all domains.

- `AuditLog` Model & Immutability
- `HasAuditColumns` Trait
- `AuditObserver`
- Audit Coverage across Modules

## Findings

### 1. Immutability & Record Integrity
- **Status**: Excellent.
- **Analysis**: The `AuditLog` model is properly locked down. It disables `$timestamps` (managing `created_at` manually), uses `$guarded = ['*']` to prevent mass assignment, and is deliberately missing `updated_at` or `deleted_at`. The only write path is through `AuditLog::record()`, which guarantees record immutability.

### 2. User Auditing (HasAuditColumns)
- **Status**: Good.
- **Analysis**: The `HasAuditColumns` trait correctly stamps `created_by` and `updated_by` automatically via Eloquent boot hooks (`creating`, `updating`). This provides base-level attribution across all models using it (like `InventoryItem`, `WorkOrder`, etc.).

### 3. Missing Audit Coverage (CRITICAL GAP)
- **Status**: **FAIL (Critical Finding)**
- **Analysis**: The `AuditObserver` is responsible for logging the detailed `old_values` and `new_values` for `created`, `updated`, `deleted`, and `restored` events. However, these observers are manually registered in `Modules\Foundation\Audit\AuditServiceProvider`.
- **The Gap**: The `$auditableModels` array **only** registers Foundation models (`Company`, `Property`, `Department`, `User`). 
- **Impact**: All operations in **PMS**, **Engineering**, **Housekeeping**, and **Inventory** currently have **ZERO detailed audit logging** for CRUD events. Changes to Reservations, Work Orders, and Inventory Items are untraceable regarding what fields changed.

### 4. Stock Movement & Financial Posting
- **Status**: Good (Domain-Specific).
- **Analysis**: Inventory stock movements bypass standard `AuditLog` and correctly use `InventoryStockCard` as an append-only, immutable ledger (BR-005). Approvals and postings are stamped directly on the transaction headers (`approved_by`, `posted_by`). While domain-specific auditing is handled properly, general field modifications (e.g., editing an unapproved Receipt's remarks) are missed due to Finding #3.

## Conclusion
While the foundational Audit mechanism is highly secure and immutable, it has not been wired up to the operational modules.

**Status**: Major Hardening Required (Missing Audit coverage for all Operations modules).
