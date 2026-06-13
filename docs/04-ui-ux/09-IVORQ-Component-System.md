# 09. IVORQ Component System

## Primary Components (Operations-First)

### Operational Snapshot (`<OperationalSnapshot>`)
A horizontal flex row of KPI cards. Each card:
- Semantic top border (3px) indicating health
- Large bold number (`24px, 700`)
- Uppercase label (`12px, 500`)
- White card background with subtle border

### Attention Area (`<AttentionArea>`)
A container with danger/warning-tinted background (`--danger-bg` or `--warning-bg`).
- Contains a header with title + count badge
- Each item is an `<AttentionCard>`: white card inside the colored area with a description and an inline action button

### Work Board (`<WorkBoard>`)
A multi-column kanban layout.
- Each column has a header with title and count badge
- Column body has a subtle gray background (`--surface-page`)
- Contains `<WorkCard>` items

### Work Card (`<WorkCard>`)
The fundamental unit of operational work.
- White background, subtle shadow
- Left colored border (4px) indicating status
- Structure: metadata row → main title → optional details → action row
- Actions are inline buttons or quick icons

### Queue List (`<QueueList>`)
A vertical list of actionable items (alternative to kanban when items flow linearly).
- White card container with header
- Each `<QueueItem>` has: info column (title + metadata) + status + action button
- Hover reveals subtle primary background

### Quick Filter Panel (`<QuickFilterPanel>`)
- 260px fixed width, white background, sticky positioning
- Contains stacked filter groups: label + input/select
- Optional "Reset" link in header
- This is a workspace utility, NOT navigation

---

## Secondary Components

### Status Badge (`<StatusBadge>`)
Pill-shaped inline indicator. Variants:
- `vip` (Gold)
- `critical` (Red)
- `warning` (Amber)
- `ready` (Green)
- `inspection` (Blue)
- `pending` (Purple)
- `neutral` (Slate)
- `11px`, `600` weight, uppercase
- Low-opacity semantic background with high-contrast text and subtle border.

### Action Button (`<Button>`)
- **Primary**: `--primary-500` background, white text — for main actions
- **Secondary**: White background, border — for alternative actions
- Sizing: compact (`padding: 6px 12px`) for inline use within cards

### Data Grid (`<DataGrid>`)
Secondary component. Only used when tabular data display is genuinely required.
- Never the default landing view
- Clean headers with uppercase labels
- Row hover highlights

### Module Tabs (`<ModuleTabs>`)
Horizontal tab bar for sub-context switching.
- Active tab: primary color text + bottom border
- Inactive: secondary text color

### Workspace Header (`<WorkspaceHeader>`)
Module title + breadcrumbs. Simple, typographic.
