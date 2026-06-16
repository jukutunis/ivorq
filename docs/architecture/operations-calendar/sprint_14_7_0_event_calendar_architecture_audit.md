# SPRINT 14.7.0 — EVENT CALENDAR ARCHITECTURE AUDIT

## Executive Summary
The Event Calendar architecture audit outlines the strategy for visualizing and managing temporal data related to Sales & Event Management within IVORQ. The calendar must act as an operational command center and presentation layer that aggregates data from established foundations (Events, Functions, Venues, Maintenance) rather than serving as a disconnected data silo.

## Recommended Architecture
- **Pattern:** Aggregation View Layer / Backend for Frontend (BFF).
- **Backend:** A dedicated `EventCalendarService` in the `SalesAndEventManagement` module that aggregates data from `FunctionSpaceBooking`, `EventFunction`, and `VenueMaintenanceBlock` utilizing the existing `FunctionSpaceAvailabilityEngine`.
- **Frontend (React/Inertia):** A dedicated Calendar SPA component with multiple view modes, prioritizing a high-performance timeline/Gantt visualization.

## Calendar Ownership
**Recommendation: B. View Layer consuming existing domains.**
*Reasoning:* The Calendar should **not** own any core state. Establishing a separate module that owns "Calendar Events" risks data desynchronization and violates the Single Source of Truth principle. The calendar must act as an Aggregator/View Layer that fetches and visualizes data owned by the `Event`, `Function`, and `Venue` domains. User actions performed on the calendar (e.g., drag-and-drop to reschedule) should dispatch standard domain commands (e.g., `RescheduleFunctionSpaceBooking`).

## Calendar Views
- **Day View:** Highly granular, hour-by-hour or minute-by-minute view. Critical for Banquets, Setup, and Housekeeping to manage daily operational execution.
- **Week View:** Ideal for Sales Managers to assess pacing, availability, and manage their own bookings over a 7-day window.
- **Month View:** High-level strategic overview for Directors of Sales and Revenue Managers to assess occupancy and demand trends.
- **Timeline View (Function Diary):** The most critical view. A Gantt-chart style horizontal timeline mapping Venues (Y-axis) against Time (X-axis). Essential for visualizing turnarounds, setup, and breakdown times.
- **Venue View:** Filtered visualization isolated to specific venues or combinations (e.g., "Grand Ballroom A+B").
- **Property View:** The holistic view of all bookable spaces within the currently scoped property.

## Conflict Detection Strategy
Leveraging the existing `FunctionSpaceAvailabilityEngine`:
- **Venue Conflict:** Hard block. Two active, non-concurrent bookings cannot occupy the same discrete or combined venue space simultaneously.
- **Setup Conflict:** Policy-based Warning/Hard block. Setup time for Event B cannot overlap with the breakdown time of Event A.
- **Breakdown Conflict:** Visual indicator ensuring adequate time is allocated for teardown before the next operational phase.
- **Turnaround Conflict:** The buffer between Event A Breakdown and Event B Setup must meet the minimum turnaround standards defined in `VenueCapacity` or `Venue`.
- **Maintenance Conflict:** Hard block. Functions cannot be scheduled during an active `VenueMaintenanceBlock`.

## Operational Layers
Role-based access control (Spatie Permissions) dictates visible data layers:
- **Sales Layer:** Client names, revenue figures, tentative/definite status, contracting details.
- **Banquet Layer:** Setup styles, BEO status, guaranteed pax, food & beverage timing.
- **Engineering Layer:** Maintenance blocks, power drops, AV setup windows.
- **Housekeeping Layer:** Turnaround times, cleaning windows.
- **Executive Layer:** Occupancy percentages, revenue pacing, high-level space utilization.

## Color Strategy
Visual cues for immediate status recognition. Colors should map to robust CSS custom properties to support light/dark modes.
- **Tentative:** 🟡 Orange/Yellow (Requires action, not guaranteed)
- **Definite:** 🟢 Green (Contracted, guaranteed)
- **In House:** 🔵 Blue (Currently executing)
- **Completed:** ⚪ Gray (Historical, finalized)
- **Cancelled:** 🔴 Red (Released space)
- **Maintenance:** 🟤 Brown/Black (Space unavailable, engineering)
- **Out Of Service:** 🟣 Purple (Space unavailable, operational/cleaning)

## Filter Strategy
Rich, composable filtering to reduce visual noise:
- **Venue & Venue Category:** Filter by specific rooms or architectural types (e.g., Boardrooms, Ballrooms, Outdoor).
- **Event Type:** Filter by Wedding, Corporate, Internal, etc.
- **Sales Manager:** View only bookings owned by specific personnel.
- **Status:** Toggle Tentative, Definite, Cancelled, etc.
- **Department:** Filter by operational layers (e.g., show only events requiring AV or Engineering support).

## Multi Property Strategy
Aligns with IVORQ's Multi-property SaaS architecture and strict tenant isolation:
- **Single Property Calendar:** The default, strict tenant isolation based on `property_id` scoping.
- **Corporate Calendar:** Aggregated read-only view for corporate users to view pacing and availability across multiple properties.
- **Cross Property Calendar:** For cluster sales environments where a single manager books across sister properties. Requires careful cross-tenant authorization policies and explicit contextual grants.

## Oracle Opera Cloud Comparison
- **Opera Function Diary:** Highly feature-rich but often suffers from legacy UI paradigms and slower performance. Relies heavily on traditional, dense grid structures.
- **IVORQ Advantage:** Real-time reactivity via Inertia/React, modern UI/UX, and a unified ecosystem without bolt-on integrations. Faster load times for timeline views.

## Amadeus Delphi Comparison
- **Delphi Function Diary:** Built on the Salesforce ecosystem. Highly customizable and robust for sales, but can feel detached from on-the-ground operations (Housekeeping/Engineering).
- **IVORQ Advantage:** Deep operational integration. The calendar is an operational command center natively interfacing with BEOs, Maintenance, and Housekeeping without syncing back and forth to an external CRM.

## Recommended Sprint 14.7.1 Scope
1. Scaffold `EventCalendarService` (Read-only aggregation).
2. Implement core API endpoints returning formatted events, functions, and maintenance blocks.
3. Build the frontend Timeline View (Function Diary) component in React/Inertia.
4. Integrate the Color Strategy based on existing Event/Function statuses.
5. Implement basic filtering (Date Range, Venue, Status).

## Enterprise Readiness Score
**Score: 9/10 (Pre-Implementation)**
The underlying foundations (`EventFunction`, `Venue`, `FunctionSpaceAvailabilityEngine`) are robust and perfectly suited to drive a View Layer calendar. The separation of concerns is clear. The primary technical risk is frontend performance when rendering large datasets on a timeline, which must be mitigated with virtualization or lazy-loading strategies.

## Final Recommendation
Proceed with **Option B (View Layer consuming existing domains)**. 
Do not create a separate Calendar module or data structure. The Calendar must be implemented as a specialized UI and service layer within the `SalesAndEventManagement` module, acting as a read-only projection of truth with strict command-dispatching capabilities for any updates.
