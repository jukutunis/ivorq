# ADR-034: Night Audit and Hospitality Business Date Architecture

## Status
Approved

## Activation Record
ADR-034 was approved by the IVORQ Owner on 2026-07-16 after acceptance and merge of FD-B10 General Cashier Obligation Read Dependency Integration. The canonical activation base is `764f143be5f49f4ae1be66ac41196ea5596806ce`, and the continuation authority for this activation is `CONTINUE_AFTER_FD_B10_ACCEPTED`.

This approval activates ADR-034 as the architectural authority for Business Date and Night Audit planning and controlled implementation. After this activation PR is independently reviewed, accepted, and merged, ADR-034 authorizes the BD-A1 implementation package to begin under a separate package authorization. This activation does not authorize BD-A1 runtime implementation inside this PR.

This activation does not authorize Night Audit execution, business-date advancement, business-date reopen, checkout execution, financial-period close, tax finalization, ledger posting, or mutation of Folio, payment, cashier, settlement, tax, revenue, GL, AR, Inventory, Front Desk stay, or any other foreign-domain state.

## BD-A1 Implementation Note

BD-A1 retains the existing `property_business_dates` persistence and `PropertyBusinessDate` aggregate as the canonical Property Business Date source. It provides controlled first initialization and read-only current-date evidence only. Initialization is first-history-only, server-derived from the persisted Property timezone and server clock, idempotent, and serialized by a locked active Property row. Active Company and active Property authorization is mandatory before Business Date history is queried.

BD-A1 captures timezone evidence server-side in `timezone_snapshot`, preserves legacy rows without fabricated evidence, and enforces foundational opening evidence immutability through PostgreSQL triggers. Legacy Inventory close services remain candidate-only, non-authoritative boundaries. BD-A1 activates no close, advancement, reopen, Night Audit, FD-B11, FD-B12, checkout execution, or foreign-domain mutation behavior.

## FD-B11 Implementation Note

FD-B11 consumes accepted BD-A1 Property Business Date evidence read-only inside the Front Desk checkout execution boundary and departure workspace. Front Desk receives current Property Business Date evidence only through the BD-A1 projection contract and does not initialize, close, advance, reopen, or mutate Business Date.

Business Date evidence unavailability remains fail-closed through stable source codes. At FD-B11 acceptance time, Night Audit close-lock evidence remained unavailable until NA-A1 supplied an authoritative source. Checkout execution remains unauthorized and unimplemented in FD-B11, and `can_execute` remains explicitly false. FD-B11 is accepted and canonical as the Front Desk Business Date read integration.

## NA-A1 Implementation Note

NA-A1 introduces Property-scoped Night Audit run evidence and one authoritative active close lock per Property. One In Progress Night Audit run is the authoritative close-lock source. Repeated start is idempotent for the current open Property Business Date, and abort creates immutable evidence while releasing the active lock so a later start can create the next attempt.

NA-A1 consumes accepted BD-A1 Property Business Date evidence read-only. It does not close or advance Business Date, does not reopen Business Date, does not evaluate checkpoints, does not mutate foreign domains, and does not perform checkout integration. NA-A1 is accepted and canonical as the first Night Audit run and active close-lock foundation.

## FD-B12 Implementation Note

FD-B12 consumes accepted NA-A1 Night Audit close-lock evidence read-only inside the Front Desk checkout execution boundary and departure workspace. Front Desk receives Night Audit close-lock evidence only through the NA-A1 projection contract and does not start, abort, close, advance, reopen, run checkpoints, or mutate Night Audit.

`NIGHT_AUDIT_LOCK_CLEAR` satisfies the Front Desk Night Audit gate. `NIGHT_AUDIT_LOCK_ACTIVE` blocks checkout readiness with `NIGHT_AUDIT_CLOSE_LOCK_ACTIVE`, and unavailable source evidence remains fail-closed with `NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE`. Checkout execution remains unauthorized and unimplemented in FD-B12, and `can_execute` remains explicitly false.

## ADR-089 Synchronization Note

