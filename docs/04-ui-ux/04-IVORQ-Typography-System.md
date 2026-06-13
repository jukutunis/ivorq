# 04. IVORQ Typography System

## Primary Typeface

**Inter** — loaded from Google Fonts.

```
font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
```

Inter is chosen for exceptional screen legibility at small sizes, excellent tabular number support, and a clean, modern character that avoids both the coldness of system fonts and the personality of display fonts.

## Type Scale

| Role | Size | Weight | Line Height | Usage |
|------|------|--------|-------------|-------|
| Workspace Title | 20px | 600 | 1.3 | Module headers ("Front Desk Operations") |
| Section Title | 16px | 600 | 1.4 | Board column headers, queue titles |
| Card Title | 14px | 600 | 1.4 | Work cards, attention items, queue items |
| Body | 13px | 400 | 1.5 | Primary content, descriptions, metadata |
| Small | 12px | 500 | 1.4 | Filter labels, timestamps, secondary metadata |
| Micro | 11px | 600 | 1.3 | Badge text, status labels, counters |
| KPI Value | 24px | 700 | 1.2 | Operational snapshot numbers |
| KPI Label | 12px | 500 | 1.4 | Snapshot descriptions, uppercase |

## Numeric Display

All numeric data uses `font-variant-numeric: tabular-nums` to ensure vertical alignment in tables, snapshots, and counters.

## Text Color Hierarchy

1. **Primary** (`--text-primary`): Card titles, queue item names, KPI values
2. **Secondary** (`--text-secondary`): Metadata, descriptions, timestamps
3. **Muted** (`--text-muted`): Placeholders, disabled states
