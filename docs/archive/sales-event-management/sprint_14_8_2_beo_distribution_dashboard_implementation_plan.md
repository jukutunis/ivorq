# SPRINT 14.8.2 — BEO DISTRIBUTION DASHBOARD IMPLEMENTATION PLAN

**Classification**: ARCHIVE  
**Domain**: Sales & Event Management / Operations Execution  

## Executive Summary
This implementation plan outlines the engineering blueprint for the BEO Distribution Dashboard. Applying the CTO's strict architectural revisions from Sprint 14.8.1, this design separates the distribution lifecycle and tracking mechanism from the payload itself. `BEODistribution` acts purely as an intelligent tracking layer referencing immutable `BEOSnapshot` records. The inclusion of configurable, department-specific Service Level Agreements (SLAs) ensures operational accountability via automated escalations and smart notifications.

---

## Entity Plan

### Enums
- **DistributionSeverityEnum:** `MINOR`, `MAJOR`, `CRITICAL`
- **AcknowledgementStatusEnum:** `PENDING`, `VIEWED`, `ACKNOWLEDGED`, `REJECTED`, `ESCALATED`

### Models
1. **BEODistribution**
   - **Role:** Tracks the distribution record for a given snapshot.
   - **Fields:** `ulid id`, `ulid property_id`, `ulid beo_snapshot_id` (NO PAYLOAD DUPLICATION), `string severity` (DistributionSeverityEnum), `datetime distributed_at`, `string status`.
2. **BEOAcknowledgement**
   - **Role:** Tracks the department-level SLA and sign-off state.
   - **Fields:** `ulid id`, `ulid beo_distribution_id`, `ulid department_id`, `ulid user_id` (actor), `string status` (AcknowledgementStatusEnum), `integer sla_hours_configured`, `datetime sla_breach_at`, `datetime viewed_at`, `datetime acknowledged_at`, `string rejection_reason`.
3. **DistributionEscalation**
   - **Role:** Records an SLA breach and its routing.
   - **Fields:** `ulid id`, `ulid beo_acknowledgement_id`, `integer escalation_level`, `datetime escalated_at`, `ulid escalated_to_role_id`.
4. **DistributionAuditTrail**
   - **Role:** Immutable event store for the distribution lifecycle.

---

## Service Plan

1. **BEODistributionService**
   - Generates the `BEODistribution` record when a `BEOSnapshot` is published.
   - Determines `DistributionSeverity` (e.g., Major if T-24h, Critical if T-4h).
2. **AcknowledgementEngine**
   - Handles tracking of "Viewed" events (read receipts).
   - Validates user permissions for department sign-off.
   - Calculates dynamic SLA timing based on department configuration (e.g., Kitchen=24h, Security=4h) to set the `sla_breach_at` timestamp.
3. **DistributionEscalationService**
   - A cron/daemon scheduled service that polls for `sla_breach_at < now()` where status is not `ACKNOWLEDGED`.
   - Triggers the escalation matrix and emits notification commands.

---

## Workflow Integration Plan

The `BEODistribution` seamlessly ties into the Foundation Workflow Engine traversing these strict states:
1. **DRAFT:** Pre-distribution.
2. **PENDING_APPROVAL:** Internal Sales leadership review.
3. **PUBLISHED:** `BEOSnapshot` generated.
4. **DISTRIBUTED:** `BEODistribution` record created. SLA clocks start.
5. **ACKNOWLEDGED:** All mandatory departments have signed off.
6. **REVISED:** Triggers `Superseded` on the previous distribution, launches a new distribution loop.
7. **COMPLETED:** Locked post-event.
8. **CANCELLED:** Halts all SLA clocks and broadcasts termination.

---

## Notification Integration Plan

The `NotificationEngine` will consume commands emitted by the `DistributionEscalationService` and `AcknowledgementEngine`.
- **In-App:** Standard delivery for standard BEO distributions.
- **Push Notification:** Triggered for `CRITICAL` severity distributions or late revisions.
- **Email:** External vendors, Exec digest summaries.
- **Operations Board Broadcast:** Real-time visual flashing alerts on BOH screens for `ESCALATED` or `REJECTED` states.
- **Future WhatsApp:** Architecture payload mapped for future webhook consumption.

---

## Universal Search Integration Plan

The `BEODistribution` aggregate will be pushed to the Search Index containing the following searchable facets:
- **BEO Number** (Referenced via Snapshot/Event)
- **Event Name**
- **Account Name**
- **Venue** (Referenced via Function)
- **Department** (Mapped via Acknowledgement status)
- **Status** (e.g., "status:escalated")
- **Severity** (e.g., "severity:critical")

---

## Testing Plan (Target: 100% PASS)

Feature tests must cover the following critical operational vectors:
1. **Property Isolation:** Ensure a user from Property A cannot query or acknowledge a BEO from Property B.
2. **Distribution Ownership:** Assert that `BEODistribution` does not duplicate JSON payload fields and correctly fetches from `BEOSnapshot`.
3. **Acknowledgement Workflow:** Assert that `PENDING` transitions to `VIEWED` when opened, and `ACKNOWLEDGED` when signed.
4. **Escalation Workflow:** Assert that `sla_breach_at` correctly calculates off the department SLA SLA configuration (e.g., +24h, +4h) and accurately triggers `DistributionEscalation`.
5. **Search Index Readiness:** Validate Elasticsearch/Scout payload structure.
6. **Operations Board Projection:** Assert rejected/escalated items format correctly for the BFF timeline.
7. **Notification Routing:** Intercept events and assert correct channel mapping based on severity.

---

## Enterprise Risks

1. **SLA Calculation Nuance:** Basic `addHours()` for SLA calculations might trigger breaches at 3:00 AM. Architecture must be ready to support "Business Hours" SLA awareness in the future if required by operations.
2. **Snapshot Integrity:** If a `BEOSnapshot` is somehow deleted, the `BEODistribution` is orphaned. Hard foreign key constraints `onDelete('restrict')` must be enforced.

---

## Recommended Sprint 14.8.3 Scope

1. **Implementation Phase:**
   - Execute migrations for Enums, Models, and Tables.
   - Implement `BEODistributionService` and `AcknowledgementEngine`.
   - Write comprehensive tests per the Testing Plan.
   - Generate Sprint 14.8.3 Completion Report.

---

## Final Recommendation
**Status: APPROVED FOR CODING**

The implementation plan perfectly maps the CTO's revisions to concrete engineering tasks. By enforcing the Snapshot-reference model, the database avoids massive bloat. Proceed directly to the implementation sprint.