ADR-089 is Approved and does not transfer Business Date or Night Audit ownership to Front Desk. NA-A2 is the current runtime package. It introduces shared Property and Business Date operational locking as a serialization primitive only; this is not an ownership transfer. Business Date and Night Audit ownership remains unchanged. NA-A2 does not authorize close, advance, reopen, checkpoints, or checkout execution. Future checkout execution and Night Audit start must share compatible Property and Business Date lock ordering so Night Audit cannot become active between checkout's final close-lock validation and checkout commit.

## Current Implementation Context
IVORQ now has accepted PMS Guest Ledger, PMS Cashiering, General Cashier, and Front Desk readiness foundations. Those accepted domains remain the owners of their existing states, source evidence, and controlled outcomes.

BD-A1 is accepted and canonical as the authoritative Property Business Date runtime foundation. FD-B11 is accepted and canonical as the Front Desk read-only Business Date dependency integration. NA-A1 is accepted and canonical as the Night Audit run and active close-lock foundation. FD-B12 is the current authorized Front Desk read-only Night Audit close-lock integration. Business Date close, advancement, and reopen remain unimplemented.

Business Date and Night Audit consume source-owned attestations. ADR-034 does not transfer Folio, payment, cashier, settlement, tax, revenue, GL, AR, Inventory, Front Desk stay, or checkout ownership to Business Date or Night Audit. Future implementation must remain incremental, package-scoped, and source-domain respectful.

## Context
IVORQ is a multi-tenant, multi-property hospitality SaaS platform operating under the hierarchy: Enterprise → Tenant → Property. Hospitality operations require a rigorous mechanism to separate the actual chronology of events from the operational "business day" of the hotel, and further separate these from strict financial accounting periods. A hotel business day can and must close operationally without silently mutating finance periods, historical tax outcomes, audit evidence, or real-world timestamps.

## Decision Drivers
- **Operational Reality vs. Financial Accounting:** A hotel business day does not cleanly map to a calendar day, nor does it cleanly map to a financial month-end period.
- **Audit Defensibility:** An operational day closure (Night Audit) represents an attested checkpoint; this checkpoint must be immutable.
- **Global Distribution:** Properties within the same Tenant operate in different time zones with different local operating schedules and business-day cutovers.
- **Safe Degradation:** The operational day must not be held hostage by downstream integration delays, nor should external systems freely dictate the business date bypassing controls.

## Scope
This ADR governs current and future PMS, Business Date, and Night Audit implementation where hospitality business-date evidence, Night Audit locks, close checkpoints, or business-date reporting are consumed, exposed, or enforced. It is the mandatory architecture authority before BD-A1 and any Night Audit runtime package begin.

This ADR also constrains already accepted PMS Guest Ledger, PMS Cashiering, General Cashier, and Front Desk foundations where they consume or expose Business Date or Night Audit evidence. It does not retroactively transfer ownership, rewrite accepted domain behavior, or turn Business Date / Night Audit into the owner of Folio, payment, cashier, settlement, tax, revenue, GL, AR, Inventory, Front Desk stay, or checkout state.

## Non-Goals
- IVORQ already has accepted PMS Guest Ledger and PMS Cashiering foundations.
- IVORQ has an accepted authoritative Property Business Date runtime foundation through BD-A1.
- IVORQ does not yet have accepted Business Date close, advancement, reopen, Night Audit checkpoint evaluation, or checkout execution behavior.
- ADR-034 approval is architecture activation; NA-A1 acceptance is limited to the first Night Audit run and active close-lock foundation.
- This ADR does not provide country-specific tax, accounting, labor, or legal guidance.
- This ADR does not claim compliance with any law, accounting standard, security standard, or certification.
- This ADR does not define database schema, API endpoints, UI screens, queue topology, cron schedules, code, vendor products, or exact business-day cutoff time.

## BD-A1 Implementation Boundary
BD-A1 is the first authorized implementation package after this ADR-034 activation is reviewed, accepted, and merged. BD-A1 is limited to an authoritative, Property-scoped Business Date foundation. It must not implement Night Audit close orchestration, business-date reopen, checkout integration, foreign-domain mutation, or automatic financial consequences.

