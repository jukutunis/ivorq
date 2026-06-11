# Asset Management Foundation Implementation Plan

## 1. Architecture Review
The Asset Management Foundation represents the operational backbone for tracking all physical property, from HVAC units to IT equipment. This module (`Modules/Operations/AssetManagement` or `Modules/AssetManagement`) will serve as the master registry feeding downstream systems like Work Orders, Preventive Maintenance, and Financial Capex/Depreciation ledgers.
The core entities will include:
- **`Asset`**: The master record (ULID, property_id, code, serial_number, purchase_date, warranty_expiry).
- **`AssetCategory`**: Hierarchical classification (e.g., HVAC > Chiller).
- **`AssetLocation`**: Spatial tracking (Building > Floor > Room).
- **`AssetAssignment`**: Tracks if an asset is assigned to a specific user, department, or location.
- **`AssetDocument`**: Versioned manuals, warranties, and compliance certificates.

## 2. Asset Lifecycle (AssetStatusEnum)
Assets will strictly transition through the following states:
- **Planned**: Budgeted or ordered, not yet received.
- **Active**: In active operational use.
- **UnderMaintenance**: Temporarily offline for repair.
- **Disposed**: Sold or scrapped (Immutable state).
- **Retired**: End of life, kept for records (Immutable state).
- **Lost**: Unaccounted for during physical audits.

## 3. Location Design (AssetLocation)
Locations will be structured hierarchically to support precise engineering dispatches:
- **Property** -> **Building** -> **Floor** -> **Area** -> **Room**
A self-referencing `parent_id` architecture allows infinite nesting, but will strictly enforce the predefined levels for standardized reporting.

## 4. Asset Classification (AssetCategory)
Classification ensures standard operating procedures (SOPs) can be mapped to asset types. Core categories:
- HVAC
- Electrical
- Mechanical
- Furniture
- Kitchen Equipment
- IT Equipment
- Vehicle
- Pool Equipment
- Safety Equipment

## 5. Finance Integration Strategy
While this sprint establishes the operational Asset Registry, future integration will seamlessly map to Finance:
- **Capex**: Purchasing Module will auto-create `Planned` assets upon Capex PO approval.
- **Depreciation**: Asset values will link to the `FixedAsset` financial subledger to auto-generate monthly depreciation journal entries.
- **General Ledger**: Each `AssetCategory` will be mapped to a specific GL Asset Account and Accumulated Depreciation Account.

## 6. Business Rules
- **BR-001**: Asset module is strictly `property_id` isolated.
- **BR-002**: `asset_code` must be unique per `property_id`.
- **BR-003**: Every lifecycle status change must log an entry in `AssetLifecycleHistory`.
- **BR-004 / BR-005**: Once an asset is marked `Disposed` or `Retired`, it is locked. No further modifications to the master record are permitted.
- **BR-006**: Core asset modifications trigger standard audit logging.
- **BR-007**: `AssetDocument` must support versioning to retain historical compliance files.

## 7. Security Design
- `asset.view`: Basic visibility for staff.
- `asset.create`: Granted to Engineering/IT managers.
- `asset.edit`: Granted to Engineering/IT managers.
- `asset.retire`: Granted to Director of Engineering / Finance.
- `asset.dispose`: Strictly requires Finance/Owner approval to ensure fixed asset subledgers are cleared.

## 8. Performance Review
**Volume Estimation:** 100 properties * 100,000 assets = 10,000,000 records.
- **Indexes:** Composite unique index on `(property_id, asset_code)`. BTREE indexes on `status`, `asset_category_id`, and `asset_location_id`.
- **Caching:** Cache the `AssetCategory` and `AssetLocation` trees per property, as these change infrequently but are queried heavily for dropdowns.
- **Search Strategy:** Implement a robust text-search driver (e.g., Meilisearch, Algolia, or Postgres Full Text Search) specifically indexing `asset_code`, `serial_number`, and `name` to ensure sub-second lookups for maintenance staff via mobile.

## 9. Risk Matrix
| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Duplicate Assets** | High | Prevented via strict DB unique constraints on `(property_id, asset_code)`. |
| **Lost Assets** | Medium | Implement an annual "Physical Audit" workflow moving unaccounted items to `Lost` status. |
| **Location Inconsistency** | High | If a room changes use, moving assets must cascade correctly. Enforce that Assets belong to Locations, not directly to Room name strings. |
| **Maintenance Dependency** | Critical | Work Orders will fail if Assets are deleted. Assets must utilize soft-deletes or strict foreign key constraints preventing deletion if Work Orders exist. |
| **Finance Dependency** | Critical | Disposing an asset operationally must trigger a workflow or alert in the Finance module to write off the asset value in the GL. |

## 10. Testing Plan
- `test_asset_property_isolation`
- `test_asset_code_is_unique_per_property`
- `test_disposed_and_retired_assets_are_immutable`
- `test_lifecycle_changes_create_audit_history`
- `test_asset_location_hierarchy_validation`
- `test_asset_document_versioning`

## 11. Open Questions
1. **Module Placement:** Should Asset Management live under `Modules/Operations/AssetManagement` or sit at the root level as `Modules/AssetManagement` given its massive cross-departmental impact (Engineering, IT, Housekeeping, Finance)?
2. **Barcode / QR Generation:** Will the system be responsible for auto-generating printable QR codes for these assets upon creation to facilitate mobile scanning by maintenance staff?
3. **Depreciation Start:** Should the `Asset` record track the operational `in_service_date` independently of the `purchase_date` so Finance knows exactly when to begin depreciation schedules?
