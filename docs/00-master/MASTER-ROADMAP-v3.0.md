# IVORQ MASTER ROADMAP v3.0

**Document Type:** Master Roadmap & Single Source of Truth
**Status:** Ready For CTO Review

---

## 1. Executive Summary
IVORQ is an enterprise-grade multi-property SaaS platform designed for high-scale hospitality operations. As of v3.0, the core architectural foundation, data governance protocols, UI/UX design systems, and primary operational modules (Asset, Maintenance, Work Order, Engineering Workspace) have been validated and locked. The platform is transitioning from the Foundation phase into the Orchestration and scaling phases.

## 2. Product Vision
To deliver a "Data First, Mobile First, Hospitality First" operational command center that allows multi-property executives to manage physical assets, engineering workflows, and financial subledgers through a unified, ultra-fast interface with zero cognitive overload.

## 3. Architecture Status
- **Database:** PostgreSQL (with explicit partitioning for high-volume data like Timelines and Checklists).
- **Primary Keys:** ULID exclusively for absolute uniqueness and offline generation capability.
- **Backend:** Laravel 11/12 API with strict Service/Repository isolation.
- **Frontend:** React/Inertia.js PWA, adhering strictly to IVORQ Design System v1.1.
- **Storage:** Cloudflare R2 strictly mandated for all media.
- **Global Constraints:** Strict `property_id` Eloquent scoping.

## 4. Governance Status
- **Documentation Governance (v1.0):** Locked. Strict directory separation enforced (`00-master`, `01-finance`, `02-operations`, etc.).
- **ADR System:** Live. Major decisions (e.g., Closure Tables, Offline PWA Sync, Cursor Pagination) are immutable.
- **Test Integrity:** 100% Green (1500 Tests Passed). Automated legacy deprecation sweeps are complete.

## 5. Domain Registry
| Domain | Scope | Status |
| :--- | :--- | :--- |
| **Foundation** | Core system objects (User, Property, Audit, Permission) | Locked |
| **Finance** | GL, Subledgers, Budgets, Treasury | Blueprint Locked |
| **Operations** | Assets, Work Orders, Maintenance, Incidents | Validated & Locked |
| **Housekeeping**| Room status, Cleaning flows | Planned |
| **Integrations**| BMS, PMS, Door Locks | Blueprint |

## 6. Module Registry
*(Status Classifications: Blueprint, Implementation, Validated, Locked, Deprecated)*

| Module | Classification |
| :--- | :--- |
| **Location Foundation** | Locked |
| **Department Foundation** | Locked |
| **Timeline Foundation** | Locked |
| **Checklist Foundation** | Locked |
| **Media Foundation** | Locked |
| **Logbook Foundation** | Locked |
| **Asset Management** | Locked |
| **Preventive Maintenance**| Locked |
| **Work Order** | Locked |
| **Audit Trail** | Validated |
| **General Ledger** | Blueprint |
| **Subledger** | Blueprint |
| **Budget & Forecast** | Blueprint |

## 7. Dependency Matrix
- **Asset** requires: `Location`, `Media`, `Timeline`, `Checklist`.
- **Preventive Maintenance** requires: `Asset`, `Checklist`, `Timeline`.
- **Work Order** requires: `Asset`, `Incident`, `Location`, `SLA Engine`.
- **Engineering Workspace** requires: `Work Order`, `Asset`, `PM`, `Logbook`.

## 8. Foundation Status
All universal foundation modules are **Locked**. The underlying data structures required for cross-domain orchestration are stable and fully tested.

## 9. Operations Status
Operations modules have completed their implementation sprints. The Asset Management, Preventive Maintenance, and Work Order modules are **Validated** against strict CAPA compliance, offline-first mobile sync requirements, and closure table hierarchies.

## 10. Workspace Status
- **Engineering Workspace v2.3.1:** **Validated**. The command center orchestrates data via 6 Aggregator Services and a mathematically weighted `WorkspacePriorityEngine`.

## 11. Finance Status
- Currently resting at the **Blueprint** phase. The Architecture is defined but backend implementation of the Subledger Posting Engine and Trial Balance processors remains pending.

## 12. Integration Status
- **PMS Gateway:** Blueprint.
- **BMS / IoT:** Future.

## 13. Validation Status
- **Pipeline:** Green.
- **Coverage:** 1500 Tests / 4130 Assertions executing correctly. 
- **Legacy:** All deprecated `Engineering` models successfully purged.

## 14. Release Status
- **Current Tag:** `v2.3-engineering-workspace`
- **Deployment Status:** Ready for UAT (User Acceptance Testing) deployment for the Engineering Operations suite.

## 15. Technical Debt
- **Cache Sizing:** Offline PWA caching limits require strict enforcement logic on the client side to prevent IndexedDB bloat (maximum 7 days retention for assigned tasks).
- **Realtime Infrastructure:** Laravel Echo/Reverb WebSockets implementation must be load-tested for the Engineering Command Center.

## 16. Risk Matrix
| Risk | Probability | Impact | Mitigation Strategy |
| :--- | :--- | :--- | :--- |
| Multi-tenant Data Bleed | Low | Critical | Global Scopes on `property_id` universally applied. |
| Mobile Offline Conflicts | Medium | High | "First Sync Wins" + Immutable timeline append strategy. |
| DB Partitioning Failure | Low | High | Pre-create partitions via CRON jobs 3 months in advance. |

## 17. Planned Modules
- **Housekeeping Workspace:** PWA-focused execution dashboard for Room Attendants.
- **Inventory & Purchasing:** Spare parts management tied to Work Orders.

## 18. Future Modules
- **HRIS (Human Resources):** Time & Attendance, Roster management.
- **AI Knowledge Base:** Automated SOP generation from closed Incidents.

## 19. Version Roadmap
- **v1.0 Series:** Framework & Governance. *(Completed)*
- **v2.0 Series:** Operations & Workspaces. *(Current)*
- **v3.0 Series:** Finance & Integrations. *(Next)*
- **v4.0 Series:** Housekeeping & Inventory.
- **v5.0 Series:** AI Command Centers.

## 20. CTO Priorities
1. **Maintain Zero Dependency Drift:** Enforce architectural isolation between Operations and Finance.
2. **Cluster Dashboard Expansion:** Develop ELT pipelines to populate the Data Warehouse for cluster-level metrics without querying live transactional shards.
3. **PWA Rollout:** Secure mobile app distribution strategies (App Clip / Instant App / Direct Install).
4. **Prepare Finance Sprint:** Lock in the Subledger logic required for real-time cost tracking against Work Orders.