BD-A1 must preserve these invariants without ADR-034 defining exact schema, tables, routes, controllers, UI layouts, DTO fields, permission names, or implementation contracts:

- One authoritative current hospitality business date per Property.
- Explicit lifecycle state.
- Active Company and Property validation.
- Property timezone sourced server-side.
- No browser-controlled Property, Company, business date, lifecycle state, actor, or audit fields.
- Immutable UTC timestamps remain distinct from hospitality business date.
- Business Date is distinct from Accounting Date and financial period.
- Read and command boundaries are authorization-first.
- Controlled initialization is idempotent.
- Cross-property and cross-tenant disclosure is forbidden.
- No silent date advancement.
- No Night Audit close orchestration yet.
- No reopen lifecycle yet.
- No checkout integration yet.
- No foreign-domain mutation.
- No automatic revenue, tax, GL, AR, cashier, Folio, Inventory, or settlement consequences.

Exact implementation details belong to the later BD-A1 delivery package and must be source-proven at that time.

## Controlled Program Sequence
1. ADR-034 activation - accepted and merged.
2. BD-A1 - authoritative Property Business Date foundation, accepted and canonical.
3. FD-B11 - Front Desk Business Date read dependency integration, accepted and canonical.
4. NA-A1 - first controlled Night Audit run and active close-lock foundation, accepted and canonical.
5. FD-B12 - Front Desk Night Audit close-lock read dependency integration, current authorized package.
6. Checkout execution readiness review, locked.
7. checkout execution remains separately unauthorized until a future explicit Owner decision.

Each step requires independent review, acceptance, merge, a new canonical SHA, and separate package authorization before the next package may start.

## Decision

### 1. Business Date Domain Boundary
Night Audit coordinates governed checks and receives controlled attestations from source domains. It must not silently become the owner of every downstream business process.

**Business Date Domain Ownership**
The Business Date / Night Audit domain **owns**:
- Property-level hospitality business-date lifecycle.
- Controlled business-day opening and closing state.
- Night Audit orchestration state.
- Business-day cutover policy reference.
- Closure validation evidence and exception evidence.
- Controlled reopen or correction request boundaries.
- Operational close status distinct from financial period close.

The Business Date / Night Audit domain **does not own**:
- Actual system timestamps or event chronology.
- General Ledger posting.
- Cost Ledger posting.
- Financial period close.
- Tax calculation or tax rule ownership.
- Revenue recognition timing.
- Bank reconciliation or payment execution.
- Inventory quantity movement.
- Final guest folio, POS, or PMS implementation behavior.

### 2. Time Model and Date Semantics
A UTC timestamp is a normalized time representation and must always retain a clear semantic purpose. Actual occurrence timestamp and recorded/received timestamp are separate facts. Actual occurrence timestamp represents when the event is asserted to have happened by a trusted source or governed operational process. Recorded/received timestamp represents when IVORQ accepted, persisted, or received the event. A source-provided occurrence timestamp must not overwrite IVORQ recorded/received timestamp. If a source occurrence timestamp is unreliable, missing, conflicting, or unverifiable, the event must follow governed late-event or exception handling. Business-date assignment must be preserved with the source event and must not overwrite either actual occurrence timestamp or recorded/received timestamp.

System-generated timestamps must remain immutable evidence and must not be overwritten by business-date assignment. A Tenant may set policy defaults, but Property-specific time zone and business-day cutover may differ when authorized. Daylight-saving transitions, ambiguous local times, skipped local times, and time-zone changes must use deterministic handling rules. Business date must not be inferred only from browser time, user device clock, browser locale, currency, or IP address. A source system or external integration must not be trusted to arbitrarily force an IVORQ business date without governed validation.

**Time-Zone and Cutover Reclassification Governance:** A Property time-zone or business-day cutover policy change must be effective-dated and apply prospectively. Such a change must not silently recalculate, reassign, or rewrite historical Property local dates, business-date assignments, closure snapshots, or prior Night Audit evidence. A change must normally activate only from a future business date that has not yet been opened. A change affecting an active Open business day requires a controlled, exceptional transition with explicit impact evidence, approval, and immutable audit trail. Historical correction remains governed by late-event, reopen, and post-audit adjustment controls rather than policy mutation.

