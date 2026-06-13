# 07. IVORQ Navigation System

## Principle

Top Navigation is the **only** navigation mechanism in IVORQ.

There is no left sidebar menu. There is no hamburger menu. There is no secondary navigation drawer. The Quick Filter Panel is NOT navigation — it is a workspace utility.

## Top Navigation Bar

### Structure

```
[ IVORQ ]  [ Home ] [ Front Desk ] [ Housekeeping ] [ Engineering ] [ Inventory ] [ Procurement ] [ HRIS ] [ Finance ] [ Reports ] [ AI Assistant ]     [ Property ] [ User ]
```

### Behavior

- Active module is indicated by a bottom border accent (`--primary-500`) and a subtle background highlight (`--navy-800`)
- Inactive modules display at 70% white opacity
- Hover reveals full white text with subtle background transition
- Clicking a module replaces the entire workspace container content

### Module Count

10 modules. This is intentionally dense. Hotel operations staff work with all of these modules throughout a shift. The top bar provides instant context switching — no menu hunting.

## Module Tabs (Sub-Navigation)

Within each module, horizontal tabs switch between operational contexts.

Example — Front Desk:
```
[ Arrivals ] [ Departures ] [ In House ] [ Reservations ] [ Room Rack ]
```

Module tabs do NOT navigate to new pages. They switch the workspace view within the same module context. The Quick Filter Panel persists across tab switches.

## What Is NOT Navigation

| Element | Purpose |
|---------|---------|
| Quick Filter Panel | Workspace filtering utility — slices the visible data |
| Action Buttons | Trigger workflows (drawers, modals) |
| Card Launchers | Quick links on Home — convenience, not navigation |
| Board Column Headers | Organizational labels — not clickable navigation |
