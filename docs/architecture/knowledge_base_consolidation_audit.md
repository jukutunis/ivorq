# Knowledge Base Consolidation Audit

## Overview
This audit reviews the contents of `docs/knowledge-base/`, with a specific focus on identifying duplicate, superseded, and archival documentation based on the newly established Documentation Governance Rules.

---

## 1. Duplicate & Superseded Documents
The entire `docs/knowledge-base/99-Archive/Project_Knowledge_Foundation/` directory acts as a legacy store. Every document within it has been superseded by newer, categorized versions in the active knowledge base directories:

- **Superseded by `01-Vision-Strategy/`**:
  - `product-vision.md`
  - `roadmap.md`

- **Superseded by `02-Architecture/`**:
  - `api-spec.md`
  - `architecture.md`
  - `coding-standards.md`
  - `database.md`
  - `folder-structure.md`
  - `security-architecture.md`
  - `ui-guidelines.md`

- **Superseded by `03-Foundation-Engines/`**:
  - `activity-log-spec.md`
  - `attachment-engine-spec.md`
  - `audit-log-spec.md`
  - `authorization-spec.md`
  - `dashboard-framework-spec.md`
  - `integration-spec.md`
  - `notification-engine-spec.md`
  - `pwa-spec.md`
  - `reporting-engine-spec.md`
  - `search-engine-spec.md`
  - `workflow-engine-spec.md`

- **Superseded by `04-Governance/`**:
  - `ai-constitution.md` (replaced by `ai-development-constitution.md`)

- **Other Superseded/Legacy items**:
  - `business-rules.md`
  - `prd-foundation.md`

---

## 2. Documents That Should Move to `docs/archive/`
To comply with the strict documentation repository paths (which designate `docs/archive/` as the single historical record location):

1. **The `99-Archive` Directory**: 
   - All contents of `docs/knowledge-base/99-Archive/` should be migrated to `docs/archive/knowledge-base-v1/`.
2. **Execution/Sprint Planning**: 
   - Files in `docs/knowledge-base/13-Execution/` such as `sprint-01-foundation.md`, `mvp-build-plan.md`, `implementation-order.md` are transient execution artifacts and should be moved to `docs/archive/`.
3. **Draft Folder Structures**:
   - `docs/knowledge-base/11-Foundation-Build/laravel-folder-structure-v1.md` should be archived since it is superseded by `02-Architecture/folder-structure.md`.

---

## 3. Documents That Remain KEEP
The remaining active domain specifications, product requirements, and engineering guidelines represent the current state of the IVORQ architecture and should be classified as **KEEP**:

- `01-Vision-Strategy/*`
- `02-Architecture/*`
- `03-Foundation-Engines/*`
- `04-Governance/*`
- `05-Database/*`
- Domain PRDs (`06-Operations`, `07-PMS`, `08-POS`, `09-Finance`, `10-HRIS`, `14-Future-Domains`)
- AI Templates (`12-AI-Development/`)
- `MASTER_INDEX.md`
- `CHANGELOG.md`

*(Note: Depending on the absolute strictness of the new root-level folder governance, these KEEP documents may ultimately need to be migrated from `docs/knowledge-base/` into `docs/architecture/` and `docs/roadmap/`. However, logically, their content classification remains KEEP).*