**Date and Time Semantic Distinctions**
| Concept | Definition |
| :--- | :--- |
| **UTC Timestamp** | Normalized time representation; actual occurrence and recorded time remain distinct. |
| **Property Local Timestamp** | UTC timestamp translated using the governed IANA time zone policy for the Property. |
| **Property Local Calendar Date** | Calendar date derived from the Property local timestamp. |
| **Hospitality Business Date** | The operational hotel day assigned under governed business-date policy. |
| **Business-Date Cutover Timestamp** | The explicit, effective-dated, auditable policy threshold separating one business day from the next. |
| **Accounting Date** | Date assigned for financial ledger entry, mapping to a financial period. |
| **Financial Period** | Finance-controlled accounting period governed separately by ADR-013. |
| **Source-Event Date** | The date claimed by the source of the event. |
| **Recorded/Received Date** | The UTC timestamp when IVORQ accepted the event payload. |

### 3. Property Business-Day Lifecycle
A Property must have only one active Open business day at a time unless an explicitly governed exceptional recovery policy applies. A Property business day must not advance until required close controls are complete or a controlled exception is approved. Operationally Closed does not automatically mean financial period closed, tax filing complete, cash reconciled, or all late documents prohibited. A new business day must not silently supersede a blocked or unresolved prior business day. State transitions require authenticated actor or system identity, timestamp, relevant policy version, and immutable audit evidence. A business-day close must preserve an immutable closure snapshot or equivalent evidence reference.

**Property Business-Day Lifecycle**
| State | Purpose & Permitted Behavior |
| :--- | :--- |
| **Open** | Active operational day; standard transactions and postings permitted. |
| **Audit In Progress** | System is actively evaluating closing checkpoints. Postings may be queued or restricted. |
| **Closure Blocked** | Critical exception found; day remains open or suspended pending remediation. |
| **Operationally Closed** | Night audit complete and attested. New postings map to the next business date. |
| **Reopen Requested** | Exception process initiated to unlock a closed day for governed correction. |
| **Reopened** | Limited, exceptional correction state. Does not allow unrestricted ordinary postings. Scope must identify minimum affected process/event. |

### 4. Night Audit Orchestration and Controlled Checkpoints
Night Audit is a controlled orchestration process. A Night Audit run must have an identifiable run/reference and must be idempotent or safely resumable. A duplicate audit-close attempt must not create duplicate downstream consequences. Night Audit must not directly create unsupported ledger postings, tax outcomes, or source events. Each participating domain remains responsible for its own data integrity and business rules. Night Audit results must distinguish successful closure, blocked closure, controlled exception closure, interrupted run, and retry/resume outcome.

**Checkpoint Criticality Classification:** Each Night Audit checkpoint must be classified by governed Tenant/Property policy as a Hard Blocker, Exception-Eligible, or Informational. A Hard Blocker prevents operational closure until resolved or until an explicitly governed high-impact exception is approved. An Exception-Eligible checkpoint may allow a controlled exception closure only with documented unresolved references, reason, owner, follow-up expectation, and audit evidence. An Informational checkpoint must not independently block operational closure. A required source module outage must not automatically block every operational closure; its treatment must follow its approved checkpoint criticality. Unresolved exceptions must remain visible and cannot be silently treated as completed.

**Tax-Pending Handling:** A `tax-pending` event must never be treated as a final tax-sensitive financial, reporting, export, refund, cross-border, or external tax-document outcome. Where Tenant policy explicitly permits controlled operational continuity, a Night Audit may close through a documented exception while retaining the affected event as unresolved `tax-pending`. Such exception closure must preserve event references, tenant/property scope, reason, responsible owner, audit evidence, and follow-up requirement. A `tax-pending` exception must not silently create final tax posting, final revenue/tax result, or external document issuance. Finalization may occur only after tax determination succeeds or through a separately governed authorized exception under ADR-033. Where policy does not permit controlled pending closure for the event category, Night Audit must fail closed for the affected closure path.

