# ADR-033: Global Tax and Jurisdiction Compliance Architecture

## Status
Proposed

## Context
IVORQ is a multi-tenant, multi-property hospitality SaaS platform operating under the hierarchy: Enterprise → Tenant → Property. Hospitality operations across multiple jurisdictions require a flexible, rigorous approach to tax determination and compliance. Tax considerations intersect with Purchasing and Procurement, Inventory and Landed Cost, Accounts Payable, Revenue and POS integrations, City Ledger / Accounts Receivable, Inclusive packages and loyalty, Intercompany transactions, and future PMS operations. The architecture must govern tax determination, treatment, evidence, jurisdiction configuration, effective-dated rules, corrections, and financial handoffs without hardcoding tax law, tax rates, legal interpretations, statutory return formats, or country-specific compliance claims.

## Decision Drivers
- **Regulatory Diversity:** Different jurisdictions have vastly different rules for tax inclusivity, exemption, rate structure, and reporting.
- **Audit Defensibility:** Tax authorities require immutable evidence linking a tax calculation back to the specific event, context, and rule version active at the time.
- **Separation of Concerns:** Source domains generate business events; the Tax domain determines tax treatment; Finance posts accounting entries.
- **Temporal Integrity:** Retroactive tax changes must not silently rewrite history; closed financial periods must be protected.

## Scope
This ADR defines IVORQ’s global Tax and Jurisdiction Compliance Architecture. It establishes how tax determination, tax treatment, tax evidence, jurisdiction configuration, effective-dated tax rules, tax corrections, and tax-sensitive financial handoffs are governed across the platform.

## Non-Goals
- This ADR does not provide tax advice, country-specific rates, legal interpretations, statutory deadlines, filing instructions, or compliance guarantees.
- This ADR does not claim compliance with VAT, GST, sales tax, tourism tax, service tax, withholding-tax, e-invoicing, IFRS, GAAP, ISO, SOC, PCI, or any local regulation.
- This ADR does not define database schemas, API endpoints, UI screens, tax formula code, vendor products, queue topology, or exact retention periods.
- This ADR does not hardcode any tax rate, threshold, country, region, or legal authority.

## Decision

### 1. Tax Domain Boundary
Source domains own their business event, while the Tax domain determines the tax treatment applicable to that event under configured policy.

**Tax Domain vs. Source Domain Responsibility**
| Domain | Area of Ownership |
| :--- | :--- |
| **Tax Domain** | Tax determination context, decision inputs, configurable rules, versioning, jurisdiction/registration references, inclusivity/exclusivity classification, applicability/exemption/withholding classification, calculation evidence, determination snapshots, correction/adjustment references, and reporting data readiness boundaries. |
| **Source Domains** | Commercial procurement approval, inventory quantity movement, cash payment execution, bank reconciliation, revenue recognition timing, and source event generation. |
| **Finance Domain** | Cost Ledger posting, General Ledger posting, and financial period close. |

### 2. Tax Determination Context and Source Facts
Tax outcomes must be reproducible from the retained determination context and approved rule version. Missing or ambiguous tax context must not silently default to a tax outcome. Tax determination must retain clear source-event references without duplicating ownership of source records. Later rule changes must not silently rewrite historical tax outcomes.

- **Tax Point / Effective-Date Selection Governance:** Tax rule eligibility must be determined through a configured and deterministic tax-point or effective-date selection policy. The policy may select the relevant business date, transaction date, document date, service date, delivery date, invoice date, or another approved date basis according to jurisdiction and transaction type. The selected controlling date basis and resulting effective date must be retained in the tax determination snapshot. A transaction initiator must not freely override the controlling tax date without a governed exception and immutable audit evidence. Later changes to date-selection policy must not silently rewrite historical tax outcomes.

**Tax Determination Context**
| Context Element | Description |
| :--- | :--- |
| **Entity / Scope** | Tenant, Property, future reporting/tax-registration identity. |
| **Event Details** | Transaction type, business date, transaction date, document date. |
| **Location** | Supply/delivery/service location, origin and destination where relevant. |
| **Classification** | Customer/vendor/counterparty classification, item/service classification. |
| **Financials** | Currency, tax-included or tax-exclusive commercial amount. |
| **References** | Exemption/eligibility evidence, contract, PO, receipt, invoice, folio, revenue-document reference. |
| **Rule Context** | Applicable rule version and effective date. |

