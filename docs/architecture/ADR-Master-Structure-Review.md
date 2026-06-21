# IVORQ ADR Master Structure Review

## 1. Executive Summary
* **Current ADR count:** 29 (ADR-001 through ADR-029)
* **Overall structural health:** Strong. The architecture is mathematically sound, adhering to IFRS/GAAP standards and addressing hospitality-specific edge cases (e.g., negative stock, recipe yields, intercompany transfers).
* **Evidence-based assessment:** The ADRs correctly isolate operational workflows from financial ledgers, preventing monolithic entanglement. However, some fragmentation exists in domain-specific workflows (e.g., Bank Reconciliation, Revenue, Intercompany) which could benefit from logical consolidation, and Security Governance (ADR-029) is currently too broad.
* **Recommended ADR target range:** Keep historical ADRs intact. The healthy target is approximately 37 to 41 ADRs, not 80 ADRs. ADR-001 through ADR-029 remain immutable historical architecture decisions. Do not retroactively rewrite approved architecture history. Future consolidation must use umbrella or superseding ADRs with explicit links to prior ADRs. New ADRs are created only for durable, cross-domain, high-impact, or difficult-to-reverse decisions. Workflow detail, operational rules, UI behavior, API field definitions, and implementation detail should normally live in PRDs, specifications, or technical design documents rather than new ADRs.

## 2. ADR Inventory Matrix

| Number | Title | Domain | Status | Recommendation | Rationale | Dependencies |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| ADR-001 | Multi-Tenant Hierarchy | Core | Active | KEEP | Foundational architecture boundary. | None |
| ADR-002 | Audit Trail Strategy | Core/Security | Active | KEEP | Critical compliance standard. | ADR-001 |
| ADR-003 | Approval Engine | Core/Workflow | Active | KEEP | Cross-module governance mechanism. | ADR-001, ADR-002 |
| ADR-004 | Finance Module Boundary | Finance | Active | KEEP | Establishes crucial operational separation. | ADR-001, 002, 003 |
| ADR-005 | Banking Standards Deferred | Finance | Approved | MERGE CANDIDATE | Simple deferral; belongs logically in the Bank Rec domain. | None |
| ADR-006 | Vendor Ownership Model | Purchasing | Accepted | KEEP | Critical multi-tenant data decision. | ADR-001, ADR-004 |
| ADR-007 | Reconciliation Architecture Finalization | Finance | Approved | MERGE CANDIDATE | 1-to-1 matching rule belongs in ADR-019. | None |
| ADR-008 | Inventory Ledger Architecture | Inventory | Proposed | KEEP | Immutable quantity tracking foundation. | None |
| ADR-009 | Inventory Location Strategy | Inventory | Proposed | KEEP | Hierarchical physical storage rules. | ADR-008 |
| ADR-010 | Inventory Valuation Strategy | Cost Control | Proposed | KEEP | AVCO property-level math. | ADR-008, ADR-009 |
| ADR-011 | GRNI Architecture | Finance/AP | Proposed | KEEP | Accrual bridge for receiving. | ADR-010 |
| ADR-012 | Cost Ledger Architecture | Finance | Proposed | KEEP | Financial bridge for inventory. | ADR-008, 009, 010, 011 |
| ADR-013 | Period Closing Strategy | Finance | Proposed | KEEP | Temporal financial boundaries. | ADR-008 to 012 |
| ADR-014 | PPV & Variance Governance | Finance | Proposed | KEEP | Strict accounting for discrepancies. | ADR-008 to 013 |
| ADR-015 | Landed Cost & Tax Apportionment | Purchasing | Proposed | KEEP | True COGS calculation mechanism. | ADR-010, ADR-011, ADR-012, ADR-014 |
| ADR-016 | Subledger Reconciliation Framework | Finance | Proposed | KEEP | Critical anti-drift audit mechanism. | ADR-012, 011, 013 |
| ADR-017 | Event Driven Accounting & Queues | Core/Infra | Proposed | KEEP | Asynchronous safety for ledgers. | ADR-012, ADR-016 |
| ADR-018 | Foreign Currency Revaluation | Finance | Proposed | KEEP | Multi-currency FX rules. | ADR-012, ADR-013 |
| ADR-019 | Payment & Bank Reconciliation Engine | Finance | Proposed | KEEP | Master architecture for cash/banking. | ADR-007, ADR-018 |
| ADR-020 | Recipe & Production Yield | F&B / Cost | Proposed | KEEP | Crucial hospitality kitchen math. | ADR-008 to 014 |
| ADR-021 | POS Integration & Sales Depletion | F&B / POS | Proposed | KEEP | Revenue to COGS depletion boundary. | ADR-012, ADR-014, ADR-020 |
| ADR-022 | Actual vs Theoretical Variance | F&B / Audit | Proposed | KEEP | Shrinkage vs Waste separation. | ADR-008 to 021 |
| ADR-023 | Intercompany Transfer Ledger | Finance | Proposed | KEEP INDEPENDENT | Cross-entity physical moves. | ADR-009, ADR-016, ADR-018 |
| ADR-024 | Consolidation & Elimination | Finance | Proposed | KEEP INDEPENDENT | Group-level financial math. | ADR-023, ADR-018 |
| ADR-025 | Revenue Recognition & Tax | Revenue/Fin | Proposed | KEEP INDEPENDENT | Base IFRS 15 rules. | ADR-012, ADR-021, ADR-013 |
| ADR-026 | Inclusive Package Allocation | Revenue/Fin | Proposed | KEEP INDEPENDENT | IFRS 15 bundled revenue rules. | ADR-025 |
| ADR-027 | Fixed Asset & Depreciation | Finance | Proposed | KEEP | CapEx and component accounting. | ADR-013, ADR-023, ADR-024 |
| ADR-028 | Accounts Receivable & Ledgers | Finance/AR | Proposed | KEEP | City vs Guest ledger boundaries. | ADR-025, ADR-019, ADR-018, ADR-016 |
| ADR-029 | Security, Roles & Permissions | Security | Proposed | SPLIT CANDIDATE | Massively broad governance. | Explicit dependencies verified from document only: ADR-003, ADR-024 |

