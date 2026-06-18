# Mandatory Audit Entity Matrix

## Executive Summary
This document serves as the authoritative source of truth for the IVORQ platform's audit logging coverage, detailing which entities require auditing, the severity of those audits, retention policies, and visibility boundaries. Currently, audit coverage across IVORQ is incomplete (approximately 8.1%). This matrix bridges the governance gap identified in the Security Hardening Plan.

It is heavily integrated with the core architectural pillars:
* **ADR-001 (Multi-Tenant Hierarchy):** Enforces tenant and property isolation on all audit logs.
* **ADR-002 (Audit Trail Strategy):** Mandates the usage of `spatie/laravel-activitylog` and append-only database operations.
* **ADR-003 (Approval Engine Architecture):** Requires all state changes via approvals, rejections, and overrides to be immutably recorded.
* **ADR-004 (Finance Module Boundary Architecture):** Protects the General Ledger and financial operations with strict audit scrutiny and segregation of duties.

## Audit Classification Model
* **Mandatory:** Core business, financial, security, and access control entities. Auditing MUST be implemented prior to deploying the module to production.
* **Recommended:** Supporting operational entities where historical tracking aids troubleshooting but is not critical for financial or security compliance.
* **Optional:** Ephemeral data, high-volume telemetry, or basic lookup tables. Auditing is typically disabled to conserve storage.

## Severity Model
* **Critical:** Actions that alter security boundaries, financial ledgers, or enterprise access. 
  * *Examples:* User Disable, Role Change, Permission Change, Budget Override, Payment Approval, API Token generation.
* **High:** Actions that affect significant monetary value, operational commitments, or external vendor relationships.
  * *Examples:* Purchase Order Approval, Vendor Modification, Forecast Revision, BEO Contract Signing.
* **Medium:** Standard operational workflow state changes.
  * *Examples:* Status Changes (e.g., PO Sent), Assignment Changes, basic CRUD on non-financial operational records.
* **Low:** Metadata updates or reference data changes.
  * *Examples:* Reference Data Updates, Description changes on a Work Order.

## Retention Model
* **Critical (7 Years):** Required by financial regulations (e.g., Sarbanes-Oxley, IRS) for general ledgers, tax documents, and high-level security changes.
* **High (7 Years):** Operational documents with financial implications (POs, BEOs) must align with financial retention.
* **Medium (3 Years):** Operational workflows (Work Orders, Guest Requests) retained for liability and historical trending.
* **Low (1 Year):** General application usage tracking.

*Rationale:* Storage must be partitioned. Active database retention is 12 months, followed by cold storage archiving for the remainder of the retention period, per ADR-002.

## Visibility Model
Integrated deeply with **ADR-001**:
* **Enterprise Visibility:** IVORQ Platform Administrators. Strictly limited to system-level entities. Tenant operational logs are blinded unless "break-glass" support access is granted.
* **Tenant Visibility:** Tenant/Corporate Auditors. Can view all audit logs across all Properties owned by the specific Tenant.
* **Property Visibility:** Property Managers/Controllers. Can view audit logs restricted exclusively to their assigned `property_id`.
* **Auditor Visibility:** Read-only access granted to external compliance auditors for a specific time window and Tenant scope.
* **Cross-Tenant Restrictions:** Hard physical restriction; no user may ever query an audit log without an enforced `tenant_id` scope.

---

## Mandatory Audit Entity Matrix

### Foundation Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `User` | Mandatory | Critical | 7 Years | Tenant | Create, Update, Delete, Status Change, Assignment Change |
| `Role` | Mandatory | Critical | 7 Years | Tenant | Create, Update, Delete, Assignment Change |
| `Permission` | Mandatory | Critical | 7 Years | Tenant | Create, Update, Delete |
| `Department` | Recommended | Low | 1 Year | Property | Create, Update, Delete |
| `Property` | Mandatory | Critical | 7 Years | Tenant | Create, Update, Status Change |
| `Tenant` | Mandatory | Critical | 7 Years | Enterprise | Create, Update, Status Change |
| `CloudName` | Mandatory | Critical | 7 Years | Enterprise | Create, Update |
| `Session` | Mandatory | High | 1 Year | Tenant | Create (Login), Delete (Logout) |
| `APIToken` | Mandatory | Critical | 7 Years | Tenant | Create, Delete, Override |

