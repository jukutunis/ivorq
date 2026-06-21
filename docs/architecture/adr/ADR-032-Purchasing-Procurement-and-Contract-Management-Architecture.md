# ADR-032: Purchasing, Procurement and Contract Management Architecture

## Status
Proposed

## Context
IVORQ is a multi-tenant, multi-property hospitality SaaS platform operating under the hierarchy: Enterprise → Tenant → Property. Purchasing and procurement are foundational to hospitality operations, intersecting heavily with Inventory, Finance, and AP workflows. The current IVORQ architecture already governs Vendor Ownership (ADR-006), Approval Workflows (ADR-003), Inventory Movements (ADR-008), GRNI (ADR-011), Cost Ledger (ADR-012), and Landed Cost (ADR-015). A dedicated Procurement architecture is now required to define the commercial intent, vendor selection, quotation, contract lifecycle, and purchasing execution boundaries before handing off to these existing operational and financial engines.

## Decision Drivers
- **Separation of Concerns:** Procurement establishes commercial commitments; Inventory manages physical realities; Finance manages financial liabilities.
- **Auditability:** Vendor selection, bid comparison, and purchase approvals are high-risk SOX/ITGC audit areas requiring immutable trails.
- **Commercial Integrity:** A Purchase Order (PO) must represent a governed, versioned commercial snapshot that cannot be secretly altered after issuance.
- **Interoperability:** Procurement must seamlessly feed expected receipts to Inventory (ADR-008) and expected liabilities to AP/GRNI (ADR-011).

## Scope
This ADR defines the enterprise procurement architecture for IVORQ, covering purchase intent, sourcing, RFQ/RFP, quotation, vendor selection, PO lifecycle, framework agreements, change orders, and the explicit boundaries where Procurement hands off to Inventory, GRNI, AP, Cost Ledger, Fixed Assets, and audit controls.

## Non-Goals
- This ADR does not define database schemas, API endpoints, UI screens, implementation code, queue topology, vendor products, or exact approval amounts.
- This ADR does not define document-storage implementation or specific legal clauses for contracts.
- This ADR does not claim legal, tax, SOX, IFRS, procurement-law, or regulatory compliance.

## Decision

### 1. Procurement Domain Boundary
The Procurement domain is strictly responsible for commercial intent and vendor commitment.

**Procurement Owns:**
- Purchase requisition (internal request).
- Sourcing request and RFQ/RFP process.
- Vendor quotation and bid comparison.
- Commercial negotiation record.
- Purchase order creation, approval, and issuance.
- Blanket or framework purchase agreements.
- Contract and amendment lifecycle.
- Supplier performance evidence at the architecture level.
- **Commercial commitment visibility** for approved or issued PO exposure. This is a commercial forecast or commitment view only; it does not create a General Ledger posting, Cost Ledger entry, AP liability, budget reservation, or formal accounting encumbrance. Formal budget reservation, budgetary encumbrance, and budget-control design are deferred to a future Budgeting / Forecasting / Encumbrance architecture decision. Commercial commitment visibility must remain distinguishable from actual expense, inventory valuation, AP liability, cash flow, and financial reporting.

**Procurement Does Not Own:**
- **Inventory quantity ledger** (governed by ADR-008).
- **AP invoice matching, GRNI clearance, and receipt-line matching boundaries** (governed by ADR-011).
- **Cost Ledger financial translation** (governed by ADR-012).
- **Financial period posting and late-transaction treatment** (governed by ADR-013).
- **General Ledger posting and Finance module ownership boundary** (governed by ADR-004).
- **Bank payment execution** (governed by ADR-019).

Procurement must provide stable, versioned commercial references to allow these downstream domains to execute their functions.

### 2. Procurement Objects and Commercial Lifecycle
IVORQ strictly distinguishes among procurement entities to prevent workflow bypass.