## 3. Consolidation Analysis

### A. Cost Ledger Architecture (ADR-010, 011, 012, 014, 015)
* **Hypothesis:** Merge ADR-010, ADR-011, ADR-014, and ADR-015 into ADR-012.
* **Evidence for:** They all dictate the values that ultimately post into the Cost Ledger.
* **Evidence against:** ADR-012 is a structural definition of a subledger. ADR-010 (AVCO), ADR-011 (GRNI), ADR-014 (Variance), and ADR-015 (Landed Cost) are distinct, massive mathematical/business policies spanning Procurement, Operations, and AP. Merging them would create an unreadable, monolithic document.
* **Final recommendation:** **KEEP INDEPENDENT**.
* **Governance impact:** Maintains clear Separation of Concerns. Operational leaders can read ADR-010 without needing to understand the database queues in ADR-012.

### B. Payment & Bank Reconciliation (ADR-005, 007, 019)
* **Hypothesis:** Merge ADR-005 and ADR-007 into ADR-019.
* **Evidence for:** ADR-005 is a minor deferral note. ADR-007 finalized the 1-to-1 rule that ADR-019 explicitly relies upon. They all belong to the Cash/Banking domain.
* **Evidence against:** None. ADR-005 and 007 are fragments.
* **Final recommendation:** **MERGE CANDIDATE**.
* **Governance impact:** Reduces fragmentation. ADR-019 should act as the master "Umbrella ADR" for Banking.
* **Migration approach:** Retain files ADR-005 and 007 for historical compliance, but mark them "Superseded by Umbrella ADR-019" (or a future consolidated Banking ADR).

