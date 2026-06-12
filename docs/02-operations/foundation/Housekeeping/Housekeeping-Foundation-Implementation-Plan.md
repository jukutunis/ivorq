# Housekeeping Foundation v2.6 Implementation Plan

## 1. Domain Overview

The Housekeeping Foundation v2.6 serves as the core operational engine for maintaining the physical cleanliness, readiness, and quality standards of all property spaces. It operates independently of the PMS regarding physical room status, establishing a clear boundary where Housekeeping owns the operational state of the room, while the PMS owns the reservation and financial state.

The domain encompasses guest rooms, public areas, back-of-house (BOH), and laundry operations. It is designed with a "Mobile First" philosophy to empower room attendants and supervisors through an Offline PWA, supporting modern workflows like QR scanning, voice notes, and media attachments.

## 2. Entity Model

All entities utilize ULID primary keys, enforce multi-property isolation (`property_id`), and follow the service layer architecture.

### Core Entities

*   **`RoomStatus`** (Extends Location/Asset): Owns the physical and operational status of a space.
*   **`CleaningTask`**: Represents a specific cleaning assignment (e.g., Departure Clean, Stayover, Turndown, Deep Clean).
*   **`TaskAssignment`**: Links a `CleaningTask` to a specific Room Attendant.
*   **`CleaningHistory`**: An append-only, immutable ledger recording every change to a room's physical state or task completion.
*   **`Inspection`**: Represents a supervisor's quality check on a space or completed task, including scoring.
*   **`LostAndFoundItem`**: An independent entity tracking items found on the property, their custody chain, and resolution.
*   **`HousekeepingChecklist`**: The template and runtime instances for task-specific cleaning checklists (precursor to universal Checklist Foundation).

## 3. State Machine

### Room Operational States (Strictly Housekeeping Owned)
*   `CLEAN`: Room is cleaned but not yet inspected.
*   `DIRTY`: Room requires cleaning.
*   `INSPECTED`: Room is clean, inspected, and ready for guests.
*   `PICKUP`: Room requires a quick touch-up (e.g., after maintenance or a brief show).
*   `OUT_OF_ORDER` (OOO): Room is unrentable due to major maintenance or damage. (Deducts from inventory).
*   `OUT_OF_SERVICE` (OOS): Room is temporarily blocked for minor repairs but remains in inventory.

### Guest-Centric Modifiers (Syncs with PMS)
*   `ARRIVAL`: Guest arriving today.
*   `STAYOVER`: Guest staying through the night.
*   `DEPARTURE`: Guest checking out today.
*   `DND` (Do Not Disturb): Attendants cannot enter.
*   `TURNDOWN_REQUIRED`: Room requires evening turndown service.

### Cleaning Task States
*   `PENDING` -> `ASSIGNED` -> `IN_PROGRESS` -> `COMPLETED` -> `VERIFIED`

## 4. Dependency Matrix

| Dependency | Relationship | Description |
| :--- | :--- | :--- |
| **Location Foundation** | **Requires** | Maps rooms, zones, and public areas. |
| **Asset Foundation** | **Requires** | Tracks FF&E within rooms for deep cleaning and damage reporting. |
| **Inventory Foundation** | **Integrates** | Consumes cleaning supplies, linens, and amenities; triggers reorder points. |
| **Work Order Foundation** | **Integrates** | Auto-generates work orders for maintenance issues discovered during cleaning. |
| **PMS (External)** | **Listens** | Subscribes to check-in/check-out events; publishes `INSPECTED` status for front desk assignment. |
| **Media Foundation** | **Future** | Stores inspection photos and Lost & Found images. |

## 5. Room Status Lifecycle

1.  **Guest Checkout (PMS)** -> Event emitted -> `RoomStatus` changes to `DIRTY` & `DEPARTURE`.
2.  **Assignment Engine** -> Generates `CleaningTask` -> Assigns to Room Attendant.
3.  **Attendant Start** -> Scans QR -> State changes to `IN_PROGRESS`.
4.  **Attendant Finish** -> Completes checklist -> `RoomStatus` changes to `CLEAN`.
5.  **Supervisor Inspection** -> Performs QA -> If pass: `INSPECTED`. If fail: generates `PICKUP` task.
6.  **Front Desk Sync** -> PMS notified that room is `INSPECTED` and ready for assignment.

