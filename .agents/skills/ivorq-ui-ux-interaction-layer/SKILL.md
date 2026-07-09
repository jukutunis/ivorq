---
name: ivorq-ui-ux-interaction-layer
description: |
  IVORQ Human Designed Hospitality UX — the preferred interaction-layer skill.
  Supersedes the legacy `ivorq-interaction-layer-standard` generic name.
  Use for pages, workspaces, navigation, forms, grids, dashboards, overlays,
  feedback states, and user-facing terminology.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ UI/UX Interaction Layer

> **This skill supersedes `ivorq-interaction-layer-standard`.** That skill is retained as a compatibility alias. Prefer this one for new work.

## Purpose

Use OPERA-derived hospitality operational logic with a modern, human-designed IVORQ interaction layer. The goal is operational familiarity, clarity, and safe action — not generic dashboard styling.

## Core interaction patterns

- **Top navigation and module launcher**, not a long ERP-style sidebar.
- **Workspace home pages** that orient a user around today's operational work.
- **Familiar hospitality module names** and role-centered workspaces.
- **Filter panel + data grid** for operational lists; do not turn every flow into a card dashboard.
- **Universal search, quick create, notification center**, and contextual action surfaces where the approved design uses them.
- **Clear status, exception, pending-work, and action-needed signals.**

## UI/UX rules

1. Preserve existing shared primitives, tokens, spacing, typography, iconography, and interaction patterns before proposing a new component.
2. Prioritize scanability, operational intent, and a clear next action above decorative visual treatment.
3. Forms must distinguish operational facts, editable input, derived values, approvals, and irreversible actions.
4. Provide coherent loading, empty, error, permission-denied, and success states.
5. Keep destructive or controlled actions distinct, confirmable, explainable, and visibly scoped.
6. Preserve keyboard/focus behavior, semantic labels, contrast, touch targets, and accessible error messaging.
7. Support responsive behavior without collapsing operationally critical information.
8. Keep data-grid density readable: allow purposeful columns, filters, row actions, and detail surfaces.
9. Use UI state to explain what is happening, but never use UI-only state as proof of an authorized or completed controlled operation.
10. Reuse a shared primitive when it fits; create a new primitive only after identifying a repeatable cross-workspace need.
11. Operational states must be clear — ready, blocked, pending, reviewed, unknown.

## Avoid

- Generic AdminLTE, long-sidebar, or CRUD-first patterns.
- Visual redesign, palette changes, new font systems, or decorative animation without explicit design scope.
- Generic SaaS landing-page aesthetics inside operational workspaces.
- Technical/database names as default user language.
- Hiding critical operational state behind icons alone, hover-only content, or ambiguous color.
- Duplicating shared primitives just to avoid understanding an existing one.

## Change discipline

UX is frozen except for approved feature work, bug fixes, and minor polish. A UI task must name the user role, operational goal, affected workflow, user-visible states, and validation state. A proposed visual or interaction change that affects shared patterns needs explicit owner approval.
