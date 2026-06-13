# 05. IVORQ Layout System

## Core Layout Architecture
The IVORQ platform utilizes a single, unified layout structure that prioritizes maximum workspace width and vertical scrolling, discarding legacy split-pane or sidebar-heavy designs.

### 1. Global Wrapper (`<AppShell>`)
- **Top Bar**: Fixed at the top, `56px` height. Contains logo, main module navigation, and user/property context.
- **Main Content Area**: Takes up the remaining viewport height. Uses a light background (`var(--surface-page)`).

### 2. The Workspace Container (`.fd-workspace`)
Every operational module operates within a standardized workspace container that spans the full width of the screen.

**Strict Rule: NO Permanent Left Sidebar**
- We do not use persistent left-side filter panels.
- All filtering and searching must be handled via the **Compact Filter Bar** situated at the top of the workspace.
- This ensures data tables and operational boards have maximum horizontal space.

### 3. Home Dashboard Layout (Command Center)
The Home screen is a unique layout designed as an "Operations Command Center."
- **Grid System**: Uses a responsive card-based grid (e.g., CSS Grid) to display distinct operational metrics.
- **Rows**:
  1. Executive Snapshot (High-level property metrics).
  2. Shift & Handover Info.
  3. Immediate Attention (KPI cards highlighting critical issues).
  4. Department Pulse & Log Book.
  5. Module Launchers.

### 4. Standard Operational Module Layout
For all modules other than Home (e.g., Front Desk, Housekeeping, etc.), the layout strictly follows this vertical flow:
1. **Workspace Header**: Breadcrumbs and Page Title.
2. **Module Tabs**: Horizontal tabs to switch sub-views (e.g., Arrivals, Departures).
3. **Operational Snapshot**: 4-5 key metrics relevant to the current tab.
4. **Compact Filter Bar**: Horizontal bar containing search inputs, dropdown filters, and primary action buttons (e.g., "New Reservation").
5. **Main Content (Data Grid / Board)**: The primary data visualization taking up the rest of the vertical space.