**Night Audit Checkpoint Ownership**
| Checkpoint Area | Orchestration Scope |
| :--- | :--- |
| **Postings** | Outstanding operational posting or posting-batch status. |
| **Revenue/Folio** | Revenue and folio posting readiness. |
| **POS Integrations** | POS and service revenue integration completeness. |
| **Cashier/Settlement** | Cashier / payment / settlement readiness. |
| **Receivables** | City Ledger and receivable handoff readiness. |
| **Tax** | Tax-determination and `tax-pending` status. |
| **Financial/Queue** | Pending financial event or queue resiliency status. |
| **Operations** | Inventory, purchasing, or other operational exception references where relevant. |
| **Approvals** | Required approval or exception evidence. |

### 5. Events Around Midnight, Late Events, and Delayed Integrations
An event must preserve actual occurrence timestamp, recorded/received timestamp, source reference, Property scope, and governed business-date assignment. A late-arriving event must not silently be assigned to a prior closed business date merely because it appears related to that date. Events generated before cutover but received after cutover require governed source-timestamp validation and late-event policy. Integration delay, offline POS activity, delayed payment response, delayed folio update, or queue retry must not create arbitrary backdating. A late event affecting a closed business day requires a governed correction, adjustment, exception, or reopening path. The event must preserve historical truth and must not rewrite the original Night Audit closure evidence. Business-date assignment for external integrations must be validated against tenant/property policy, event timestamps, source reliability, and audit evidence. No source client may bypass business-date controls by sending a manually selected date.

### 6. Business Date, Revenue, Tax, AR, Cash, and Financial Period Boundaries
A closed business date does not automatically close a Finance period. A Finance period close does not silently reopen a previously closed business day. A late charge, refund, correction, late invoice, or tax adjustment after business-date closure must follow the applicable source-domain and finance-period policy. Closed business day evidence must remain traceable when later adjustments occur. `tax-pending` events under ADR-033 must not be treated as final tax-sensitive closure outcomes; finalization requires governed determination or an authorized exception. A business-date exception must not bypass revenue, tax, AR, approval, audit, or financial-period controls. Consolidated Tenant reporting must clearly distinguish Property business date, actual timestamp, and financial reporting period.

**Business Date vs. Finance/Tax/Revenue Boundaries**
| Process | Architectural Boundary Rule |
| :--- | :--- |
| **Business Date Closure** | Operational hotel-day closure and evidence. Owned by Business Date domain. |
| **Revenue Recognition** | Governed by ADR-025. |
| **Tax Determination** | Governed by ADR-033. |
| **AR / City Ledger** | Governed by ADR-028. |
| **Payment & Bank Rec** | Governed by ADR-019. |
| **Financial / Cost Ledger**| Governed by ADR-004 and ADR-012 where applicable. |
| **Financial Period Close** | Governed by ADR-013. |

### 7. Reopen, Corrections, and Post-Audit Adjustments
Reopening a closed business day is exceptional, not routine operational behavior. Reopened is a limited, exceptional correction state and is not equivalent to returning the Property business day to unrestricted normal Open operation. Reopen scope must identify the minimum affected process, source event, transaction set, or correction objective. Reopen must not allow unrestricted re-entry of ordinary postings merely because the business day is unlocked. Reopen request requires reason, scope, impact statement, requesting identity, independent approval where required, and immutable audit evidence. A requester must not self-approve a high-impact reopen affecting their own financial or operational activity. Reopen must be time-limited and scoped to the minimum necessary Property, business date, process, or source event. Reopen must not erase prior closure evidence, prior audit-run results, original timestamps, tax determination, ledger evidence, or financial-period status. If a Finance period is closed, correction must follow ADR-013 adjustment and period-exception policy rather than mutating the closed period. Any re-run, retry, reconciliation, or post-audit adjustment must preserve original closure evidence and create a linked post-audit adjustment or reopened-run reference. Reopen and retry must remain idempotent and must not duplicate source events, revenue postings, tax events, financial consequences, or downstream attestations. Completion of a reopen must create a governed re-closure or post-audit correction outcome with immutable audit evidence. Emergency reopen must follow ADR-030 break-glass boundaries and must never become a routine bypass.

