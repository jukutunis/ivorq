# 10. IVORQ Interaction System

## Core Interactions

### 1. Inline Actions
The most common interactions happen directly within work cards and queue items. Users click "Start Check-In", "Assign Room", or "Approve" without navigating away from the workspace.

### 2. Right-Side Drawers
For complex workflows that require form input (creating a reservation, filing a work order, reviewing a purchase request), a **slide-in drawer** opens from the right edge of the screen.
- Drawer width: 480px (narrow) or 640px (wide)
- The underlying workspace remains visible but dimmed
- Preserves operational context — the user never "leaves" their workspace

### 3. Confirmation Modals
Used only for destructive or irreversible actions:
- Cancel a reservation
- Reject a purchase request
- Mark a room Out of Order
Small centered modal with clear action labels.

### 4. Hover States
- Queue items: subtle `--primary-50` background on hover
- Buttons: slight opacity shift or shadow
- Nav items: background color transition
- Work cards: subtle shadow elevation

### 5. Toast Notifications
Non-blocking feedback appearing at the top-right.
- "Guest checked in successfully"
- "Work Order assigned to Wayan"
- "Purchase Request approved"

### 6. Quick Filter Interaction
Filter changes immediately update the visible work board/queue content. No "Apply" button required for simple filters. Text search uses debounce (300ms).

## Transitions

- Module switching: `300ms` fade-in
- Drawer open/close: `250ms` slide
- Hover states: `150ms` transition
- Toast appear/disappear: `200ms` with auto-dismiss after `4s`
