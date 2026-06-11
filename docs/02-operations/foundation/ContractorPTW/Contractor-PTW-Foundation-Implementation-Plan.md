# IVORQ Contractor & PTW Foundation v2.5 — Implementation Plan

**Role:** Enterprise Operations Architect / EHS Architect
**Module:** Contractor & Permit To Work (PTW) Foundation
**Status:** READY FOR CTO REVIEW

---

## 1. Architecture Review
The Contractor & Permit To Work (PTW) Foundation is the enterprise governance layer for safety, environmental health, and vendor site access. It strictly enforces legal compliance, insurance viability, and physical safety constraints before any high-risk engineering action can occur.

**Core Philosophy:** Safety is non-negotiable. High-risk `WorkOrders` cannot transition from `Assigned` to `In Progress` unless a mathematically validated, fully approved `PermitToWork` is cryptographically signed and active.

The architecture comprises two main engines:
1. **Contractor Management Engine:** A registry validating third-party companies, their workers, inductions, insurance bounds, and safety certifications.
2. **Permit To Work Engine:** A dynamic approval state-machine mapping hazards, control measures, LOTO (Lockout/Tagout) isolations, and supervisor validations for high-risk work types.

---

## 2. Domain Relationships
The module operates entirely within the Operations domain but enforces hard blocks on the Engineering (Work Order) domain.

### Core Entities
- **ContractorCompany:** Top-level vendor containing insurance tracking, approved status, and blacklists.
- **ContractorWorker:** Individual human tracking, mapped to `ContractorCompany`, possessing specific `ContractorCertification`s and `ContractorInduction`s.
- **ContractorInsurance:** Expiry-bound policies protecting the property.
- **ContractorAccessPass:** Time-bound check-in/check-out tokens.
- **PermitToWork:** The core safety document. Defines the work type (Hot Work, LOTO, Height, etc.) and time constraints.
- **PermitRiskAssessment:** Child to PTW. Lists hazards, initial risk, control measures, and residual risk.
- **PermitIsolation:** Lockout/Tagout instructions mapping directly to the `Asset` Foundation.
- **PermitApproval:** The multi-level signature state machine.
- **PermitAttachment:** Hard link to the `Media` Foundation for method statements.
- **PermitAudit:** Read-only immutable safety snapshot.

### Integration Contracts
- **WorkOrderPermitRequirement:** Binds a Work Order to a mandatory Permit.
- **WorkOrderContractorAssignment:** Binds a Work Order to a `ContractorCompany`.
- **WorkOrderSafetyValidation:** Interceptor that halts WO timers if the PTW expires or is suspended.

---

## 3. Business Rules & Engines

### Contractor Validation Rules
- `ContractorCompany` must have an Active `ContractorInsurance` covering the exact date of work.
- `ContractorWorker` cannot receive a `ContractorAccessPass` if their `ContractorInduction` is expired.
- Blacklisted entities propagate down: If a Company is blacklisted, all Workers are instantly suspended.

### Risk Management Engine
- Calculates: `Hazard + Control Measures = Residual Risk`.
- If `Residual Risk > Threshold`, the PTW Approval state machine automatically escalates from `Supervisor` to `Engineering Manager` and `Safety Officer`.

### Approval Engine
- Hierarchical multi-level routing (Requester -> Supervisor -> Dept Head -> Safety Officer -> GM).
- If rejected, the PTW resets to `Draft` and requires new Risk Assessment acknowledgments.
- Approvals mandate Digital Signatures (stored via `Media` Foundation as hashed SVGs).

### Asset Isolation (LOTO)
- PTW directly links to the `Asset` module to document shutdown approvals.
- The Asset is flagged as "Isolated", preventing any concurrent Work Orders from bypassing safety.

---

