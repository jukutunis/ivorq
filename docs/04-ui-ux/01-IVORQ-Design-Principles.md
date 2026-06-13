# 01. IVORQ Design Principles

## What IVORQ Is

IVORQ is a **Hotel Operations Platform**. Not a PMS. Not an ERP. Not accounting software. It is the single workspace where hotel teams run their daily operations — from guest arrivals to engineering work orders, from housekeeping task boards to financial approvals.

## Core Philosophy: Context → Work → Action

Every screen in IVORQ follows this flow:

1. **Context** — What is happening right now? (Operational Snapshot, Attention Areas)
2. **Work** — What needs to be done? (Boards, Queues, Assignments)
3. **Action** — Do it. (Inline actions, drawers, quick commands)

We explicitly reject the legacy pattern of Filter → Table → CRUD.

## Design Commitments

### 1. Operations First
The user is running a hotel, not managing database records. Every pixel must reinforce this. Screens show operational states (rooms, guests, work orders, approvals) — not data rows.

### 2. Workspace, Not Software
IVORQ feels like a place of work, not a software application. Inspired by Asana's task-centric workspaces and Opera Cloud's hospitality density. Users spend time *in* their workspace, not *navigating* between screens.

### 3. Premium and Calm
The visual language is executive, minimal, and calm. No visual noise. No competing colors. No crowded toolbars. The interface stays out of the way so operational information can breathe.

### 4. Minimal Clicks
Designed for speed. The most common hospitality actions (check-in, assign room, approve PR, escalate work order) must be reachable within 1-2 clicks from the operational workspace.

### 5. No Legacy Patterns
We reject: AdminLTE, Bootstrap admin templates, generic CRUD, ERP grid layouts, accounting-software aesthetics, and developer-oriented dashboards.

## Design Inspiration Mix

| Source | Weight | What We Take |
|--------|--------|-------------|
| Opera Cloud | 40% | Hospitality density, room boards, operational workflows |
| VHP Cloud | 30% | Clean filter panels, property context, PMS-grade layouts |
| Asana | 20% | Board-first work management, cards, queues, minimal UI |
| IVORQ | 10% | Navy brand identity, hospitality-specific patterns |
