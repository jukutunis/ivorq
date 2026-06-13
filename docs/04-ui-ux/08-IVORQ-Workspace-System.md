# 08. IVORQ Workspace System

## Philosophy

This document defines how each IVORQ module workspace is structured. Every workspace follows the **Context → Work → Action** pattern. Tables are always secondary. Operations are always primary.

## Workspace Zones (Universal)

### Zone 1: Operational Snapshot
A horizontal row of metric cards showing real-time operational state. Maximum 4-5 cards. Each card has:
- Large numeric value (KPI)
- Label
- Semantic top-border indicating health

### Zone 2: Attention Area
A highlighted container showing items that need immediate human action. Uses a danger/warning-tinted background to create visual urgency. Contains actionable cards with inline resolution buttons.

### Zone 3: Work Board
The primary operational content. Can take the form of:
- **Kanban Board** — Columns representing workflow stages (Housekeeping, Engineering, Procurement)
- **Queue List** — Ordered list of actionable items (Front Desk arrivals, HRIS leave requests)
- **Assignment Board** — Cards showing workload distribution (Engineering technicians, HK attendants)

### Zone 4: Action Area
Supplementary content. May include:
- Secondary data tables (for drill-down)
- Summary statistics
- Batch action toolbars

---

## Per-Module Workspace Definitions

### Home — Operational Command Center
- **No Quick Filter Panel**
- Full-width card grid
- Zones: MOD + Shift Status | VIP Arrivals + Critical Attention | Staff On Duty | Operational Pulse | Log Book | Quick Launchers

### Front Desk
- **Quick Filter**: Search, Arrival Date, Departure Date, Res Status, Room Type, OTA Source
- Snapshot: Pending Arrivals, VIPs Due, Pending Departures
- Attention: Pre-arrival issues (no room, no guarantee, special requests)
- Work: Check-In Queue, Check-Out Queue (queue-list format)
- Action: New Reservation, Walk-In buttons

### Housekeeping
- **Quick Filter**: Floor/Zone, Room Status, Attendant, Priority
- Snapshot: Dirty, Pending Inspection, Rush Rooms
- Attention: VIP rooms needing priority cleaning
- Work: Room Task Board (kanban — Dirty/Unassigned → In Progress → Pending Inspection → Clean)
- Action: Auto-Assign, Print Assignment Sheet

### Engineering
- **Quick Filter**: Priority, Location, Asset, Technician, WO Status
- Snapshot: SLA Breaches, Open WOs, PM Due
- Attention: Overdue SLA items
- Work: Work Order Pipeline (queue with inline assign/escalate)
- Action: Create WO, View PM Schedule

### Inventory
- **Quick Filter**: Store, Category, Stock Status, Supplier, Movement Type
- Snapshot: Below PAR count, Pending Transfers, Pending Receiving
- Attention: Critical stock shortages
- Work: Low Stock Action Queue, Transfer Queue
- Action: Stock Transfer, Generate PO for Shortages

### Procurement
- **Quick Filter**: Department, Vendor, PR Status, Approval Status, Amount Range
- Snapshot: Awaiting Approval, Open PRs, Active POs
- Attention: Overdue approvals
- Work: Approval Board (kanban — Needs Approval → Approved/Sourcing → PO Issued → Received)
- Action: Create PR

### HRIS
- **Quick Filter**: Department, Position, Shift, Employment Status
- Snapshot: Clocked In, Late/No-Show, Open Leave Requests
- Attention: Leave approvals, coverage gaps
- Work: Attendance Dashboard, Shift Coverage Overview
- Action: Manage Roster

### Finance
- **Quick Filter**: Business Date, Cost Center, Department, Period, Status
- Snapshot: Revenue Today, AP Pending, AR Outstanding
- Attention: Overdue AP items, unreconciled transactions
- Work: AP Workflow Queue, AR Follow-up Queue
- Action: Process Batch, Post Journal

### Reports
- **Quick Filter**: Category, Date Range, Department
- Work: Report catalog with Generate/Schedule actions
- Action: Export Center

### AI Assistant
- **Quick Filter**: Department Scope
- Work: Conversational interface, suggested actions
- Action: Pre-built queries (VIP arrivals, occupancy forecast, handover draft)