### 3. Jurisdiction, Registration, and Scope Governance
- Tax rules must be associated with an approved jurisdiction scope and effective period.
- A future tax registration or reporting identity may be associated with a Tenant and, where required by business structure, Property or a future legal-entity boundary. (This ADR does not define legal-entity master-data design).
- Jurisdiction, registration, reporting scope, and registration validity must be governed configuration, not free-text transaction behavior.
- Tax treatment must not be inferred only from a user’s locale, browser language, currency, or IP address.
- Cross-border transactions require explicit jurisdictional evaluation and may not rely on domestic default rules.
- A Property must not override Tenant tax policy without authorized configuration and audit evidence.

### 4. Tax Rule Governance, Effective Dating, and Version Integrity
Specific legal rate values, formulas, and jurisdiction interpretations remain tenant/jurisdiction policy content subject to competent tax/legal review.

**Tax Rule Lifecycle**
| Lifecycle Event | Rule Governance |
| :--- | :--- |
| **Configuration** | Tax rates, thresholds, inclusivity, exemptions, tax codes, rounding policy, and rule applicability must be configurable and effective-dated. |
| **Activation / Expiry** | Tax rule creation, amendment, activation, suspension, and deactivation must require authorization and immutable audit evidence. Must preserve version history, approval evidence, activation date, expiry date, and jurisdiction scope. |
| **Precedence & Overlap Controls** | Tax rules must use a deterministic approved precedence model when more than one rule could be applicable. The rule model must detect overlapping active rules with the same effective scope where no approved precedence exists. A conflicting or ambiguous active rule set must not be activated silently. Rule activation must fail closed or require governed remediation when overlap, ambiguity, missing scope, or missing precedence is detected. The selected rule and applicable precedence basis must be retained in the tax determination snapshot. Rule precedence configuration, conflict resolution, and activation must have authorization and immutable audit evidence. |
| **Amendment** | A change to a tax rule must not alter a posted, issued, invoiced, closed-period, or otherwise historically finalized transaction. |
| **Correction** | Retroactive tax correction must be represented through governed adjustment, credit/debit, correction, or restatement workflow—not silent mutation. |

### 5. Hospitality Revenue, POS, Guest, Package, and Service-Charge Boundaries
- Revenue source domains provide the commercial and operational facts; Tax domain determines applicable treatment.
- Room charges, F&B sales, spa, activities, events, packages, service charges, gratuities, fees, deposits, no-show fees, cancellation fees, and loyalty-related consideration may have different tax treatment.
- Service charge, gratuity, surcharge, and tax must not be assumed to be interchangeable.
- Inclusive package allocation under ADR-026 must preserve tax determination evidence per allocated performance component where tax treatment differs.
- Future PMS and POS integrations must provide sufficient tax context and must not hardcode tax amounts without governed Tax domain treatment.
- Tax determination does not decide revenue recognition timing; ADR-025 remains authoritative for revenue recognition architecture.
- Tax treatment for guest folio, invoice, credit note, refund, and adjustment must preserve traceability to the underlying source event.

### 6. Procurement, Inventory, AP, Landed Cost, and Withholding Boundaries
- Procurement captures commercial tax references and supplier terms but does not create final tax posting. PO tax reference is a commercial expectation and may require validation at receipt, service acceptance, or invoice match.
- Goods Receipt, service acceptance, GRNI, and AP invoice match remain governed by ADR-011 and related Finance boundaries.
- Landed-cost tax allocation is governed by ADR-015.
- Recoverable/non-recoverable tax, tax capitalization, and cost treatment must be determined by governed policy and Finance authority; Procurement cannot independently decide accounting treatment.
- Withholding or supplier-related tax handling may be supported as a configurable tax classification, but no jurisdiction-specific withholding logic is assumed by this ADR.
- Vendor tax identifiers and tax-related documents must be handled as Confidential or Restricted data where appropriate under ADR-031.
- Supplier invoices with missing, expired, invalid, or conflicting tax treatment must fail closed for final financial consequence unless a governed exception process applies.

### 7. Tax Documents, Corrections, Credits, Refunds, and Evidence
- Tax-sensitive commercial documents may include invoices, credit notes, debit notes, receipts, folio documents, supplier invoices, adjustment references, and tax evidence.
- Issued tax-sensitive document snapshots must remain traceable, immutable, and version-aware.
- Correction must occur through governed reversal, credit/debit, correction, or adjustment references as appropriate; original evidence must not be silently overwritten.
- Refunds and cancellations must not erase original tax evidence.
- Tax evidence retention, privacy, export, and legal-hold behavior are governed by ADR-031.
- This ADR does not define statutory invoice formats, e-invoicing vendors, QR formats, signatures, fiscal devices, or document-numbering implementation.