### Purchasing Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `Vendor` | Mandatory | High | 7 Years | Tenant | Create, Update, Delete, Status Change |
| `PurchaseRequest`| Mandatory | Medium | 3 Years | Property | Create, Update, Approve, Reject, Cancel |
| `PurchaseOrder` | Mandatory | High | 7 Years | Property | Create, Update, Approve, Reject, Cancel, Override |
| `RFQ` | Recommended | Medium | 3 Years | Property | Create, Update, Status Change |
| `Quotation` | Mandatory | High | 7 Years | Property | Create, Update, Approve, Reject |
| `Receiving` | Mandatory | High | 7 Years | Property | Create, Update, Status Change |
| `Return` | Mandatory | High | 7 Years | Property | Create, Update, Approve |

### Inventory Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `InventoryItem` | Mandatory | Medium | 3 Years | Tenant | Create, Update, Delete |
| `Store` | Recommended | Low | 1 Year | Property | Create, Update |
| `StockMovement` | Mandatory | High | 7 Years | Property | Create, Status Change, Approve |
| `StockCount` | Mandatory | High | 7 Years | Property | Create, Update, Approve, Override |
| `StockAdjustment`| Mandatory | Critical | 7 Years | Property | Create, Approve, Reject, Override |
| `Recipe` | Mandatory | Medium | 3 Years | Tenant | Create, Update, Delete |
| `Yield` | Recommended | Low | 1 Year | Property | Create, Update |

### Cost Control Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `CostPosting` | Mandatory | High | 7 Years | Property | Create, Update, Override |
| `Variance` | Mandatory | High | 7 Years | Property | Create, Status Change, Approve |
| `Consumption` | Mandatory | Medium | 3 Years | Property | Create, Update |
| `ParLevel` | Recommended | Medium | 3 Years | Property | Create, Update, Override |

### Finance Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `Budget` | Mandatory | Critical | 7 Years | Property | Create, Approve, Override |
| `BudgetRevision` | Mandatory | Critical | 7 Years | Property | Create, Approve, Reject, Override |
| `Forecast` | Mandatory | High | 7 Years | Property | Create, Approve |
| `ForecastRevision`| Mandatory | High | 7 Years | Property | Create, Approve, Reject |
| `PaymentVoucher` | Mandatory | Critical | 7 Years | Property | Create, Update, Approve, Reject, Cancel |
| `FinancialPeriod`| Mandatory | Critical | 7 Years | Property | Create, Status Change, Override |

### Accounting Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `JournalEntry` | Mandatory | Critical | 7 Years | Property | Create, Update, Override, Status Change |
| `JournalLine` | Mandatory | Critical | 7 Years | Property | Create, Update |
| `Invoice` | Mandatory | High | 7 Years | Property | Create, Update, Approve, Reject, Cancel |
| `AccountsPayable`| Mandatory | Critical | 7 Years | Property | Create, Update, Approve |
| `AccountsReceivable`| Mandatory| Critical | 7 Years | Property | Create, Update, Approve |
| `BankRecon` | Mandatory | Critical | 7 Years | Property | Create, Update, Approve, Status Change |

### Sales & Event Management Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `BEO` | Mandatory | High | 7 Years | Property | Create, Update, Approve, Reject, Cancel, Status Change |
| `BEORevision` | Mandatory | High | 7 Years | Property | Create, Approve, Reject |
| `EventBooking` | Mandatory | Medium | 3 Years | Property | Create, Update, Cancel, Status Change |
| `EventContract` | Mandatory | High | 7 Years | Property | Create, Update, Approve, Reject |

### Front Office Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `Reservation` | Mandatory | High | 7 Years | Property | Create, Update, Cancel, Status Change |
| `CheckIn` | Mandatory | Medium | 3 Years | Property | Create, Override |
| `CheckOut` | Mandatory | High | 7 Years | Property | Create, Override |
| `RoomMove` | Mandatory | Medium | 3 Years | Property | Create, Approve |
| `RateOverride` | Mandatory | Critical | 7 Years | Property | Create, Approve, Reject, Override |

### Housekeeping Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `RoomStatus` | Recommended | Low | 1 Year | Property | Create, Update, Status Change |
| `Inspection` | Recommended | Low | 1 Year | Property | Create, Update |
| `LostAndFound` | Mandatory | High | 3 Years | Property | Create, Update, Status Change, Ownership Change |

### Engineering Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `WorkOrder` | Recommended | Medium | 3 Years | Property | Create, Update, Cancel, Status Change, Assignment Change |
| `MaintenanceReq` | Recommended | Low | 1 Year | Property | Create, Update |
| `Asset` | Mandatory | High | 7 Years | Property | Create, Update, Delete, Ownership Change |
| `CAPEX` | Mandatory | Critical | 7 Years | Property | Create, Approve, Reject, Cancel, Override |

