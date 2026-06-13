# 06. IVORQ Layout System

## Global Shell

```
┌─────────────────────────────────────────────────────┐
│  Top Navigation (56px, fixed)                       │
├─────────────────────────────────────────────────────┤
│                                                     │
│  Workspace Container (full remaining viewport)      │
│                                                     │
│  ┌─────────────┐ ┌─────────────────────────────┐   │
│  │ Quick Filter │ │ Main Workspace              │   │
│  │ Panel        │ │                             │   │
│  │ (260px)      │ │ ┌─────────────────────────┐ │   │
│  │              │ │ │ Operational Snapshot     │ │   │
│  │              │ │ ├─────────────────────────┤ │   │
│  │              │ │ │ Attention Area          │ │   │
│  │              │ │ ├─────────────────────────┤ │   │
│  │              │ │ │ Work Board / Queue      │ │   │
│  │              │ │ ├─────────────────────────┤ │   │
│  │              │ │ │ Action Area             │ │   │
│  │              │ │ └─────────────────────────┘ │   │
│  └─────────────┘ └─────────────────────────────┘   │
│                                                     │
└─────────────────────────────────────────────────────┘
```

## Top Navigation

- Fixed at top of viewport, `56px` height
- Background: `--navy-900`
- Contains: Brand mark, module links, property selector, user context

## Workspace Container

Sits below the top navigation. Fills `calc(100vh - 56px)`. Scrollable vertically.

## Workspace Header + Module Tabs

- **Workspace Header**: Module name + breadcrumbs. Sits at the top of the workspace container.
- **Module Tabs**: Horizontal tabs for switching operational contexts within a module.

## Split Layout (Operational Modules)

All operational modules use a two-column split layout:

- **Left Column: Quick Filter Panel** — 260px fixed width, sticky positioning, white background, vertical stack of filter controls
- **Right Column: Main Workspace** — Fluid width (`calc(100% - 260px - 24px)`), contains the operational workspace

## Home Layout (Exception)

Home does NOT use the split layout. Home is a full-width command center using a responsive grid of operational cards. No Quick Filter Panel.

## Main Workspace Internal Flow

Every operational workspace flows vertically through these zones:

1. **Operational Snapshot** — Horizontal row of KPI cards showing current state
2. **Attention Area** — Critical items needing immediate action (highlighted container)
3. **Work Board** — Primary operational content: kanban boards, assignment queues, room boards
4. **Action Area** — Secondary tables or supplementary data (only when needed)
