# IVORQ Domain Boundaries & Integration Map

This document establishes the official ownership, providing, and consuming boundaries for IVORQ bounded domains in alignment with the Phase 13 Revised Architecture. Strict adherence to these boundaries guarantees long-term extensibility and enterprise scalability.

## 1. Planning & Budgeting
**Owner**: Planning & Budgeting Domain

**Purpose**: The central engine for financial planning, budgeting, forecasting, and OPEX/CAPEX lifecycles.

**Provides**:
- Budgets (Base, Optimistic, Iterative Scenarios)
- Forecasts (Rolling and Actualized)
- Corporate Benchmarks & Templates

**Consumes**:
- **Workforce Planning**: Imports labor standards and headcount plans for payroll forecasting.
- **Operational Metrics**: Ingests aggregated statistical actuals (Occupancy, ADR) to power Budget vs Actual analysis.

---

## 2. Workforce Planning
**Owner**: Independent Workforce Planning Domain

**Purpose**: The shared planning engine dedicated strictly to labor modeling, independent of pure finance or HR operations.

**Provides**:
- Headcount Plans
- Labor Standards
- Budget Positions

**Consumes**:
- **Future HRIS**: Reconciles forecasted staffing models against actual payroll and roster schedules.

---

## 3. Operational Metrics
**Owner**: Independent Operational Metrics Domain

**Purpose**: A centralized single source of truth for all aggregated statistical actuals. It strictly forbids storing raw transaction data.

**Provides**:
- Occupancy Actual
- ADR Actual
- RevPAR Actual
- Covers Actual
- Labor Hours Actual

**Consumes**:
- **PMS**: Night audit and daily actual extracts.
- **POS**: Daily outlet covers and revenue aggregates.
- **HRIS**: Actualized labor hours.

---

## 4. Future Domain Integration Readiness

### Revenue Management
**Future Owner Of**:
- Rate Strategy
- Dynamic Pricing
- Occupancy Forecast
- ADR Forecast
- Channel Mix
**Integration**: Acts as the intelligent predictive layer hydrating `RevenueAssumption`s inside Planning & Budgeting.

### Sales & Event Management
**Future Owner Of**:
- Leads, Accounts, Opportunities
- Events, Function Space, BEOs
- Group Business, Room Blocks, Deposits
- Event Revenue Forecast
**Integration**: The Event Revenue Forecasts serve as lead indicators, feeding future pipeline data directly into `RevenueAssumption` and `Operational Metrics`.

### HRIS
**Future Consumer Of**:
- **Workforce Planning**: Transforms theoretical Headcount Plans into actionable recruitment drives and rostering logic.

### PMS
**Future Producer To**:
- **Operational Metrics**: Emits aggregated operational truths post-night audit (e.g., Room Nights, Arrival/Departure counts, actualized Occupancy/ADR).

### Enterprise Performance Management (EPM)
**Future Consumer Of**:
- **Planning & Budgeting**: Ingests locked budgets and forecasts to construct executive scorecards.
- **Operational Metrics**: Combines plan vs. actuals into high-level enterprise dashboards and visual KPIs without needing direct access to underlying transactional tables.
