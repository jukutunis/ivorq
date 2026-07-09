---
name: ivorq-interaction-layer-standard
description: |
  IVORQ Human Designed Hospitality UX, web UI implementation, design-system
  reuse, responsiveness, accessibility, and interaction-layer rules. Use for
  pages, workspaces, navigation, forms, grids, dashboards, overlays, feedback
  states, and user-facing terminology.
metadata:
  version: v2
  publisher: IVORQ
---

# IVORQ Interaction Layer Standard — UI/UX & Web UI Implementation

## Purpose

Use OPERA-derived hospitality operational logic with a modern, human-designed IVORQ interaction layer. The goal is operational familiarity, clarity, and safe action—not generic dashboard styling or a generic web-design trend.

This skill deliberately replaces the need for a separate generic “web design” or “UI/UX style pack” in IVORQ. Preserve the approved UX direction; do not reopen visual design decisions unless the task explicitly authorizes a design change.

## Core interaction patterns

- Top navigation and module launcher, not a long ERP-style sidebar.
- Workspace home pages that orient a user around today’s operational work.
- Familiar hospitality module names and role-centered workspaces.
- Filter panel + data grid for operational lists; do not turn every flow into a card dashboard.
- Universal search, quick create, notification center, and contextual action surfaces where the approved design uses them.
- Clear status, exception, pending-work, and action-needed signals.

## UI/UX and web UI rules

1. Preserve existing shared primitives, tokens, spacing, typography, iconography, and interaction patterns before proposing a new component.
2. Prioritize scanability, operational intent, and a clear next action above decorative visual treatment.
3. Forms must distinguish operational facts, editable input, derived values, approvals, and irreversible actions.
4. Provide coherent loading, empty, error, permission-denied, offline/unavailable where applicable, and success states.
5. Keep destructive or controlled actions distinct, confirmable, explainable, and visibly scoped.
6. Preserve keyboard/focus behavior, semantic labels, contrast, touch targets, and accessible error messaging.
7. Support responsive behavior without collapsing operationally critical information into ambiguous mobile cards or hidden menus.
8. Keep data-grid density readable: allow purposeful columns, filters, saved views, row actions, and detail surfaces without turning the page into a visual dashboard.
9. Use UI state to explain what is happening, but never use UI-only state as proof of an authorized or completed controlled operation.
10. Reuse a shared primitive when it fits; create a new primitive only after identifying a repeatable cross-workspace need.

## Avoid

- generic AdminLTE, long-sidebar, or CRUD-first patterns;
- visual redesign, palette changes, new font systems, or decorative animation without explicit design scope;
- generic SaaS landing-page aesthetics inside operational workspaces;
- technical/database names as default user language;
- hiding critical operational state behind icons alone, hover-only content, or ambiguous color;
- duplicating shared primitives just to avoid understanding an existing one;
- generic external UI/UX skill instructions that conflict with the approved IVORQ pattern.

## Change discipline

UX is frozen except for approved feature work, bug fixes, and minor polish. A UI task must name the user role, operational goal, affected workflow, user-visible states, and validation state. A proposed visual or interaction change that affects shared patterns needs explicit owner approval.
