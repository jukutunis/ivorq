# SPRINT 14.7.1 — OPERATIONS CALENDAR REVISION

## Architecture Revisions
The original architecture proposed placing the calendar within `SalesAndEventManagement`. Following the CTO's review, the scope has expanded to include broader property operations (Staff, Equipment, Vehicles, Inspections). Consequently, the calendar is no longer just a sales visualization tool but a holistic operational command center.
- **Shift to Dedicated Module:** The calendar will be refactored into a standalone module (`Modules/OperationsCalendar`) serving as a read-only Backend for Frontend (BFF).
- **Drag & Drop Governance:** Strict enforcement of CQRS principles. The calendar frontend will never update the database directly. All mutable interactions (e.g., Drag & Drop to reschedule) must dispatch explicit domain commands to the owning modules (e.g., `RescheduleEventFunctionCommand` to `SalesAndEventManagement`, `UpdateMaintenanceBlockCommand` to `Engineering`).

## Ownership Recommendation
**Recommendation: Dedicated Read-Only Aggregation Domain (`Modules/OperationsCalendar`)**
*Reasoning:* By abstracting the calendar into a dedicated aggregation module, we prevent domain leakage. The calendar module will not own any database tables related to events or maintenance. Instead, it will query existing domain modules, normalize the diverse data structures into standardized Data Transfer Objects (DTOs), and serve them to the frontend. This acts as a robust anti-corruption layer.

## Resource Strategy
To support true operational readiness, the calendar architecture must extend beyond physical venue spaces to include temporal resource allocation:
- **Staff:** Visualizing setup crews, engineering coverage, and banquet staffing against function demands.
- **Equipment:** Tracking physical inventory conflicts (e.g., staging, dance floors, specialized furniture).
- **Vehicle:** Logistics, shuttle schedules, and loading dock utilization.
- **AV Asset:** High-value technical assets (Projectors, PA systems).
*Strategy:* The `OperationsCalendar` module will feature "Resource Timeline" layers. It will query the respective resource management domains to ensure that required assets for a given time block do not exceed the property's available inventory.

## Calendar Item Strategy
To handle the diverse operational data sources elegantly, the BFF will map all source data into a standardized abstraction driven by a `CalendarItemType` definition. Supported types include:
- **Event Function:** Standard BEO-driven client function.
- **Maintenance:** Engineering repair blocks (Out of Order).
- **Venue Closure:** Strategic or operational closures (Out of Service).
- **Internal Event:** Staff meetings, property-level training.
- **Inspection:** Health & Safety audits, GM walkthroughs.
- **VIP Visit:** High-profile arrivals that require operational awareness.

## Operations Board Strategy
**Operations Board View**
- **Purpose:** A dedicated, large-screen mode designed specifically for back-of-house (BOH) areas such as Kitchens, Engineering offices, and Banquet service hallways.
- **Execution:** Auto-refreshing, high-contrast, read-only display focused on immediate execution (Today / Next 48 Hours).
- **Features:** Highlights real-time setup/breakdown turnarounds, active functions, and critical operational alerts without requiring user interaction. It functions purely as a broadcast projection of the calendar state.

## Implementation Recommendation
1. **Scaffold Module:** Create `Modules/OperationsCalendar` as a strictly read-only domain.
2. **Define Abstractions:** Create the `CalendarItemDTO` and `CalendarItemType` enumerations to standardize all incoming data.
3. **Build Aggregators:** Implement data fetchers/adapters that read from `SalesAndEventManagement` (Functions), `Engineering` (Maintenance), and Resource modules, transforming the results into standard DTOs.
4. **Develop Views:** Build the Operations Board frontend view tailored for passive, large-screen consumption, alongside standard Timeline/Grid views.
5. **Implement Command Dispatcher:** Configure the frontend calendar component to route drag-and-drop actions to the correct source domains via a standardized command bus.

## Final Calendar Architecture
- **Layer:** `Modules/OperationsCalendar` (Read-Only Aggregation / BFF).
- **Data Source:** Cross-domain queries to `EventFunction`, `VenueMaintenanceBlock`, `ResourceAllocation`, etc.
- **Output:** Standardized JSON payload of `CalendarItemDTO`s representing the unified operational timeline.
- **Mutation Path:** Drag & Drop -> Frontend Dispatcher -> Laravel Command Bus -> Respective Owning Domain -> Event Sourced / DB Update -> Calendar Auto-Refreshes.
- **Primary Views:** Timeline (Function Diary), Operations Board (BOH Screen), Day/Week/Month Grid.