### C. Cost Control / Production Yield (ADR-020, 022)
* **Hypothesis:** Merge ADR-020 and ADR-022.
* **Evidence for:** Both deal with F&B food cost.
* **Evidence against:** ADR-020 governs Kitchen Production (Recipes, Yield). ADR-022 governs Audit/Governance (Investigating missing inventory). They target different personas (Executive Chef vs F&B Controller).
* **Final recommendation:** **KEEP INDEPENDENT**.
* **Governance impact:** Maintains clear workflow boundaries.

### D. Revenue Recognition (ADR-025, 026)
* **Hypothesis:** Merge ADR-025 and ADR-026.
* **Evidence for:** Both are strictly dictated by IFRS 15. Package allocation (026) is just a complex execution of the core deferred revenue principles in 025.
* **Evidence against:** ADR-025 defines core revenue recognition and tax principles. ADR-026 governs complex allocation scenarios, loyalty liabilities, and gift-card related obligations. ADR-026 is a material liability and allocation domain, not merely a minor sub-section.
* **Final recommendation:** **KEEP INDEPENDENT**.
* **Governance impact:** They should remain separate but include explicit cross-references within a future conceptual "Revenue Accounting" domain.

### E. Group Accounting (ADR-023, 024)
* **Hypothesis:** Merge ADR-023 and ADR-024.
* **Evidence for:** Intercompany AP/AR (023) exists purely because of multi-entity boundaries, and Consolidation (024) exists purely to eliminate those exact boundaries.
* **Evidence against:** ADR-023 governs operational and financial intercompany movements, including transfer-ledger behavior and physical inventory transit. ADR-024 governs group-level consolidation, elimination, reporting, and close activities. They are related but occur at different operational layers and different frequencies.
* **Final recommendation:** **KEEP INDEPENDENT**.
* **Governance impact:** They must be cross-referenced under a future conceptual "Group Accounting" umbrella, but must not be merged or superseded now.

### F. Security Governance (ADR-029)
* **Hypothesis:** Split ADR-029.
* **Evidence for:** ADR-029 covers too much ground: RBAC, Segregation of Duties (SoD), Audit Logging, Session Management, and Break-Glass protocols.
* **Evidence against:** None.
* **Final recommendation:** **SPLIT CANDIDATE**.
* **Governance impact:** Separating Identity/Auth from SoD and Audit Logging will allow focused ITGC audits.

## 4. Dependency Map

The architectural flow correctly isolates concerns while building upon dependencies:

1. **Tenant Hierarchy (ADR-001)** - The physical/logical foundation.
2. **Security & Audit (ADR-029, 002)** - Protects the hierarchy.
3. **Approval (ADR-003)** - Governs the workflows.
4. **Inventory (ADR-008, 009)** - Physical stock reality.
5. **Cost Ledger & Valuation (ADR-010, 012, 014, 015)** - Assigns value to physical stock.
6. **Finance / AP / AR (ADR-004, 011, 028)** - Connects value to external vendors/guests.
7. **Revenue (ADR-021, 025, 026)** - Inbound cash logic.
8. **Intercompany & Consolidation (ADR-023, 024)** - Multi-entity reporting.

**Unclear Dependencies:**
* The dependency between PMS Night Audit and Revenue Recognition (ADR-025) is stated but the exact mechanism of the Night Audit is an undocumented black box.
* **Note:** Dependency entries must be evidence-based and verified directly from the source ADR text, not inferred from broad domain relevance.

## 5. Missing Architecture Domains

* **Night Audit & Daily Rolling Engine**
  * **Criticality:** High.
  * **When required:** Mandatory before related future module starts (PMS).
  * **Why:** Controls the rolling of the business date and the recognition of Room Revenue (ADR-025).
* **Tax Calculation Engine Architecture**
  * **Criticality:** High.
  * **When required:** Mandatory before production enterprise deployment.
  * **Why:** Touches Landed Cost (ADR-015), AP, AR, and Revenue (ADR-025). Requires distinct architectural rules for compounding vs flat taxes.
* **Purchasing & Procurement Workflows**
  * **Criticality:** Medium.
  * **When required:** Mandatory before next implementation stage.
  * **Why:** ADR-006 exists for vendors, but the PO/PR lifecycle is assumed, not documented.
