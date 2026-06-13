# 07. IVORQ Component System

## Core Components

### 1. Workspace Card (`.workspace-card`)
The fundamental building block of the Home Dashboard.
- Clean white background (`--surface-card`).
- Subtle 1px border (`--border-default`).
- Light shadow.
- Contains a discrete Header (Title + optional action) and Body.

### 2. Operational Snapshot (`.op-snapshot`)
A horizontal bar of critical metrics at the top of an operational module.
- Features large, bold typography for numbers.
- Semantic coloring on the numbers depending on the metric's health.

### 3. Compact Filter Bar (`.compact-filter-bar`)
The standard filtering mechanism, replacing legacy left-side panels.
- **Left Side**: Search input + Quick dropdown filters (e.g., Date, Status).
- **Right Side**: Meta information (e.g., "24 arrivals") + Primary Action Buttons (e.g., "New Reservation").

### 4. Status Badges (`.badge`)
Used universally to denote state.
- **Success**: Green text/background (e.g., Checked In, Clean).
- **Warning**: Orange/Yellow text/background (e.g., Due In, Dirty, High SLA).
- **Danger**: Red text/background (e.g., Out of Order, Critical).
- **Neutral/Info**: Gray or Blue text/background.

### 5. Data Grid (`.data-grid`)
The standard table layout.
- Navy Header variant (`.navy-header`) for distinct separation of data.
- Generous padding for legibility.
- Right-aligned action buttons on hover.

### 6. Room/Work Board Cards
Used in Housekeeping and Engineering for visual task management.
- Color-coded borders or backgrounds based on status (e.g., red top border for dirty rooms).
- Displays key metadata (Room number, Type, Status, Assignee).
