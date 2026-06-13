# IVORQ React Mapping Guide

This guide maps the new HTML prototype structure to future React components.

## 1. Global Layout
- `<AppShell>`: The master wrapper containing the TopBar and the main content router outlet.
- `<TopBar>`: The `56px` fixed navy header containing `<Brand>`, `<NavigationMenu>`, and `<UserContext>`.

## 2. Shared Workspace Components
- `<WorkspaceHeader>`: Contains the page title and breadcrumbs.
- `<ModuleTabs>`: Renders the horizontal sub-navigation tabs (e.g., Arrivals, Departures).
- `<OperationalSnapshot>`: Renders the row of `<KpiCard>` elements at the top of a module.
- `<CompactFilterBar>`: Renders the horizontal search and action row. Props should include `onSearch`, `filters` array, and `primaryAction`.

## 3. Data Display
- `<DataGrid>`: A reusable table component supporting sortable columns, pagination, and row actions.
- `<StatusBadge>`: Reusable badge component accepting a `variant` prop (`success`, `warning`, `danger`, `info`, `neutral`).

## 4. Module Specific
- `<RoomBoard>`: Used in Housekeeping for the floor-by-floor grid view of rooms.
- `<KanbanBoard>`: Used in Engineering for Work Orders (Open, Assigned, In Progress).
- `<CommandCenterGrid>`: Used exclusively for the Home Dashboard to render the complex masonry/grid layout of operational widgets.