* **PMS (Front Office / Reservations)**
  * **Criticality:** Future.
  * **When required:** Required before related future module starts.
* **HRIS (Payroll / Labor Cost)**
  * **Criticality:** Future.
  * **When required:** Future optional expansion.

## 6. ADR Numbering Strategy

**Recommendation:** ADR-001 through ADR-029 remain immutable historical architecture decisions. Continue numbering from ADR-030. Never retroactively rewrite approved architecture history. Future consolidation must use umbrella or superseding ADRs with explicit links to prior ADRs. New ADRs are created only for durable, cross-domain, high-impact, or difficult-to-reverse decisions. Workflow detail, operational rules, UI behavior, API field definitions, and implementation detail should normally live in PRDs, specifications, or technical design documents rather than new ADRs.

## 7. Recommended Next ADRs

### ADR-030 — Identity, Authentication and Session Governance
* **Purpose:** Separate identity, MFA, SSO, session lifecycle, session revocation, authentication methods, and emergency access boundaries from the broad ADR-029 security document. Required before broader enterprise production hardening.
* **Priority:** High
* **Classification:** Mandatory before enterprise production release
* **Dependencies:** ADR-001, ADR-002, ADR-029

### ADR-031 — Data Privacy, PII, Retention and Data Residency Governance
* **Purpose:** Define data classification, guest/staff/vendor PII, retention, lawful handling, masking, deletion/anonymization boundaries, legal holds, privacy-aware audit logs, tenant data residency, and payment-token/PCI boundary principles. Do not claim legal compliance automatically; jurisdiction-specific obligations must remain configurable and subject to legal review.
* **Priority:** High
* **Classification:** Mandatory before enterprise production release
* **Dependencies:** ADR-001, ADR-002, ADR-029

### ADR-032 — Purchasing, Procurement and Contract Management Architecture
* **Purpose:** Define the PR, RFQ, quotation, approval, PO, receiving intent, vendor contract, amendment, commitment, and procurement-to-AP boundaries. Close the gap between Vendor Ownership, GRNI, landed cost, approval, and AP matching.
* **Priority:** High
* **Classification:** Mandatory before next Finance implementation stage
* **Dependencies:** ADR-003, ADR-004, ADR-006, ADR-011, ADR-015

### ADR-033 — Global Tax and Jurisdiction Compliance Architecture
* **Purpose:** Define configurable tax calculation, tax determination, compounding/stacking, inclusive/exclusive tax handling, jurisdiction rules, tax reporting boundaries, and finance integration. Do not claim country-specific compliance without a verified legal and tax design.
* **Priority:** Medium-High
* **Classification:** Mandatory before multi-jurisdiction enterprise release
* **Dependencies:** ADR-015, ADR-018, ADR-025, ADR-028

### ADR-034 — Night Audit and Hospitality Business Date Architecture
* **Purpose:** Define PMS business date, night audit sequencing, operational cut-off, financial posting boundaries, exception handling, re-open policy, and relationship to period closing and revenue recognition. This is required when PMS implementation begins; it is not an immediate defect in the current Finance Foundation.
* **Priority:** High for PMS phase
* **Classification:** Required before PMS implementation begins
* **Dependencies:** ADR-013, ADR-025, ADR-028

## 8. Final Verdict

**Finance Foundation Ready**

The Finance, Inventory, Cost Control, Accounting, AR, and Security foundations are architecturally strong for the current roadmap scope. IVORQ has successfully architected a world-class, hospitality-specific financial backend. The rules governing Inventory, Valuations, AP, AR, and Reconciliations are mathematically sound and audit-ready. 

IVORQ is not yet Enterprise Hospitality Ready because PMS, Night Audit, privacy/data residency hardening, identity/session hardening, procurement workflow architecture, and tax governance are not yet completed. Unstarted future modules such as PMS, Housekeeping, Engineering, and HRIS must not be treated as defects in the current Finance Foundation.