### 8. Foreign Currency, Rounding, and Intercompany Boundaries
- Tax determination must identify transaction currency and relevant functional/base-currency reference without redefining ADR-018.
- Tax rounding policy must be configurable, deterministic, and preserved with the tax calculation snapshot.
- FX revaluation remains governed by ADR-018; Tax domain must not independently create FX revaluation journals.
- Intercompany transactions must not be presumed tax-neutral.
- Intercompany tax treatment must respect ADR-023 transaction ownership, transfer evidence, jurisdiction scope, and separate tax determination for each accountable side where required.
- Cross-border or multi-currency tax differences must not be silently absorbed as generic variance.

### 9. Posting, Period Closing, and Adjustment Boundaries
- Tax domain produces governed tax determination outputs and evidence; Finance controls accounting translation and posting.
- Tax-related posting must follow Finance module boundaries under ADR-004 and Cost Ledger/financial posting boundaries under ADR-012 where applicable.
- Tax treatment must respect GRNI, invoice-match, and receipt timing architecture under ADR-011.
- Tax corrections involving closed periods must follow ADR-013 period controls.
- A closed financial period must not be silently mutated to change historical tax results.
- Late tax documents, late invoices, and retroactive rule discoveries require governed exception, adjustment, or subsequent-period treatment.
- Tax reports or declarations must be traceable to the exact rule versions, source events, corrections, and period context used.

### 10. Segregation of Duties, Approval, and Operational Controls
- Tax policy maintainer, tax rule approver, transaction initiator, invoice matcher, revenue adjuster, journal approver, and payment executor must be separable responsibilities.
- No single identity may create, approve, activate, and use a high-impact tax rule without an independent approval boundary.
- Emergency tax overrides must be exceptional, time-limited, justified, independently approved where possible, and immutable-audit logged.
- A tax override must never silently rewrite historical tax results.
- Access must respect Tenant, Property, Department, and jurisdiction scope where applicable.
- Detailed role/permission matrix remains governed by ADR-029.

### 11. Audit, Privacy, Integrations, and Reporting Readiness
- **Reporting Reproducibility:** Historical tax reports must be generated from persisted tax determination outputs, approved rule versions, source-event references, corrections, credit/debit adjustments, refunds, and period context. A tax report for a historical period must not silently recalculate historical events using current tax rules. Any restatement, correction, or subsequent-period adjustment must be traceable to its original determination and reason. Tax-report generation must preserve report version, reporting period, generation context, and immutable audit evidence. A tax report remains a controlled output and is not proof of statutory filing, payment, or legal compliance.
- **Integrations & Privacy:** External tax providers, e-invoicing providers, and reporting processors are future integration choices that require separate security, privacy, contractual, and jurisdictional review. Tax information inherits ADR-031 classification, masking, retention, legal hold, data residency, and controlled support-access rules. Audit logs must retain meaningful action context but must not expose raw payment data, unnecessary PII, government identity details, raw credentials, secrets, or unmasked Restricted payloads. Tax-related integration payloads must preserve tenant scope, idempotency, source references, and rule-version traceability.

**Tax-Sensitive Audit Events**
| Event Type | Required Audit Scope |
| :--- | :--- |
| **Rule Lifecycle** | Creation, revision, activation, suspension, and expiry of tax rules. |
| **Scope Governance** | Jurisdiction/registration configuration changes. |
| **Exceptions** | Tax override, exemption decision, and approval. |
| **Corrections** | Tax correction, credit/debit adjustment, refund, cancellation, and period exception. |
| **Data Movement** | Tax-sensitive export, report generation, or integration delivery. |
| **Failures** | Tax integration failure, replay, rejection, or manual resolution. |

### 12. Failure Modes and Safe Degradation