### 8. Multi-Tenant, Multi-Property, Time-Zone, and Group Reporting Boundaries
Business date is Property-scoped operationally. Tenant governs policy defaults, ownership, approval, audit expectations, and cross-property reporting controls. A Property cannot change its time zone or cutover policy without authorized, effective-dated configuration and immutable audit evidence. Cross-property reports must not assume that a single calendar date equals the same business date across all Properties. Group or Tenant consolidated reporting must retain Property identity, local time zone, business date, and reporting-period context. A Property-to-Property or intercompany event may require distinct business-date evidence for each accountable side. Cross-tenant business-date data access or benchmarking must follow Enterprise governance and ADR-031 privacy/data-isolation boundaries.

### 9. Segregation of Duties and Operational Controls
A night auditor must not self-approve their own high-impact closure exception or reopen request. A business-date configuration maintainer must not independently create, approve, and activate a high-impact cutover/time-zone change. A system administrator must not silently alter business date, close status, or reopen outcome without audit evidence and governed authorization. Emergency operation must be time-limited, justified, and fully audited. Detailed permissions remain governed by ADR-029. Identity, session, privileged recovery, and break-glass controls remain governed by ADR-030.

**Roles to Segregate:** Night auditor, Property operational manager, Finance reviewer, Business-date configuration maintainer, Reopen requester, Reopen approver, System administrator, Emergency break-glass operator.

### 10. Audit, Privacy, Retention, and Evidence
Audit evidence must identify actor/system identity, Tenant, Property, business date, actual timestamps, source references, relevant policy/rule version, and outcome. Audit logs must not expose unnecessary guest PII, payment secrets, raw tokens, credentials, or unmasked Restricted data. Business-date evidence, exception records, and audit trails must follow ADR-031 retention, legal hold, data residency, masking, and controlled support-access rules. Records with financial, audit, tax, or fraud relevance must not be casually hard-deleted.

**Minimum Night Audit Audit Events**
| Event Type | Required Audit Scope |
| :--- | :--- |
| **Lifecycle Change** | Business-day opening and closure. |
| **Run Status** | Night Audit run start, success, block, retry, resume, abort, and completion. |
| **Exceptions** | Closure exception creation, approval, rejection, and expiry. |
| **Policy Change** | Cutover policy or time-zone change. |
| **Late Events** | Late-event classification and business-date resolution. |
| **Reopen / Correction**| Reopen request, approval, rejection, expiry, completion, post-audit adjustment reference. |
| **Access** | Controlled access to business-date evidence. |

### 11. Failure Modes and Safe Degradation
Fail closed for final operational closure, business-date assignment, tax-sensitive closure, external reporting, export, or high-impact reopen when essential controls cannot be resolved. Preserve minimum safe audit and incident evidence. Do not silently discard material operational, revenue, financial, tax, or guest-related events. Use governed exception, controlled pending state, safe rejection, or idempotent retry/replay where appropriate. Do not expose Confidential or Restricted payloads in logs. Interrupted Night Audit must be safely resumable and must not duplicate close consequences. Financial-period status uncertainty should block only the financial-period-impacting action or output that depends on it, unless the checkpoint is explicitly classified as a Hard Blocker.

**Failure Modes and Safe Outcomes**
| Failure Condition | Safe Outcome |
| :--- | :--- |
| Property timezone or cutover policy unresolvable. | Fail-closed for operational closure or business-date assignment. |
| Night Audit run is interrupted. | Safely resumable; must not duplicate consequences. |
| Duplicate Night Audit or close request occurs. | Idempotent rejection; preserve audit evidence. |
| Required source module is unavailable. | Block operational closure if classified as Hard Blocker; follow policy. |
| Queue or event processing is delayed/ambiguous. | Do not discard events; preserve received timestamps; hold closure if critical. |
| Source timestamp missing/unreliable/conflicting. | Fail-closed for automatic assignment; use governed exception. |
| A late event arrives after operational closure. | Preserved as late event; governed correction/adjustment path required. |
| Tax determination pending or unavailable. | Fail-closed for tax-sensitive closure; preserve operational state or use Exception-Eligible handling if policy permits. |
| Financial-period status cannot be resolved. | Fail-closed for period-impacting actions; block unless exception approved. |
| Reopen requested while run/reopen in progress. | Fail-closed; reject concurrent conflicting requests. |
| Business-date evidence cannot be persisted. | Fail-closed for state transition; alert operations. |
| Privacy/residency unresolved for export/access. | Fail-closed for export or transmission. |

