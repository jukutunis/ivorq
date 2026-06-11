# Architecture Decision Records (ADR) Registry

**Document Type:** Central ADR Catalog
**Status:** Live

This registry tracks the immutable architectural choices that dictate IVORQ's enterprise structure.

---

### ADR-001: Asset Uses Closure Tables
- **Status:** Approved
- **Decision:** The `AssetHierarchy` and `LocationHierarchy` must be implemented using Closure Tables instead of simple recursive `parent_id` foreign keys.
- **Reason:** Standard SQL recursion collapses under heavy load when calculating depths of 6+ levels (e.g., HVAC systems). Closure tables guarantee constant read times for massive multi-property trees.
- **Affected Modules:** Asset, Location.

### ADR-002: Asset QR URI Standard
- **Status:** Approved
- **Decision:** QR codes must use the strict URI deep-linking format `ivorq://{entity}/{ulid}` (e.g., `ivorq://asset/01H2...`).
- **Reason:** Enables native OS intercepting to directly launch the PWA and eliminates parsing legacy JSON payloads.
- **Affected Modules:** Asset, Location, PWA.

### ADR-003: Media Uses Cloudflare R2
- **Status:** Approved
- **Decision:** Media payloads (photos, videos, docs) will prioritize Cloudflare R2 over AWS S3 for primary active storage.
- **Reason:** Zero egress fees prevent bandwidth bankruptcy when 10,000 technicians view massive Blueprints daily.
- **Affected Modules:** Media.

### ADR-004: Operational Asset Financial Separation
- **Status:** Approved
- **Decision:** Operational `Asset` tables are strictly prohibited from tracking `Purchase Price`, `Depreciation`, or `Salvage Value`.
- **Reason:** Avoids mingling pure accounting math with operational uptime status. A mapping `FinancialAsset` table in the Finance domain will link to the Asset ULID.
- **Affected Modules:** Asset, Finance Core.

### ADR-005: Property Isolation Mandatory
- **Status:** Approved
- **Decision:** Every major operational entity MUST include a `property_id` column protected by strict Eloquent Global Scopes.
- **Reason:** Ultimate guardrail against multi-tenant data leakage.
- **Affected Modules:** Global.

### ADR-006: Offline First PWA
- **Status:** Approved
- **Decision:** The mobile application must be capable of surviving basement network drops.
- **Reason:** High-priority Work Orders happen in utility tunnels without 4G. IndexedDB and Background Sync are mandated to capture state offline.
- **Affected Modules:** Logbook, Incident, Work Orders, Asset.

### ADR-007: Cursor Pagination Required
- **Status:** Approved
- **Decision:** All REST APIs listing resources that can exceed 10k rows must use Cursor Pagination (`?cursor=`); offset pagination (`?page=`) is banned.
- **Reason:** Offset pagination forces PostgreSQL to scan and discard millions of rows, killing database performance at scale.
- **Affected Modules:** Timeline, Audit Logs, API Layer.

### ADR-008: Timeline Uses Partitioning
- **Status:** Approved
- **Decision:** `timeline_events` MUST be partitioned natively in PostgreSQL by Year/Month.
- **Reason:** 10 million events sitting in a flat table will make archival and querying impossible within 2 years of enterprise deployment.
- **Affected Modules:** Timeline, Checklist Responses.

### ADR-009: Incident CAPA Enforcement
- **Status:** Approved
- **Decision:** An Incident cannot be marked `Closed` if attached Corrective/Preventive Actions remain open.
- **Reason:** Hard requirement for legal compliance and ISO standard audits to prove issues are structurally mitigated, not just "paper closed".
- **Affected Modules:** Incident, Work Orders.

### ADR-010: Asset Is Master Entity
- **Status:** Approved
- **Decision:** Preventive Maintenance, Work Orders, and Engineering Consumption are completely subordinate to the Asset Foundation.
- **Reason:** Equipment cannot be maintained in software unless it definitively exists as a physical tracking point in the DB.
- **Affected Modules:** Asset, PM, Work Orders.
