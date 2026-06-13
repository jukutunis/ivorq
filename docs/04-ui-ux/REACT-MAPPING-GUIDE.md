# IVORQ React Mapping Guide

## Purpose

This document maps every HTML prototype element to its future React + Inertia + Tailwind implementation. The prototype is the source of truth — React implementation must match it 1:1.

## Global Shell

| Prototype | React Component | Notes |
|-----------|----------------|-------|
| `<header class="topbar">` | `<AppShell>` → `<TopNavigation>` | Inertia persistent layout |
| `<div class="workspace">` | `<WorkspaceContainer>` | Router outlet for module content |

## Layout Components

| Prototype | React Component | Tailwind Classes |
|-----------|----------------|-----------------|
| `<div class="split-layout">` | `<WorkspaceSplitPane>` | `flex gap-6 items-start` |
| `<div class="quick-filter-panel">` | `<QuickFilterPanel>` | `w-[260px] shrink-0 sticky top-20` |
| `<div class="main-content">` | `<MainWorkspace>` | `flex-1 min-w-0 flex flex-col gap-6` |

## Workspace Zone Components

| Prototype | React Component | Props |
|-----------|----------------|-------|
| `<div class="op-snapshot">` | `<OperationalSnapshot>` | `items: KpiItem[]` |
| `<div class="attention-area">` | `<AttentionArea>` | `title, variant, items: AttentionItem[]` |
| `<div class="work-board">` | `<WorkBoard>` | `columns: BoardColumn[]` |
| `<div class="board-column">` | `<BoardColumn>` | `title, count, children` |
| `<div class="work-card">` | `<WorkCard>` | `title, meta, status, actions` |
| `<div class="queue-list">` | `<QueueList>` | `title, headerAction, items` |
| `<div class="queue-item">` | `<QueueItem>` | `title, subtitle, status, action` |

## Secondary Components

| Prototype | React Component | Props |
|-----------|----------------|-------|
| `<span class="badge">` | `<StatusBadge>` | `variant: success\|warning\|danger\|info` |
| `<button class="btn">` | `<Button>` | `variant: primary\|secondary, size` |
| `<div class="module-tabs">` | `<ModuleTabs>` | `tabs: Tab[], activeTab` |

## Data Flow

- Quick Filter Panel state is managed via React state (or URL query params via Inertia)
- Filter changes trigger Inertia `router.get()` with updated query parameters
- The server returns filtered data, which populates the WorkBoard/QueueList
- No client-side filtering of large datasets — server-driven via Inertia

## Page Structure Example (Front Desk)

```tsx
export default function FrontDesk({ arrivals, snapshot, attention }) {
  return (
    <WorkspaceSplitPane>
      <QuickFilterPanel filters={frontDeskFilters} />
      <MainWorkspace>
        <OperationalSnapshot items={snapshot} />
        <AttentionArea title="Pre-Arrival Attention" items={attention} />
        <QueueList title="Check-In Queue" items={arrivals} />
      </MainWorkspace>
    </WorkspaceSplitPane>
  );
}
```