**Failure Modes and Safe Outcomes**
| Failure Condition | Safe Outcome |
| :--- | :--- |
| Tax jurisdiction cannot be resolved. | Fail-closed for final financial posting, external tax document issuance, cross-border disclosure, or tax-sensitive export. |
| Required tax registration is inactive or cannot be validated. | Fail-closed; preserve safe audit evidence. |
| Tax rule is missing, expired, ambiguous, or conflicting. | Fail-closed; do not silently discard material event. Use governed exception process. |
| Tax determination service or required tax context is temporarily unavailable. | IVORQ must never silently substitute a zero-tax, default-tax, guessed-tax, or stale-tax result. Where tenant policy explicitly permits operational continuity, the source event may be captured in a controlled `tax-pending` state with minimum safe evidence, tenant scope, source reference, and audit trace. A tax-pending event must not be treated as final for tax-sensitive financial posting, external tax document issuance, tax-sensitive export, statutory reporting, refund finalization, or cross-border disclosure. Finalization may occur only after governed tax determination succeeds or a documented authorized exception is approved. If tenant policy does not permit pending capture for the event type, the action must fail closed. The system must not silently discard a material source event. |
| Effective-date rule cannot be determined. | Fail-closed. |
| Source event lacks required tax context. | Fail-closed. |
| Tax calculation differs from integration amount. | Quarantine; use governed exception, approved correction, or safe rejection. |
| Tax integration sends ambiguous/conflicting data. | Quarantine; block processing. Do not expose Restricted payload in logs. |
| Tax correction affects a closed period. | Fail-closed for direct mutation; requires ADR-013 period exception/adjustment. |
| Tax export/report generation fails midway. | The process must terminate safely and retain minimum audit and incident evidence. A partial report, incomplete tax document, or incomplete export must not be distributed, marked final, or treated as a valid reporting artifact. Any temporary generated artifact must be access-controlled, expired, or cleaned up according to governed retention and security policy. Retry or replay must preserve reporting-period scope, tenant scope, source references, tax-rule version traceability, idempotency, and auditability. Reprocessing must not create duplicate report outputs or duplicate external submissions. |
| Data residency/privacy classification unresolved. | Fail-closed for export/transmission. |
| Duplicate tax event or replay occurs. | Reject securely via idempotent controls. |

## Alternatives Considered
- **Hardcoding regional tax formulas into accounting services:** Rejected. Hardcoded logic lacks the flexibility required for multi-jurisdiction compliance, fails to provide distinct tax rule versioning, and blends tax policy with general accounting logic.

## Consequences

### Positive Consequences
- Establishes a flexible, auditable Tax Domain that shields core financial ledgers and operational domains from the volatility of jurisdiction-specific tax laws.
- Ensures tax treatment is robust, versioned, and temporally accurate without rewriting history.

### Trade-Offs and Risks
- Introduces complexity in ensuring all source domains provide a comprehensive, accurate tax determination context.
- High dependency on correct tenant/property configuration to avoid fail-closed states.

### Operational Requirements
- This ADR is mandatory before multi-jurisdiction enterprise release.
- Hospitality/PMS tax details require further module design before PMS implementation.
- Statutory filing/e-invoicing implementation remains future architecture/integration work.
- Tax policy data requires competent legal/tax/privacy review before activation.

## Dependencies and Related ADRs
- ADR-001 — Multi-Tenant Hierarchy
- ADR-002 — Audit Trail Strategy
- ADR-004 — Finance Module Boundary
- ADR-011 — GRNI Architecture
- ADR-012 — Cost Ledger Architecture
- ADR-013 — Period Closing Strategy
- ADR-014 — PPV and Variance Governance
- ADR-015 — Landed Cost and Tax Apportionment Strategy
- ADR-017 — Event-Driven Accounting and Queue Resiliency Strategy
- ADR-018 — Foreign Currency Revaluation Strategy
- ADR-019 — Payment and Bank Reconciliation Engine
- ADR-023 — Intercompany Accounting and Transfer Ledger
- ADR-025 — Revenue Recognition and Tax Engine
- ADR-026 — Inclusive Package Allocation and Loyalty Accounting
- ADR-028 — Accounts Receivable and City Ledger Strategy
- ADR-029 — Security, Roles and Permissions Governance
- ADR-031 — Data Privacy, PII, Retention and Data Residency Governance
- ADR-032 — Purchasing, Procurement and Contract Management Architecture

## Deferred Decisions
- Statutory filing and e-invoicing implementation details are deferred to future architecture/integration work.
- Legal-entity master-data design is deferred.
- Hospitality/PMS detailed tax application rules are deferred until specific PMS module design.

## Open Questions Requiring CTO Approval
- None at this time.

## Validation Criteria
- All tax calculations trace back to an explicit, versioned rule and context snapshot.
- Source domains (e.g., Procurement, Inventory, Revenue) can execute operational transactions but cannot force final tax accounting postings without Tax domain determination.
- Closed periods remain inviolate under tax corrections.
- Ambiguous tax context or conflicting integration data results in safe quarantine, not silent assumption.

## References
- Internal: IVORQ ADR Master Structure Review