**Procurement Object Distinctions**
| Object | Definition & Architectural Role |
| :--- | :--- |
| **Purchase Requisition (PR)** | Internal, governed request for goods/services. Not a vendor commitment. |
| **RFQ / RFP** | Formal request sent to vendors to solicit competitive bids. |
| **Vendor Quotation** | A vendor's commercial offer in response to a sourcing request. |
| **Bid Comparison** | The audited evaluation and selection matrix of multiple vendor quotes. |
| **Purchase Order (PO)** | The approved, issued commercial commitment to a vendor. |
| **Change Order / PO Amendment** | A governed, approved modification to an issued PO. |
| **Purchase Contract** | Governed legal/commercial terms governing long-term vendor relationships. |
| **Blanket / Framework Agreement** | A contract defining terms/pricing over time, drawn down via Release Orders. |
| **Release Order** | A specific PO issued against a Framework Agreement. |
| **Goods Receipt Expectation** | PO data provided to Inventory to anticipate physical arrival. |
| **Service Acceptance Expectation** | PO data provided to operations to anticipate service delivery. |
| **Invoice Match Reference** | PO and Receipt data provided to AP for 3-way matching. |
| **Procurement Commitment** | A commercial forecast/visibility view of approved/issued POs not yet received or invoiced (not a financial/budget encumbrance). |

### 3. Purchase Requisition Governance
- A requisition represents an internal request and is **not** a vendor commitment.
- It must identify Tenant, Property, requesting department, purpose, expected delivery/service location, required date, requested items/services, and estimated commercial value.
- It must be scoped to the requester’s authorization boundary.
- It must follow ADR-003 approval policy.
- It must not create ledger postings, stock movement, GRNI, AP liability, or financial expense on its own.
- Cancellation, rejection, or expiry must preserve audit history.
- A requisition may be converted into sourcing, PO, contract, or approved direct-procurement flow only through governed transitions.

### 4. Sourcing, Quotation, and Vendor Selection
- RFQ/RFP and quotation collection are commercial evaluation processes.
- Bid comparison must preserve the evaluated offer snapshot, selection rationale, approving authority, and conflict-of-interest declaration where required.
- Vendor selection must reference the Vendor Ownership Model in ADR-006.
- Vendors, vendor bank details, and commercial terms must not be changed implicitly through PO creation.
- Quote expiry, supersession, withdrawal, and rejected bids must retain audit history.
- Procurement policy may require competitive quotations, but the exact thresholds remain configurable business policy.

### 5. Purchase Order Lifecycle and Version Integrity
An approved/issued PO must be immutable as a commercial snapshot.

**PO Lifecycle States**
| State | Definition |
| :--- | :--- |
| **Draft** | Under construction; no commercial validity. |
| **Submitted for Approval** | Locked for requester editing; routing via ADR-003. |
| **Approved** | Internally authorized but not yet transmitted. |
| **Issued** | Transmitted to vendor; represents active commercial commitment. |
| **Acknowledged** | Vendor has confirmed acceptance (where applicable). |
| **Partially Received / Accepted** | Downstream receipt/acceptance has begun. |
| **Fully Received / Accepted** | Downstream receipt/acceptance is complete. |
| **Closed** | Commercially finalized for ordinary receiving or service acceptance. |
| **Cancelled** | Applied only to unexecuted commercial balance. |
| **Superseded** | Replaced by a governed Change Order/Amendment. |
| **Expired** | Validity window passed without execution. |

- Any material change to quantity, price, currency, delivery, supplier, contract reference, item/service, tax treatment reference, or scope must create a versioned amendment/change order.
- Historical PO versions and approval evidence must remain immutable and auditable.
- **Closed Rule:** Closed status does not automatically invalidate a legitimate late AP invoice relating to goods already received or services already accepted. A late invoice must be handled through a governed AP exception or reopen policy and must follow ADR-011 GRNI/receipt matching rules plus ADR-013 period-posting rules. Closing a PO must not erase receipt, service acceptance, invoice-match, GRNI, variance, or audit evidence.
- **Cancelled Rule:** A PO with received quantity, accepted services, matched invoice, or GRNI history cannot be silently cancelled as though no execution occurred. Cancellation of an unexecuted remainder must preserve the original PO, residual quantity/value, approvals, history, and reason.
- Any controlled reopen, residual cancellation, or late-invoice exception must be auditable and must not rewrite prior PO versions.
- A PO does not itself create inventory movement, financial liability, Cost Ledger entry, or bank payment.
- PO commitment reporting is not the same as actual expense, inventory value, AP liability, or cash flow.

### 6. Contracts, Framework Agreements, and Release Orders
- Contracts and framework agreements represent governed commercial terms, not direct accounting entries.
- Contract terms may control approved vendors, pricing, quantity ceilings, validity windows, service obligations, delivery terms, renewal dates, and amendment history.
- A release order must reference its controlling agreement where applicable.
- Contract expiry, renewal, termination, and amendment must preserve historical versions and audit evidence.
- Contract records may contain Confidential information and must follow ADR-031 access, masking, retention, and controlled support access rules.

