# ADR-063: Polyglot Specialized Services Boundary

**Status:** Accepted for architecture boundary only
**Date:** 2026-07-05

## Context

IVORQ is an enterprise hospitality operations platform built on a Laravel 13 + PHP backend core, with a React + TypeScript frontend, and PostgreSQL as the primary system-of-record database. Redis, Docker, and Nginx are active components of the current platform baseline.

As the platform scales to support high-throughput operations, real-time messaging, IoT device integrations, and data-science workloads (such as forecasting and analytics), isolated specialized services written in Go or Python may be considered. However, introducing additional runtimes carries significant risks of polyglot sprawl, duplicated business logic, split database schemas, and the fragmentation of established transactional and financial controls.

This document defines the strict boundary rules and governance policies for specialized services in IVORQ. It is not an authorization to implement or deploy any Go or Python runtime at this time.

## Decision

Laravel 13 + PHP remains the primary IVORQ application core and workflow orchestrator. It remains authoritative for:
- hospitality domain workflow;
- approvals;
- authorization;
- audit boundary;
- Finance and General Ledger;
- Inventory and Cost Control;
- Purchasing and Payables;
- PMS operational workflow;
- cross-domain orchestration;
- system-of-record state transitions.

React + TypeScript remains the primary application interaction layer.

PostgreSQL remains the authoritative system-of-record database for IVORQ core domains.

### Go Boundary

Go is a future specialized-service option only. It may be considered later for:
- real-time event fan-out;
- WebSocket gateway;
- high-throughput workers;
- device gateway;
- synchronization service;
- protocol-intensive integration adapters.

Go must not be introduced merely because it is faster or popular. Each future Go service requires:
- a concrete business/operational use case;
- demonstrated Laravel/PHP bottleneck or protocol boundary;
- explicit service ownership;
- separate ADR or approved implementation package;
- API or event contract;
- idempotency boundary;
- audit correlation;
- observability;
- failure and retry policy;
- security and authorization boundary;
- deployment and operational ownership decision.

### Python Boundary

Python is a future specialized-service option only. It may be considered later for:
- AI workflow;
- OCR;
- analytics;
- forecasting;
- reporting automation;
- model-serving or data-science workloads.

Python must not be introduced merely for generic scripting or duplicated backend logic. Each future Python service requires:
- a concrete business/operational use case;
- clear reason Laravel/PHP is not the appropriate owner;
- explicit service ownership;
- input/output contract;
- data retention and privacy boundary;
- audit correlation;
- human-review or approval boundary where output affects operations;
- deterministic fallback or controlled failure behavior;
- security, observability, and deployment decision;
- separate ADR or approved implementation package.

### Core Data and Database Ownership

- Go and Python services must not directly own, write to, or mutate IVORQ core PostgreSQL tables.
- They must not bypass Laravel domain services, Finance controls, approval engines, audit requirements, or authorization boundaries.
- They may consume approved read models, APIs, queues, events, or files only after a future contract is approved.
- No cross-language service may independently post accounting outcomes, create GL journals, alter inventory movements, change payment state, or finalize approval outcomes.
- Finance, GL, Cashier, Banking, Inventory, Purchasing, Payables, and PMS core ownership remain in the Laravel domain boundaries unless future ADRs explicitly change them.
- Any write-back from a specialized service must enter through an approved Laravel-controlled command boundary and preserve actor, correlation, audit, idempotency, and approval requirements.

### Communication and Integration Boundary

- This ADR does not select a message broker, event bus, RPC protocol, API gateway, or orchestration tool.
- Future service communication must be contract-first and versioned.
- A future service must not depend on implicit shared database access.
- API/event payloads must have explicit ownership, validation, idempotency, correlation, and failure behavior.
- New asynchronous workflows need separately approved retry and dead-letter handling.
- No automatic retry rule may override IVORQ’s existing financial control principles.

### Security, Audit, and Observability

- Every future service must preserve tenant and property scope.
- Every request/event must carry correlation context without exposing credentials.
- Audit evidence must remain traceable back to the initiating IVORQ actor and business action.
- Secrets must remain outside source control.
- Service-level logs and metrics must avoid sensitive accounting or guest data exposure.
- Service failures must fail controlled and must not silently mutate core state.

## Explicit Non-Decisions

This ADR does not authorize:
- Go implementation;
- Python implementation;
- Docker runtime expansion;
- new database;
- message broker;
- event bus;
- queue replacement;
- microservice migration;
- Laravel rewrite;
- API gateway;
- direct PostgreSQL integration;
- new infrastructure cost;
- current-service extraction;
- changes to existing Finance, Inventory, PMS, approval, or audit ownership.

## Consequences

### Positive
- keeps Laravel 13 as a stable core;
- permits future targeted specialization;
- prevents service sprawl and duplicated business rules;
- protects financial and operational ownership;
- creates a controlled decision path for future scalability and AI work.

### Limitations
- future Go/Python needs remain subject to ADR and implementation approval;
- no immediate performance benefit;
- future integration requires explicit operational ownership and observability work.

## Deferred Decisions

The following decisions are explicitly deferred:
- whether and when IVORQ introduces any Go service;
- whether and when IVORQ introduces any Python service;
- broker/event bus selection;
- API/RPC technology selection;
- service discovery;
- deployment topology;
- container orchestration;
- data warehouse and analytics architecture;
- model provider or AI runtime;
- OCR provider;
- vector database;
- monitoring stack;
- service-level SLA and cost model.

## Related ADRs

- [ADR-001 Multi-Tenant Hierarchy Architecture](file:///C:/laragon/www/ivorq/docs/architecture/adr/ADR-001-Multi-Tenant-Hierarchy-Architecture.md)
- [ADR-002 Audit Trail Strategy](file:///C:/laragon/www/ivorq/docs/architecture/adr/ADR-002-Audit-Trail-Strategy.md)
- [ADR-004 Finance Module Boundary](file:///C:/laragon/www/ivorq/docs/architecture/adr/ADR-004-Finance-Module-Boundary.md)
- [ADR-040 Interaction Layer Standard](file:///C:/laragon/www/ivorq/docs/architecture/adr/ADR-040-Interaction-Layer-Standard.md)
- [ADR-061 Realized FX Adjustment Candidate Boundary](file:///C:/laragon/www/ivorq/docs/architecture/adr/ADR-061-Realized-FX-Adjustment-Candidate-Boundary.md)
- [ADR-062 FX Realized Candidate Precision, Direction, and Authority Policy](file:///C:/laragon/www/ivorq/docs/architecture/adr/ADR-062-FX-Realized-Candidate-Precision-Direction-and-Authority-Policy.md)
