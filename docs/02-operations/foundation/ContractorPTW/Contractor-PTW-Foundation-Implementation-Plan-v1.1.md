# IVORQ Contractor & PTW Foundation v2.5 — Implementation Plan v1.1

**Role:** Enterprise Operations Architect / EHS Architect
**Module:** Contractor & Permit To Work (PTW) Foundation
**Status:** READY FOR FINAL CTO LOCK

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
- **ContractorWorkerGlobal:** Global entity representing the human across the entire cluster.
- **ContractorWorkerPropertyProfile:** Property-specific profile tracking inductions, passes, and violations at a single site.
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

### Hybrid Contractor Worker Model
- Uses a decoupled architecture where a single human is represented by a `ContractorWorkerGlobal` entity across the SaaS instance, but requires a `ContractorWorkerPropertyProfile` for each hotel they work at.
- Training and Induction tracking is managed at the `PropertyProfile` level.

### Contractor Validation Rules
- `ContractorCompany` must have an Active `ContractorInsurance` covering the exact date of work.
- `ContractorWorkerPropertyProfile` cannot receive a `ContractorAccessPass` if their `ContractorInduction` is expired.
- Blacklisted entities propagate down: If a Company is blacklisted, all Workers are instantly suspended.

### Risk Management Engine
- Calculates: `Hazard + Control Measures = Residual Risk`.
- If `Residual Risk > Threshold`, the PTW Approval state machine automatically escalates from `Supervisor` to `Engineering Manager` and `Safety Officer`.

### Approval Engine
- Hierarchical multi-level routing (Requester -> Supervisor -> Dept Head -> Safety Officer -> GM).
- If rejected, the PTW resets to `Draft` and requires new Risk Assessment acknowledgments.
- Approvals mandate Digital Signatures (stored via `Media` Foundation as hashed SVGs).

### Emergency Override Workflow
- Standard multi-level approvals can be bypassed using an `Emergency Override`.
- Requires a **Mandatory Reason**.
- Triggers a **Post-event Risk Assessment** and mandates retroactive **GM / Safety Officer Sign-off**.
- Triggers an **Incident Auto-link** automatically linking the work to a safety/emergency incident report.
- Every step of the override is indelibly logged to the **Timeline Audit**.

### Permit Expiry Enforcement
- A background worker continually evaluates active permits.
- If a PTW expires, the system **Auto-pauses** any related `Work Order` immediately.
- Emits real-time **Safety Officer and Supervisor notifications**.
- Demands a **Revalidation Requirement** to resume work, establishing a new time block.

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
| **Incident** | Hard | Upstream | Safety Breaches, Emergency Overrides, and Near Misses linked to Contractors. |
| **Work Order** | Contract | Downstream | Work Orders require PTWs to start, paused on expiry. |
| **Access Control** | Contract | Downstream | Integration with physical security gates and visitor passes. |

---

## 5. Integrations & Dashboards

### Access Control Integration
- The Foundation enables integration with physical hotel security through structured logic for:
  - **Gate Access** controls and security checkpoint validations.
  - Issuance of **Visitor Badges**.
  - Check-in/Check-out via **Contractor QR Pass**.

### PTW Command Board
- Specialized high-level UI providing real-time oversight to Safety Officers and Management:
  - **Active Permits** currently running.
  - **Expiring Soon** visual alerts.
  - **Expired Permits** highlighting safety breaches.
  - **High-Risk Work** tracking.
  - Live count of **Contractors On Site**.
  - Dedicated pane for monitoring active **Emergency Overrides**.

---

## 6. Risk Matrix
| Risk | Probability | Impact | Mitigation Strategy |
| :--- | :--- | :--- | :--- |
| **Legal/Compliance Liability** | Low | Critical | Ensure `PermitAudit` creates a mathematically hashed, immutable JSON snapshot upon closure to prove compliance in court. |
| **Offline Signature Loss** | Medium | High | Mobile PWA utilizes IndexedDB. Signatures are queued locally and "First Sync Wins" logic applies upon reconnection. |
| **Insurance Expiry Drift** | Low | High | Background CRON sweep runs daily to auto-suspend `ContractorCompany` statuses if insurance lapses. |
| **Safety Bypass** | Low | Critical | `WorkOrderSafetyValidation` strictly intercepts Eloquent state transitions on the Work Order if the PTW is not mathematically valid. |

---

## 7. CTO Recommendations (Incorporated & Approved)
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
- **Facial Recognition Integration:** API contracts prepared to accept third-party biometric verification for `ContractorWorkerGlobal` access.

---

## 10. Sprint Readiness
| Phase | Status |
| :--- | :--- |
| Governance Protocols Reviewed | **Yes** |
| Architecture Documented | **Yes (v1.1)** |
| Database Schemas Defined | **Pending Implementation Approval** |
| Cross-Module Contracts Defined | **Yes** |

**Status:** READY FOR FINAL CTO LOCK