### 7. Receiving, Services, Inventory, GRNI, and AP Handoffs
Clear boundaries exist between Procurement and downstream execution.

**Domain Handoff Boundaries**
| Process Step | Architectural Owner | Governing Rule |
| :--- | :--- | :--- |
| **Purchase Requisition / PO** | Procurement | Establishes commercial intent and supplier commitment. |
| **Goods Receipt** | Inventory | Physical receipt governed by ADR-008 and ADR-011 (GRNI). |
| **Service Acceptance** | Operations | Governed confirmation that a service obligation was delivered. |
| **AP Invoice Match** | Accounts Payable | Validation against commercial evidence. Governed by ADR-011 (GRNI/receipts) and ADR-013 (period controls). |
| **Cost Ledger / Financial Posting** | Finance | Financial consequence governed by ADR-012 (Cost Ledger), ADR-013 (periods), and ADR-004 (Finance boundary). |

- Procurement must provide stable PO and contract references to receiving and AP.
- Goods receipt must remain governed by ADR-008 and ADR-011.
- Service acceptance must be auditable and must not be treated as inventory receipt by default.
- AP matching must not silently treat an unapproved PO, cancelled PO, or unauthorized PO amendment as valid commercial evidence.
- Goods/services without a PO must be handled only through a documented exception policy, approval, and audit trail.
- Procurement may expose expected commitment data, but must **not** post financial ledger entries directly.

### 8. Landed Cost, Tax, Currency, and Capital Procurement Boundaries
- Procurement may capture commercial estimates and references for freight, duty, tax, currency, delivery terms, and other acquisition factors.
- Actual landed cost allocation is governed by ADR-015.
- FX treatment is governed by ADR-018.
- Tax calculation and jurisdiction-specific tax rules are deferred to ADR-033.
- Capital asset classification and capitalization decisions are governed by ADR-027 and Finance authority.
- Procurement cannot independently capitalize an item, recognize expense, or alter accounting treatment.

### 9. Segregation of Duties and Approval Controls
- Requester, buyer/procurement officer, vendor maintainer, approver, receiver/service confirmer, invoice matcher, and payment executor must be separable roles.
- A requester must not self-approve their own requisition or PO outside explicitly governed emergency policy.
- PO amendments that increase commercial exposure must be re-approved under ADR-003 policy.
- **Vendor Bank Detail SoD:** Vendor Master Data is Tenant-owned under ADR-006. Procurement buyers may select approved vendors but must not implicitly create, alter, or approve vendor banking/payment details through PR, RFQ, PO, contract, amendment, or vendor selection workflow. Vendor bank/payment detail creation or change requires a separate governed vendor-master process. The requester or buyer initiating a vendor-bank change must not be the sole approver of that change. Bank/payment detail changes require independent verification, immutable audit evidence, and appropriate approval. A vendor-bank change must not silently alter historical commercial snapshots, already-approved payment batches, historical invoices, or prior audit evidence. Payment execution remains governed by ADR-019 and must continue to require verified active vendor banking details.
- Emergency procurement must be time-limited, justified, and fully audited; it cannot become a routine bypass.
- Access and scope must respect Tenant, Property, Department, and Location boundaries where applicable.
*(See ADR-029 for the detailed role matrix).*

### 10. Multi-Tenant, Multi-Property, and Intercompany Boundaries
- Procurement records must be tenant-isolated.
- PO, contract, and requisition ownership must be explicit at Tenant and Property level.
- Property-to-property procurement and group purchasing arrangements require explicit governed ownership and commercial responsibility.
- Intercompany procurement/transfer is not assumed to be an ordinary vendor PO; it must honor ADR-023 where applicable.
- Cross-tenant supplier sharing, commercial pricing visibility, or group procurement analytics must require explicit Enterprise and Tenant governance.

### 11. Audit, Privacy, and Record Retention
Procurement records with financial or audit implications must not be hard-deleted casually.

**Minimum Procurement Audit Events**
| Event Target | Required Audit Coverage |
| :--- | :--- |
| **Requisition (PR)** | Creation, approval, rejection, cancellation. |
| **Sourcing** | RFQ/RFP creation and release. |
| **Quotation** | Quote submission, selection, rejection, withdrawal, vendor selection rationale. |
| **Purchase Order (PO)** | Approval, issuance, cancellation, amendment, closure. |
| **Contracts** | Creation, amendment, expiry, renewal, termination. |
| **Exceptions** | Emergency procurement exception; SoD conflict/override where permitted. |
| **Access** | Controlled access to Confidential/Restricted commercial documents. |

