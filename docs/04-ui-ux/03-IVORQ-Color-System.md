# 03. IVORQ Color System

## Brand Colors

| Token | Hex | Usage |
|-------|-----|-------|
| `--navy-900` | `#1A2356` | Top navigation background, brand anchor |
| `--navy-800` | `#212C6A` | Active nav item background, hover states |
| `--primary-500` | `#4F6BED` | Primary actions, active tab indicators, links |
| `--primary-100` | `#DDE5FF` | Subtle primary highlights |
| `--primary-50` | `#EEF2FF` | Row hover, soft interactive backgrounds |

## Surface Colors

| Token | Hex | Usage |
|-------|-----|-------|
| `--surface-page` | `#F4F6F8` | Main page background, board column backgrounds |
| `--surface-card` | `#FFFFFF` | Cards, panels, queue items, filter panels |
| `--surface-elevated` | `#FFFFFF` | Modals, drawers, floating elements |

## Text Colors

| Token | Hex | Usage |
|-------|-----|-------|
| `--text-primary` | `#1F2937` | Primary content, headings, card titles |
| `--text-secondary` | `#6B7685` | Metadata, labels, timestamps |
| `--text-muted` | `#9CA3AF` | Placeholders, disabled text |

## Border Colors

| Token | Hex | Usage |
|-------|-----|-------|
| `--border-default` | `#E8ECF0` | Card borders, dividers, input borders |
| `--border-subtle` | `#F0F2F5` | Internal separators within cards |

## Semantic Colors (Operational Status)

| Status | Name | Foreground | Background | Usage |
|--------|------|-----------|------------|-------|
| VIP | Gold | `#D97706` | `#FEF3C7` | VIP guests, high-value items |
| Critical | Red | `#DC2626` | `#FEF2F2` | OOO, SLA Breach, Unresolved Complaints |
| Warning | Amber | `#F59E0B` | `#FFFBEB` | Dirty, Due In, SLA Warning, Late |
| Ready | Green | `#059669` | `#ECFDF5` | Clean, Checked In, Approved, On Track |
| Inspection | Blue | `#2563EB` | `#EFF6FF` | Pending Inspection, Assigned, Informational |
| Pending | Purple | `#7C3AED` | `#F5F3FF` | Pending Approval, Sourcing, Awaiting Action |
| Neutral | Slate | `#64748B` | `#F8FAFC` | Departed, Closed, Standard Status |

## Design Rule

Color is used for **operational meaning**, not decoration. Every colored element must communicate a specific status, priority, or action state.
