# Governance Recovery Plan

## Executive Summary
This Governance Recovery Plan has been developed following the review of the `cto_validation_report_v2.md` and the independent `ivorq_master_repository_audit_v2.md`. The review confirms that the legacy audit represented a severe governance failure and that the V2 audit accurately reflects the current architectural and security drift. This plan acts as the authoritative backlog to remediate missing documentation, incomplete audit trails, unresolved technical debt, and tenancy ambiguity.

## Verified Findings

### FND-001: Missing Core Architectural Decision Records (ADRs)
* **Verification Status:** VERIFIED
* **Evidence:** The `docs/decisions/` directory was inspected and only `ADR-005-Banking-Standards-Deferred.md` is present. ADR-001 through ADR-004 are physically missing.
* **Risk:** P0 = Governance Blocker
* **Recommended Action:** Reconstruct and commit ADR-001, ADR-002, ADR-003, and ADR-004.
* **Sprint Assignment:** Sprint 14.8.6

### FND-002: Incomplete Enterprise Audit Logging Implementation
* **Verification Status:** VERIFIED
* **Evidence:** Inspected business models (`Vendor.php`, `PaymentVoucher.php`, `Forecast.php`, `BEOIssueLog.php`) and confirmed the absence of the `Spatie\Activitylog\Traits\LogsActivity` trait, which is properly applied to Foundation models.
* **Risk:** P1 = Security Foundation
* **Recommended Action:** Apply the `LogsActivity` trait systematically across all core business entities to ensure Tier 1 security compliance.
* **Sprint Assignment:** Sprint 14.8.7

### FND-003: Unresolved Technical Debt (TODOs) in Core Services
* **Verification Status:** VERIFIED
* **Evidence:** Found explicitly bypassed logic via code inspection:
  - `StockCountSessionService.php`: `// TODO: Dispatch Foundation Approval Engine workflow event here.`
  - `PurchaseRequestService.php`: `// TODO: Budget integration will be implemented later in Budgeting/Finance sprint.`
* **Risk:** P2 = Enterprise Readiness
* **Recommended Action:** Address the identified TODOs by implementing the missing Approval Engine and Budgeting integrations.
* **Sprint Assignment:** Sprint 14.8.8

### FND-004: Potential Tenant/Property Leakage in Purchasing
* **Verification Status:** VERIFIED
* **Evidence:** The `2026_06_10_000002_create_vendors_table.php` migration file contains explicit comments highlighting structural ambiguity: `// Might be bound to Company instead of Property`.
* **Risk:** P3 = Optimization
* **Recommended Action:** Clarify the Vendor tenancy model in ADR-004 and standardize the database foreign keys accordingly.
* **Sprint Assignment:** Sprint 14.8.6

## Unverified Findings
* **None:** All findings presented in the V2 audit were successfully verified through direct repository inspection.

## False Positives
* **None:** The `ivorq_master_repository_audit_v2.md` contains no false positives. Every identified issue corresponds to a genuine vulnerability, technical debt, or documentation gap in the current codebase.

## False Negatives
* **Legacy Audit Failure:** The legacy `repository-audit-2026-06.md` was a 100% false negative that completely masked the platform's drift.
* **V2 Audit Completeness:** No additional false negatives were identified within the scope of the V2 audit during this recovery review. The V2 audit accurately captures the current state of governance and architecture gaps.

## Governance Recovery Backlog

| Task ID | Description | Priority | Risk Category |
|---------|-------------|----------|---------------|
| GOV-01 | Reconstruct Missing ADRs (ADR-001 to ADR-004) | P0 | Governance Blocker |
| GOV-02 | Clarify Vendor Tenancy Model & Update Schema | P3 | Optimization |
| GOV-03 | System-Wide Audit Log Hardening (`LogsActivity`) | P1 | Security Foundation |
| GOV-04 | Approval Engine Integration for Stock Counts | P2 | Enterprise Readiness |
| GOV-05 | Budget Integration for Purchase Requests | P2 | Enterprise Readiness |

## Sprint Assignment Plan

### Sprint 14.8.6
**Focus: Governance & Tenancy**
* Reconstruct ADR-001 (Property Isolation).
* Reconstruct ADR-002 (Audit Trail Strategy).
* Reconstruct ADR-003 (Approval Engine).
* Reconstruct ADR-004 (Finance Module Boundary).
* Resolve GOV-02: Clarify Vendor tenancy boundaries and align migration schemas to eliminate potential data leakage.

### Sprint 14.8.7
**Focus: Security Foundation**
* Resolve GOV-03: Audit all Eloquent models within `Modules/` and enforce the implementation of the `LogsActivity` trait on all mutative entities to guarantee Tier 1 Security (Audit Log) compliance.

### Sprint 14.8.8
**Focus: Enterprise Readiness & Tech Debt**
* Resolve GOV-04: Wire the `StockCountSessionService` to the Foundation Approval Engine workflow.
* Resolve GOV-05: Implement cross-module budget validations within the `PurchaseRequestService`.