### 12. PMS Readiness and Implementation Boundary
This ADR governs accepted and future PMS-adjacent implementation wherever Property-level Business Date evidence, Night Audit locks, guest folio cutover, front-office posting cutover, or multi-property hospitality business-date reporting are involved. PMS Guest Ledger, PMS Cashiering, Front Office, guest folio, cashier, reservation, POS, and room-status modules must honor this ADR when they consume or expose Business Date / Night Audit evidence.

This ADR does not define PMS database schema, user interface, operational checklist screens, room status behavior, folio calculations, posting engine code, or Night Audit job implementation. Exact close-check catalog, operational thresholds, exception limits, cutoff times, and staffing procedures remain configurable Tenant/Property policy. Future PMS module documents must define their source-domain attestations and failure behavior against this Business Date architecture without transferring their source-domain ownership to Business Date or Night Audit.

## Alternatives Considered
- **Treating midnight UTC as universal business cutoff:** Rejected. Fails operational reality for a globally distributed SaaS platform where properties operate in distinct local time zones with varied hotel-day cutoffs.
- **Combining Business Date and Finance Period close:** Rejected. Operational hotel-day closure must happen daily for property management; financial periods are monthly accounting constructs. Coupling them causes catastrophic workflow blocks.

## Consequences

### Positive Consequences
- Guarantees absolute temporal integrity by separating immutable UTC timestamps from operational business dates and financial periods.
- Prevents cascading corruption by ensuring delayed integrations or late events cannot silently alter closed audit evidence.

### Trade-Offs and Risks
- Increases orchestration complexity by demanding explicit, idempotent attestation checks across distinct domains rather than a single monolithic "close" script.
- Properties with sloppy operating procedures will experience "blocked closures" due to strict exception enforcement.

### Operational Requirements
- This ADR is mandatory before BD-A1 and Night Audit runtime packages begin and constrains current or future PMS-adjacent packages that consume or expose Business Date / Night Audit evidence.
- Business-date closure is separate from Finance period close.
- Business date is Property-scoped while Tenant policy governs defaults and cross-property reporting.
- Exact operational close checklist and cutoff policies are configurable.
- Future PMS, Front Office, POS, Cashiering, and Reservations module designs must conform to this ADR.

## Dependencies and Related ADRs
- ADR-001 — Multi-Tenant Hierarchy
- ADR-002 — Audit Trail Strategy
- ADR-003 — Approval Engine
- ADR-004 — Finance Module Boundary
- ADR-013 — Period Closing Strategy
- ADR-017 — Event-Driven Accounting and Queue Resiliency Strategy
- ADR-019 — Payment and Bank Reconciliation Engine
- ADR-025 — Revenue Recognition and Tax Engine
- ADR-028 — Accounts Receivable and City Ledger Strategy
- ADR-029 — Security, Roles and Permissions Governance
- ADR-030 — Identity, Authentication and Session Governance
- ADR-031 — Data Privacy, PII, Retention and Data Residency Governance
- ADR-033 — Global Tax and Jurisdiction Compliance Architecture

## Deferred Decisions
- Exact operational checklist definitions, folio structures, room status integrations, and UI implementation details are deferred to future PMS module design.

## Open Questions Requiring CTO Approval
- None at this time.

## Validation Criteria
- System timestamps (UTC) are never overwritten by business-date changes.
- A property cannot have more than one open business day without explicit, governed exception rules firing.
- Delayed events map to the currently active business day or an explicit exception bucket, never silently backdating a closed day.
- A Night Audit run can be aborted and safely resumed without duplicate postings.

## References
- Internal: IVORQ ADR Master Structure Review
