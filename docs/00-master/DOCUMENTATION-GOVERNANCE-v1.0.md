# Documentation Governance System (v1.0)

**Document Type:** Master Governance Protocol
**Status:** Pending CTO Approval

---

## 1. Domain Analysis: The Documentation Crisis
The IVORQ project has scaled massively. Currently, all blueprints, reviews, and implementation plans are dumped into a flat `docs/architecture/` directory.
**Risks Identified:**
- **Duplication & Conflict:** An old `v1.0` document might contradict a `v2.1` revision.
- **AI Context Loss:** Feeding 100+ disjointed markdown files into an AI context window causes hallucinations and architectural amnesia.
- **Maintenance Nightmare:** New engineers (and AI agents) cannot definitively identify the "single source of truth".

This Governance System mandates a rigid, hierarchical taxonomy to eliminate documentation sprawl and enforce absolute architectural integrity.

---

## 2. Final Document Hierarchy

The `docs/` directory will be strictly reorganized into the following domain-bounded folders:

| Directory | Purpose | Ownership |
| :--- | :--- | :--- |
| `00-master/` | Single Source of Truth rules, Governance, Master Locks, and ADRs. | CTO |
| `01-finance/` | GL, AP, AR, Treasury, Budgeting architecture and implementation. | Finance Architect |
| `02-operations/` | Assets, PM, Work Orders, Incidents, Media, Locations. | Operations Architect |
| `03-integrations/` | External mappings (PMS, BMS, Door Locks, IoT). | Integration Architect |
| `04-ui-ux/` | PWA workflows, design tokens, component libraries. | Frontend Architect |
| `05-database/` | ERDs, Partitioning rules, Indexing strategies. | DBA |
| `06-api/` | REST/GraphQL schemas, Webhook structures, Auth flows. | Backend Architect |
| `07-dev/` | Environment setup, Git workflows, Testing standards. | DevOps |
| `archive/` | Historical, deprecated, and obsolete files. | System (Automated) |

---

## 3. Master Architecture Lock

To prevent AI from regenerating core concepts, IVORQ will utilize `docs/00-master/MASTER-ARCHITECTURE-LOCK-v1.0.md`.
This is the **Supreme Source of Truth**. It dictates:
- **Domain Boundaries:** What constitutes Finance vs Operations.
- **Module Dependencies:** (e.g., Work Orders *must* depend on Asset Foundation).
- **Standards:**
  - *Naming:* ULID primary keys, explicit snake_case DB columns.
  - *Storage:* Cloudflare R2 for Media, Partitioned PostgreSQL tables.
  - *Security:* Strict `property_id` global scoping on every entity.
  - *API:* Cursor-based pagination mandated for large datasets.

---

## 4. Official Document Types

To prevent naming chaos, every file must belong to one of these explicit types:

| Type | Suffix / Name | Allowed Usage |
| :--- | :--- | :--- |
| **Architecture Blueprint** | `*-Blueprint.md` | High-level domain theory and entity design. |
| **Implementation Plan** | `*-Implementation-Plan.md` | Exact, actionable instructions for creating code. |
| **Review** | `*-Review.md` | Audits or critiques of existing code/plans. |
| **Decision Record** | `ADR-*.md` | Immutable logs of major technical choices. |
| **Roadmap** | `*-Roadmap.md` | Timeline and sprint scheduling. |
| **Specification** | `*-Spec.md` | Strict technical details (e.g., API payloads). |

---

## 5. Architecture Decision Records (ADR) System

When a major architectural choice is made, it is recorded in `docs/00-master/ADRs/` and never debated again unless formally challenged.

**Examples:**
- `ADR-001-Asset-Uses-Closure-Table.md`: Mandates closure tables over recursive SQL.
- `ADR-002-Asset-QR-URI-Standard.md`: Enforces `ivorq://asset/{ulid}` deep-linking.
- `ADR-003-Media-Uses-Cloudflare-R2.md`: Forbids standard AWS S3 to prevent egress costs.
- `ADR-004-Asset-Financial-Data-Separation.md`: Forbids Depreciation fields on Operational Assets.

**Enforcement:** AI agents must read relevant ADRs before proposing any new architecture.

---

## 6. AI Governance Rules (MANDATORY)

Before creating **ANY** new module, blueprint, or implementation plan, the AI Agent **MUST**:
1. Read `MASTER-ARCHITECTURE-LOCK-v1.0.md`.
2. Read all relevant ADRs in `00-master/ADRs/`.
3. Read the underlying Foundation files (e.g., Timeline, Media, Location) before designing a dependent module (like Work Orders).
4. **Refuse Duplication:** Actively search the target directory. If a Blueprint already exists, the AI must explicitly refuse to create a new one, opting instead to suggest a `Review` or an `ADR`.

---

## 7. Document Lifecycle

Documents move through a strict state machine:
1. **Draft:** Work in progress. Open for AI/Human iteration.
2. **Review:** Pending CTO approval. No code generation allowed.
3. **Approved:** Ready for implementation.
4. **Locked:** Code is in production. The document cannot be modified. Any changes require a new `Revision` document.
5. **Deprecated:** Replaced by a newer revision.
6. **Archived:** Physically moved to `docs/archive/`.

---

## 8. Module Document Structure

Every module (e.g., `Asset`, `WorkOrder`, `GeneralLedger`) must strictly follow this internal structure inside its domain folder (e.g., `02-operations/asset/`):

- `architecture/`: Blueprints, Entity Relationship Diagrams.
- `implementation/`: Implementation Plans, Migration lists.
- `reviews/`: Security Audits, CTO Reviews.
- `decisions/`: Module-specific ADRs.
- `specifications/`: API Contracts, JSON schemas.

---

## 9. Archive Strategy

- **Trigger:** When `v2.0` of a document is Approved, `v1.0` is instantly transitioned to `Archived`.
- **Physical Move:** The file is moved from its domain folder to `docs/archive/{domain}/{module}/`.
- **Filename Tagging:** The file is renamed to `{filename}-OBSOLETE-{date}.md`.
- **AI Enforcement:** The AI is strictly forbidden from reading files in the `docs/archive/` directory during active implementation planning to prevent pulling stale context.

---

## 10. Implementation Roadmap

This governance system will be implemented in the following sequence:

1. **Phase 1: Folder Scaffolding:** Create `00-master` through `07-dev` and `archive`.
2. **Phase 2: Archive Migration:** Move the existing 40+ documents currently sitting in the flat `docs/architecture/` folder into their correct domain buckets (`01-finance`, `02-operations`).
3. **Phase 3: Master Lock Creation:** Extract the universal truths from the past 10 sprints and compile them into `MASTER-ARCHITECTURE-LOCK-v1.0.md`.
4. **Phase 4: ADR Extraction:** Extract explicit CTO Decisions (e.g., "Use R2", "No IoT in Assets") into standalone ADR files.
5. **Phase 5: Policy Enforcement:** Delete the old flat `docs/architecture/` folder entirely. All future AI prompts will reference the new structure.