- Records must preserve business and financial traceability.
- Audit logs must not contain secrets, raw payment data, unnecessary PII, or unmasked Restricted data.
- Retention, legal hold, anonymization, and controlled support access are governed by ADR-031.

### 12. Failure Modes and Safe Degradation
- **Approval engine unavailable:** Fail-closed (cannot approve/issue POs).
- **Vendor quotation or contract expires before PO issuance:** Fail-closed (requires re-quote, contract renewal, or governed exception).
- **PO amendment conflicts with received quantity or matched invoice:** Fail-closed (cannot amend below already executed values; requires Finance resolution).
- **Receiving occurs against a cancelled/superseded PO:** Fail-closed (Inventory must reject or escalate to exception workflow).
- **Contract or vendor validity cannot be resolved:** Fail-closed (cannot issue new POs).
- **Emergency procurement occurs during outage:** Permitted only if an explicit, audited emergency policy applies and captures necessary data for post-recovery reconciliation.
- **Procurement data residency or classification cannot be resolved:** Fail-closed (prevent export, sharing, or cross-border transmission).
- **Duplicate requisition, PO, or change-order submission:** Fail-safe (idempotency controls must reject duplicates).
- **Supplier integration delivers ambiguous or conflicting commercial data:** The payload must be quarantined. Downstream commercial processing, PO issuance/amendment, vendor-master mutation, disclosure, and ordinary retries must be blocked until resolved. The system must record only minimum safe metadata and audit evidence; it must not expose Confidential or Restricted payload content in logs. The system must not silently discard a potentially material commercial, financial, operational, or contractual event. Resolution must occur through a governed exception workflow, approved correction/mapping, safe rejection to the sender, or approved replay. Any replay must preserve idempotency, version integrity, tenant scope, and auditability.

## Alternatives Considered
- **Combining Procurement and AP into a single "Purchasing" ledger:** Rejected. Procurement controls commercial intent while AP controls actual financial liability. Merging them violates core Segregation of Duties and blurs the lines between a quote/PO (intent) and an invoice (liability).

## Consequences

### Positive Consequences
- **Strict SOX/ITGC Alignment:** Clear boundaries between requesters, buyers, approvers, receivers, and payers.
- **Audit Defensibility:** A PO is guaranteed to be an immutable commercial snapshot, eliminating "ghost amendments" after receipt.

### Trade-Offs and Risks
- **Workflow Friction:** Strict PO amendment rules and SoD enforcement will create operational friction for properties used to informal, unstructured purchasing.
- **Integration Complexity:** Requiring Procurement to provide stable references to AP and Inventory mandates highly robust state-management between independent domains.

### Operational Requirements
- Approval thresholds, competitive-quote thresholds, delegation limits, emergency limits, and contract policy must be configurable governance policies at the Tenant/Property level.

## Dependencies and Related ADRs
- ADR-001 — Multi-Tenant Hierarchy
- ADR-002 — Audit Trail Strategy
- ADR-003 — Approval Engine
- ADR-004 — Finance Module Boundary
- ADR-006 — Vendor Ownership Model
- ADR-008 — Inventory Ledger Architecture
- ADR-011 — GRNI Architecture
- ADR-012 — Cost Ledger Architecture
- ADR-013 — Period Closing Strategy
- ADR-015 — Landed Cost and Tax Apportionment Strategy
- ADR-018 — Foreign Currency Revaluation Strategy
- ADR-023 — Intercompany Accounting and Transfer Ledger
- ADR-027 — Fixed Asset and Depreciation Architecture
- ADR-029 — Security, Roles and Permissions Governance
- ADR-031 — Data Privacy, PII, Retention and Data Residency Governance

## Deferred Decisions
- Tax calculation and jurisdiction-specific tax rules are deferred to ADR-033.
- Specific implementation of contract document-storage and legal clause management is deferred to future contract implementation.
- Specific data mapping for PMS/HRIS modules is deferred until those boundaries are active.

## Open Questions Requiring CTO Approval
- None at this time.

## Validation Criteria
- PR/PO issuance cannot circumvent ADR-003 approvals.
- PO amendments generate a new version without altering historical snapshots.
- AP matching strictly verifies against the immutable PO snapshot and Goods Receipt.
- No direct Cost Ledger entries originate from Procurement.

## References
- Internal: IVORQ ADR Master Structure Review
