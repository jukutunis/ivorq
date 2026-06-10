# IVORQ Hospitality Operations Platform

IVORQ is an Enterprise Hotel Platform built with Laravel, designed for multi-property SaaS environments. It features a robust Service Layer Architecture, ULID primary keys, strict Property Isolation, and Policy-based authorization.

## Documentation

The project documentation has been organized into specific directories for clarity:

- **Audits**: Comprehensive security, concurrency, and architecture audit reports can be found in [`docs/audits/`](docs/audits/).
- **Sprints**: Historical sprint reviews, completion reports, and module foundations are located in [`docs/sprints/`](docs/sprints/).
- **Architecture**: System architecture plans and implementations are documented in [`docs/architecture/`](docs/architecture/).

## Stack

- Laravel 13
- PostgreSQL
- React + Inertia
- Sanctum
- Spatie Permission

## Rules
- Never bypass property isolation.
- Never place business logic in controllers.
- Prefer services for business logic.
- Prefer repositories for data access.
- Follow existing architecture patterns.