### Project Management Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `Project` | Mandatory | High | 7 Years | Tenant | Create, Update, Approve, Status Change |
| `Task` | Recommended | Low | 1 Year | Tenant | Create, Update, Assignment Change |
| `Milestone` | Recommended | Medium | 3 Years | Tenant | Create, Update, Status Change |
| `Issue` | Recommended | Low | 1 Year | Tenant | Create, Update |
| `ChangeRequest` | Mandatory | High | 7 Years | Tenant | Create, Approve, Reject, Override |

### Future PMS Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `GuestProfile` | Mandatory | High | 7 Years | Tenant | Create, Update, Delete |
| `Folio` | Mandatory | Critical | 7 Years | Property | Create, Update, Status Change |
| `NightAudit` | Mandatory | Critical | 7 Years | Property | Create, Approve, Status Change, Override |
| `RoomCharge` | Mandatory | High | 7 Years | Property | Create, Update, Cancel |
| `Posting` | Mandatory | Critical | 7 Years | Property | Create, Update, Override |

### Future HRIS Domain
| Entity | Classification | Severity | Retention | Visibility | Required Audit Actions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `Employee` | Mandatory | Critical | 7 Years | Tenant | Create, Update, Delete, Status Change |
| `Payroll` | Mandatory | Critical | 7 Years | Property | Create, Approve, Override |
| `LeaveRequest` | Mandatory | Medium | 3 Years | Property | Create, Approve, Reject |
| `Attendance` | Mandatory | Medium | 3 Years | Property | Create, Update, Override |
| `DisciplinaryAction`| Mandatory| High | 7 Years | Tenant | Create, Update, Approve |

---

## Audit Event Matrix

| Event Category | Severity | Retention | Notification Required | Approval Required | Audit Required |
| :--- | :--- | :--- | :--- | :--- | :--- |
| Financial Transaction Posting | Critical | 7 Years | No | Yes | Yes |
| Security/Role Alteration | Critical | 7 Years | Yes | Yes | Yes |
| Workflow Escalation/Override | Critical | 7 Years | Yes | No | Yes |
| AP/AR Invoice Processing | High | 7 Years | No | Yes | Yes |
| Vendor Onboarding | High | 7 Years | No | Yes | Yes |
| Operational Workflow (BEO, WO) | Medium | 3 Years | No | No | Yes |
| Master Data Sync | Low | 1 Year | No | No | Optional |

---

## Security Requirements
* **ADR-001 Integration:** `tenant_id` and `property_id` MUST be physically written into every audit log row.
* **ADR-002 Integration:** All logs are strictly append-only.
* **ADR-003 Integration:** Every state transition within the Approval Engine automatically generates an audit log identifying the actor.
* **ADR-004 Integration:** Finance logs (`JournalEntry`, `Budget`, `AccountsPayable`) operate under maximum retention and security scrutiny.

## Anti-Patterns
* **Selective Auditing:** Turning off `LogsActivity` for a `Mandatory` entity during bulk updates to "save time."
* **Silent Modifications:** Updating records via direct database queries without triggering Eloquent events.
* **Audit Bypassing:** Using `withoutEvents()` during regular application workflows.
* **Cross-Tenant Visibility:** Executing log queries without global tenant scopes applied.
* **Audit Deletion:** Issuing `DELETE` or `TRUNCATE` commands against the audit log tables.
* **Audit Suppression:** Suppressing audit notifications for Critical severity events.

---

## Implementation Guidance
Given the extensive scope, implementation is prioritized by business risk:

* **Phase 1 (Immediate):** Foundation, Finance, Accounting, and Cost Control. (Highest financial and security risk).
* **Phase 2 (Near-Term):** Purchasing, Inventory, Sales & Event Management. (High operational risk).
* **Phase 3 (Mid-Term):** Front Office, Housekeeping, Engineering, Project Management. (Moderate risk).
* **Phase 4 (Future):** PMS and HRIS domains (to be implemented concurrently with module development).

---

## Final Recommendation

**Top 25 Highest-Risk Entities:**
1. `User`
2. `Role`
3. `Permission`
4. `Tenant`
5. `APIToken`
6. `StockAdjustment`
7. `Budget`
8. `BudgetRevision`
9. `PaymentVoucher`
10. `FinancialPeriod`
11. `JournalEntry`
12. `JournalLine`
13. `AccountsPayable`
14. `AccountsReceivable`
15. `BankRecon`
16. `RateOverride`
17. `CAPEX`
18. `Folio`
19. `NightAudit`
20. `Posting`
21. `Employee`
22. `Payroll`
23. `PurchaseOrder`
24. `BEO`
25. `Vendor`

**Immediate Rollout Candidates (Phase 1):**
All Foundation (`User`, `Role`, `Permission`) and Accounting (`JournalEntry`, `PaymentVoucher`, `FinancialPeriod`) entities must have `LogsActivity` enforced in the immediate sprint to secure the platform baseline.
