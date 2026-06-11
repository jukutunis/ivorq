# Preventive Maintenance Foundation Implementation Plan

## 1. Architecture Review
The Preventive Maintenance (PM) Foundation represents the proactive operational tier of IVORQ. It relies heavily on the `Asset Management Foundation` to ensure that physical equipment (HVAC, Kitchen Equipment, Vehicles) receives timely, standard-compliant servicing. 
The core entities will include:
- **`MaintenancePlan`**: The master schedule template defining *what* needs to be done and *how often* (Daily, Weekly, Meter Based).
- **`MaintenanceChecklist`**: The versioned set of tasks (Pass/Fail criteria, remarks, required photos) attached to a plan.
- **`MaintenanceExecution`**: The actual instance of a maintenance job (Scheduled, In Progress, Completed, Cancelled, Overdue).
- **`MaintenanceTask`**: Individual line items derived from the Checklist that the engineer completes during an execution.
- **`MaintenanceHistory`**: An immutable audit log recording the final state, completion times, and user signatures of finished maintenance.

## 2. Scheduling & Plan Structure
Maintenance Plans will utilize a polymorphic or robust Enum-based interval engine:
- **Time-Based:** Daily, Weekly, Monthly, Quarterly, Semi-Annual, Annual.
- **Meter-Based:** Triggered by asset readings (e.g., generator run-hours > 500, vehicle mileage > 10,000).

Every plan strongly binds to an `Asset`. To scale rapidly, a plan can also bind to an `AssetCategory` (e.g., applying an Annual Chiller Service plan to *all* assets categorized as Chillers).

## 3. Checklist Design
The `MaintenanceChecklist` acts as the SOP (Standard Operating Procedure) for the engineer. It will support:
- **Checklist Items:** Specific actions (e.g., "Check oil level", "Replace air filter").
- **Pass/Fail Outcomes:** Binary validation to enforce compliance.
- **Remarks:** Text fields for engineer observations.
- **Photo Attachments:** Mandatory or optional image uploads (via Spatie Media Library or direct S3 URLs) to prove task completion.

## 4. Security Design
Role-based access will isolate PM workflows:
- `maintenance.view`: Engineering staff and managers can view schedules and history.
- `maintenance.create`: Chief Engineers / Operations Managers can create and modify `MaintenancePlan`s and `MaintenanceChecklist`s.
- `maintenance.execute`: Technicians can transition executions to `In Progress` and upload photos.
- `maintenance.complete`: Technicians (or supervisors, depending on workflow) can transition executions to `Completed`.

## 5. Audit & Compliance
- **BR-004 & BR-005**: Once a `MaintenanceExecution` reaches `Completed` or `Cancelled`, it is strictly immutable. The entire execution record, including the specific `MaintenanceChecklist` version used, is archived into `MaintenanceHistory`.
- **BR-006**: Checklists must be versioned. If a Chief Engineer updates an SOP (adds a new checklist item), existing historical executions must retain the exact version of the checklist the technician originally completed.
- **BR-007**: Overdue tasks must be flagged automatically by the system, leaving a persistent audit trail of delayed maintenance.

## 6. Performance & Scheduling Strategy
**Volume Estimation:** 100 properties * 100,000 assets running monthly/weekly PMs over 10 years will generate hundreds of millions of `MaintenanceTask` rows.
- **Scheduling Strategy:** Do *not* auto-generate 10 years of `MaintenanceExecution` rows in advance. Instead, utilize a rolling window. A nightly background job (`maintenance:generate-schedules`) will evaluate all active `MaintenancePlan`s and generate `MaintenanceExecution` rows for the upcoming 14-30 days only.
- **Background Jobs:** Overdue tracking must be handled by a nightly scheduled command (`maintenance:mark-overdue`) that scans `Scheduled` or `In Progress` executions past their deadline.
- **Indexes:** BTREE indexes must cover `(property_id, asset_id, status)` and `(property_id, scheduled_date)` to ensure fast dashboard rendering for engineering teams.

## 7. Risk Matrix
| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Missed Maintenance** | Critical | Mitigate via daily automated email/push notifications summarizing `Overdue` tasks directly to the Chief Engineer. |
| **Duplicate Schedules** | High | The generation command must use idempotency keys or strict queries (e.g., "does an execution for this plan already exist for this week?") to prevent duplicate task generation. |
| **Overdue Tasks** | Medium | Overdue executions should not block the generation of *next* cycle's executions unless explicitly configured (e.g., "Wait for completion"). |
| **Asset Dependency** | Critical | If an `Asset` is marked `Disposed` or `Retired`, all future `MaintenancePlan`s attached to it must be automatically paused or archived to prevent phantom dispatching. |
| **History Integrity** | High | Prevent users from manually modifying `Completed` records. Force the use of DB-level Eloquent `booted()` checks returning `false` on updates for completed statuses. |

## 8. Testing Plan
- `test_pm_property_isolation`
- `test_pm_linked_to_asset_validation`
- `test_background_job_auto_generates_executions`
- `test_completed_maintenance_is_immutable`
- `test_checklist_modification_creates_new_version`
- `test_overdue_job_marks_past_due_executions`

## 9. Open Questions
1. **Meter-Based Triggers:** Time-based scheduling is handled natively via cron. However, how will meter-based PMs be triggered? Will we introduce a `MaintenanceMeterReading` API where IoT sensors or technicians log hours/mileage, which in turn triggers the PM generation?
2. **Work Order Escalation:** If a technician marks a checklist item as "Fail", should the system automatically spin up a reactive "Work Order" to fix the broken component, or is that deferred to a future Sprint?
3. **Checklist Versioning DB Strategy:** Should we use standard row-duplication (cloning the checklist and incrementing a `version` column) or a JSON snapshot strategy saved directly onto the `MaintenanceExecution` at the time of scheduling?