## 6. Lost & Found Architecture

**Independence:** `LostAndFoundItem` exists as a standalone domain aggregate. It does not strictly depend on a reservation or guest profile, as items are often found in public areas or after a guest has purged from active memory.

**Attributes:**
*   `reference_number` (Auto-generated unique ID)
*   `found_location_id` / `location_description`
*   `found_by_user_id`
*   `category_id` (Valuable, Clothing, Electronics, etc.)
*   `status` (`REPORTED`, `SECURED`, `CLAIMED`, `DISPOSED`, `SHIPPED`)
*   `media_attachments` (Photos of the item)
*   `chain_of_custody` (JSON / Immutable ledger of possession transfers)

## 7. Inspection Architecture

Inspections utilize a quantifiable scoring system to track attendant performance and maintain standards.

*   **Template:** Supervisors create `HousekeepingChecklist` templates (e.g., "5-Star Departure Clean").
*   **Scoring:** Each item on the checklist carries a weight. Total score determines Pass/Fail.
*   **Fail Workflow:** Failing an inspection automatically triggers a `PICKUP` task for the original attendant and flags the room as `DIRTY`.
*   **Immutability:** Once submitted, an inspection report is immutable. Re-inspections create new `Inspection` records linked to the same parent task.

## 8. Mobile Workflow (Room Attendant App)

Designed for rapid, low-friction interactions (IVORQ Design System: Mobile First UX).

1.  **Workspace Launcher:** Dedicated "Attendant Portal".
2.  **Task Acceptance:** Swipe-to-accept or QR scan physical room tag.
3.  **In-Room Actions:**
    *   One-tap "Start Cleaning".
    *   Voice notes for maintenance issues (auto-transcribed to Work Orders).
    *   Photo attachments for damages or Lost & Found.
    *   One-tap "DND" logging.
4.  **Completion:** Swipe-to-complete, automatically consuming standard room inventory (linens, amenities).

## 9. Offline Strategy (PWA)

*   **Local Caching:** Daily task lists and room checklists are cached via Service Workers upon initial login.
*   **Optimistic UI:** State changes (e.g., marking a room clean) reflect instantly in the local UI.
*   **Sync Queue:** Actions are queued in IndexedDB. When network connectivity is restored, the queue syncs with the Laravel API.
*   **Conflict Resolution:** Last-write-wins at the field level, but structural changes (e.g., room marked OOO by engineering while attendant is offline) surface a sync warning.

## 10. Risk Assessment

> [!WARNING]
> **PMS Sync Race Conditions:** If the PMS expects real-time sync but the attendant is offline, the front desk might see a stale status. We must implement a "Last Synced At" indicator on the front desk dashboard.

> [!CAUTION]
> **Inventory Drain:** Auto-consuming inventory upon task completion might artificially drain stock if attendants mark rooms clean without actually restocking amenities. We need a supervisor variance audit workflow.

> [!IMPORTANT]
> **Checklist Engine Overbuild:** The requirement states "integrate with future Checklist Foundation". We must avoid building a monolithic checklist engine now; keep it lightweight (JSON columns) and refactor to the generic foundation later.

## 11. Open CTO Questions

1.  **Inventory Consumption:** Should standard inventory consumption (e.g., 2 towels, 2 soaps per departure clean) be strictly automated, or require attendant confirmation/adjustment per room?
2.  **Voice Notes Transcription:** Will we rely on native mobile OS transcription (Siri/Google Assistant keyboard) for voice notes, or integrate a backend AI transcription service (e.g., Whisper API)?
3.  **DND Enforcement:** Should the system enforce an automatic supervisor alert if a room is marked `DND` for > 48 hours to comply with modern hotel safety protocols?

---

**Status:** READY FOR CTO REVIEW
