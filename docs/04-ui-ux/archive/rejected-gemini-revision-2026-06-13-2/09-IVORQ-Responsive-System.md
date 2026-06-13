# 09. IVORQ Responsive System

## Target Environments
IVORQ is built as a **Desktop-First** Hospitality Operations Platform. Front desk agents, reservation agents, and back-office staff primary utilize desktop monitors or wide-screen terminals.

### Breakpoints
- **Desktop (1024px and above)**: The primary, optimized view. Uses full-width grids, comprehensive data tables, and expanded filter bars.
- **Tablet (768px - 1023px)**: Supported for housekeeping supervisors or engineering technicians using iPads. Data tables may truncate less critical columns or collapse into card-based lists.
- **Mobile (<768px)**: Only specific sub-modules are optimized for mobile (e.g., Housekeeping Attendant Room List, Engineering Work Order execution). The core command center and complex financial screens are not targeted for mobile devices.

### Responsive Behaviors
- **Fluid Grids**: Dashboard cards on the Home screen wrap intelligently as screen real estate shrinks.
- **Compact Filter Bar**: On smaller screens, filter options collapse into a "Filters" dropdown menu to save horizontal space.
