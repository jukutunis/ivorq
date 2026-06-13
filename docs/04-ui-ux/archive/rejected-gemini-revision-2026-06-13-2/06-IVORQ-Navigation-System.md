# 06. IVORQ Navigation System

## Primary Navigation Strategy
IVORQ strictly uses a **Top Navigation Bar** for global module switching. There is no left sidebar navigation.

### Global Top Bar Components
1. **Brand Area**: IVORQ Logo on the far left.
2. **Module Links**: Centered (or left-aligned adjacent to brand). Features icon + text (e.g., Home, Front Desk, Housekeeping).
3. **Context Menu**: Right-aligned. Displays the current Property context, Notification Bell, and User Profile menu.

### Secondary Navigation (Within Modules)
Within a specific module workspace, navigation is handled via **Module Tabs**.
- Horizontal tabs located below the workspace header.
- Used to switch between logical groupings of data (e.g., switching from 'Arrivals' to 'Departures' within the Front Desk module).
- Tabs often feature small badges indicating the count of items within that tab.

### Deep Linking & Breadcrumbs
- As users navigate deeper into specific records (e.g., viewing a specific reservation), a Breadcrumb trail is provided in the Workspace Header (e.g., `Front Desk › Arrivals › Res #12345`).