## 4. Dependency Matrix
| Dependency | Type | Direction | Reason |
| :--- | :--- | :--- | :--- |
| **Property** | Hard | Upstream | Property Isolation global scopes. |
| **Media** | Hard | Upstream | Digital Signatures, Risk Assessments (PDF), Insurance policies via Cloudflare R2. |
| **Checklist** | Hard | Upstream | Pre-work, Safety, and Completion Checklists. |
| **Timeline** | Hard | Upstream | Immutable logs for Permit Created, Approved, Suspended, Closed. |
| **Asset** | Hard | Upstream | Asset Isolation (LOTO) and Risk Association. |
| **Incident** | Hard | Upstream | Safety Breaches and Near Misses linked to Contractors. |
| **Work Order** | Contract | Downstream | Work Orders require PTWs to start. |

---

## 5. Risk Matrix
| Risk | Probability | Impact | Mitigation Strategy |
| :--- | :--- | :--- | :--- |
| **Legal/Compliance Liability** | Low | Critical | Ensure `PermitAudit` creates a mathematically hashed, immutable JSON snapshot upon closure to prove compliance in court. |
| **Offline Signature Loss** | Medium | High | Mobile PWA utilizes IndexedDB. Signatures are queued locally and "First Sync Wins" logic applies upon reconnection. |
| **Insurance Expiry Drift** | Low | High | Background CRON sweep runs daily to auto-suspend `ContractorCompany` statuses if insurance lapses. |
| **Safety Bypass** | Low | Critical | `WorkOrderSafetyValidation` strictly intercepts Eloquent state transitions on the Work Order if the PTW is not mathematically valid. |

---

## 6. Open Questions (CTO Review Required)

> [!CAUTION]
> **Open Question 1:** Should a `ContractorWorker` be globally synced across the IVORQ multi-tenant cluster, or strictly isolated to the `property_id`? If a contractor works at two hotels in the same city, do they require two separate records and two inductions?

> [!WARNING]
> **Open Question 2:** For Emergency Work (e.g., Burst Pipe), standard PTW multi-level approval is too slow. Should we design an `Emergency Override` state that allows work to commence instantly, with the Risk Assessment verified *post-event*?

> [!IMPORTANT]
> **Open Question 3:** When a `PermitToWork` expires at 17:00, should the system *automatically* pause the attached `WorkOrder` and alert the Safety Officer, or does it simply flag the permit as a violation for audit purposes while letting the work continue?

---

## 7. CTO Recommendations
1. **Scalability:** The `PermitAudit` table must use native PostgreSQL Year/Month partitioning. Permits generate massive compliance paper trails that must not slow down operational queries.
2. **Safety:** Implement strict Database Transactions (ACID) when a PTW is approved to simultaneously update the `Asset` lockout state and unblock the `WorkOrder`.
3. **Mobile (PWA):** QR Codes (`ivorq://ptw/{ulid}`) must be printed on physical permit boards. Technicians scan it to view live residual risk and digitally sign on their mobile devices.
4. **Audit:** Hashed snapshotting. Once a Permit is `Closed`, no user (not even super-admins) can modify the fields.

---

## 8. Compliance Readiness
The architecture explicitly maps to **ISO 45001** (Occupational Health and Safety) requirements:
- **Government Audits:** Time-stamped Digital Signatures and immutable `PermitAudit` logs.
- **Insurance Audits:** Direct correlation between `ContractorInsurance` validity dates and `ContractorAccessPass` issuance.
- **Legal Investigations:** Hard linking between `Incident` (Near Misses) and `ContractorWorker` / `PermitToWork` violations.

---

## 9. Future Expansion Strategy
- **IoT Integration (BMS):** Future ability to link Asset Isolation (LOTO) directly to Building Management System sensors to verify power is actually off before the PTW can be approved.
- **Visitor Management Kiosks:** Expanding the `ContractorAccessPass` API to support self-service iPad check-in kiosks at the loading dock.
- **Facial Recognition Integration:** API contracts prepared to accept third-party biometric verification for `ContractorWorker` access.

---

## 10. Sprint Readiness
| Phase | Status |
| :--- | :--- |
| Governance Protocols Reviewed | **Yes** |
| Architecture Documented | **Yes** |
| Database Schemas Defined | **Pending Approval** |
| Cross-Module Contracts Defined | **Yes** |

**Status:** READY FOR CTO REVIEW
