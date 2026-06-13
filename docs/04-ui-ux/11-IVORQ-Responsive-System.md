# 11. IVORQ Responsive System

## Primary Target

IVORQ is a **Desktop-First** operational platform. Target resolution: `1366×768` and above.

Hotel front desk agents, engineering supervisors, and operations managers work on desktop monitors. The UX is optimized for this environment.

## Breakpoints

| Breakpoint | Width | Behavior |
|-----------|-------|----------|
| Desktop (Primary) | ≥ 1280px | Full split layout, all columns visible |
| Laptop | 1024px – 1279px | Slightly compressed filter panel, smaller card gaps |
| Tablet | 768px – 1023px | Quick Filter Panel collapses to a toggleable overlay |
| Mobile | < 768px | Only specific mobile-optimized views (HK attendant, Eng technician) |

## Responsive Rules

1. **Quick Filter Panel**: Fixed width on desktop. On tablet, it becomes a collapsible panel triggered by a filter icon.
2. **Work Boards**: Kanban columns scroll horizontally on smaller screens. Minimum column width maintained at 260px.
3. **Operational Snapshot**: Cards wrap to 2 rows on tablet, stack vertically on mobile.
4. **Top Navigation**: On tablet, module labels hide and only icons remain. On mobile, collapses to a hamburger menu (but mobile is not a primary target).

## Mobile-Specific Views

Only the following modules are designed for mobile use:
- **Housekeeping Attendant View**: Simple room list with status updates
- **Engineering Technician View**: Assigned work orders with completion actions

All other modules are desktop-only by design.
