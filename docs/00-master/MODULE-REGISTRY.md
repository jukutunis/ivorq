# Module Registry

**Document Type:** Central Module Catalog
**Status:** Live

This document lists every active and planned module within IVORQ, ensuring clear architectural boundaries, dependencies, and file locations.

---

## 1. General Ledger
- **Domain:** Finance
- **Status:** Locked
- **Current Version:** v1.0
- **Dependencies:** None
- **Dependents:** Subledger, Trial Balance, P&L
- **Blueprint Location:** `docs/architecture/General-Ledger-Implementation-Plan.md`
- **Implementation Location:** `app/Domains/Finance/GeneralLedger/`

## 2. Budget & Forecast
- **Domain:** Finance
- **Status:** Locked
- **Current Version:** v1.0
- **Dependencies:** General Ledger, Chart of Accounts
- **Dependents:** Treasury, P&L
- **Blueprint Location:** `docs/architecture/Budget-Foundation-Implementation-Plan.md`
- **Implementation Location:** `app/Domains/Finance/Budget/`

## 3. Treasury
- **Domain:** Finance
- **Status:** Locked
- **Current Version:** v1.0
- **Dependencies:** Budget, Forecast, Accounts Payable, Banking
- **Dependents:** None
- **Blueprint Location:** `docs/architecture/Treasury-Foundation-Implementation-Plan.md`
- **Implementation Location:** `app/Domains/Finance/Treasury/`

## 4. Department
- **Domain:** Operations
- **Status:** Locked
- **Current Version:** v2.1A (Rev 1.1)
- **Dependencies:** Property
- **Dependents:** All Operational Modules
- **Blueprint Location:** `docs/architecture/Department-Foundation-Implementation-Plan-v1.1.md`
- **Implementation Location:** `app/Domains/Operations/Department/`

## 5. Location
- **Domain:** Operations
- **Status:** Locked
- **Current Version:** v2.1B (Rev 1.1)
- **Dependencies:** Property
- **Dependents:** Asset, Incident, Housekeeping, PM
- **Blueprint Location:** `docs/architecture/Location-Foundation-Implementation-Plan-v1.1.md`
- **Implementation Location:** `app/Domains/Operations/Location/`

## 6. Media
- **Domain:** Operations (Universal)
- **Status:** Locked
- **Current Version:** v2.1C (Rev 1.1)
- **Dependencies:** None
- **Dependents:** All Modules
- **Blueprint Location:** `docs/architecture/Media-Foundation-Implementation-Plan-v1.1.md`
- **Implementation Location:** `app/Domains/Media/`

## 7. Timeline
- **Domain:** Operations (Universal)
- **Status:** Locked
- **Current Version:** v2.1D
- **Dependencies:** Media
- **Dependents:** Incident, Asset, Logbook, Work Orders
- **Blueprint Location:** `docs/architecture/Timeline-Foundation-Implementation-Plan.md`
- **Implementation Location:** `app/Domains/Timeline/`

## 8. Checklist
- **Domain:** Operations (Universal)
- **Status:** Locked
- **Current Version:** v2.1E
- **Dependencies:** Media, Timeline
- **Dependents:** PM, Housekeeping, Work Orders, Asset Commissioning
- **Blueprint Location:** `docs/architecture/Checklist-Foundation-Implementation-Plan.md`
- **Implementation Location:** `app/Domains/Checklist/`

## 9. Logbook
- **Domain:** Operations
- **Status:** Locked
- **Current Version:** v2.1F
- **Dependencies:** Location, Department, Media, Timeline
- **Dependents:** Incident, Work Orders
- **Blueprint Location:** `docs/architecture/Logbook-Foundation-Implementation-Plan.md`
- **Implementation Location:** `app/Domains/Operations/Logbook/`

## 10. Incident
- **Domain:** Operations
- **Status:** Locked
- **Current Version:** v2.1G
- **Dependencies:** Location, Checklist, Timeline, Media, Logbook
- **Dependents:** Work Orders, Asset, Legal Case Management
- **Blueprint Location:** `docs/architecture/Incident-Foundation-Implementation-Plan.md`
- **Implementation Location:** `app/Domains/Operations/Incident/`

## 11. Asset
- **Domain:** Operations
- **Status:** Locked
- **Current Version:** v2.2A (Revision Lock)
- **Dependencies:** Location, Media, Timeline, Checklist, Incident
- **Dependents:** Preventive Maintenance, Work Orders, Inventory
- **Blueprint Location:** `docs/architecture/Asset-Management-Foundation-v2.2A-Revision-Lock.md`
- **Implementation Location:** `app/Domains/Operations/Asset/`

## 12. Procurement / Purchasing
- **Domain:** Operations
- **Status:** Quarantined / Deprecated (Legacy module removed from active testing)
- **Current Version:** N/A (Future Planned Module)
- **Dependencies:** Inventory
- **Dependents:** None currently
- **Blueprint Location:** Pending
- **Implementation Location:** `app/Domains/Operations/Purchasing/` (Quarantined)

## 13. Inventory
- **Domain:** Operations
- **Status:** Locked (v2.4)
- **Current Version:** v2.4
- **Dependencies:** Asset, Location
- **Dependents:** ContractorPTW, Procurement
- **Blueprint Location:** `docs/02-operations/foundation/Inventory/Inventory-Foundation-Implementation-Plan-v1.1.md`
- **Implementation Location:** `Modules/Operations/Inventory/`

## 14. ContractorPTW
- **Domain:** Operations
- **Status:** Locked (v2.5)
- **Current Version:** v2.5
- **Dependencies:** Asset, Location, Property
- **Dependents:** Engineering Workspace
- **Blueprint Location:** `docs/02-operations/foundation/ContractorPTW/Contractor-PTW-Foundation-Implementation-Plan-v1.1.md`
- **Implementation Location:** `Modules/Operations/ContractorPTW/`
