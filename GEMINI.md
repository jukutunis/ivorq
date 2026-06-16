# IVORQ AI Instructions

Project:
IVORQ Hospitality Operations Platform

Stack:

* Laravel 13
* PostgreSQL
* React
* Inertia
* Sanctum
* Spatie Permission

Architecture:

* Multi-property SaaS
* Property isolation required
* ULID primary keys
* Service layer architecture
* Repository pattern
* Policy-based authorization

Current Status:

* v0.3-sprint03-complete
* Foundation complete
* Zoning complete
* Housekeeping complete
* 357 tests passing

Rules:

* Never bypass property isolation.
* Never place business logic in controllers.
* Prefer services for business logic.
* Prefer repositories for data access.
* Follow existing architecture patterns.
* Do not redesign completed modules without explicit approval.

Git Tags:

* v0.1-foundation-stable
* v0.2-sprint02-complete
* v0.3-sprint03-complete

# Documentation Rules

NEVER create sprint reports in repository root.

All architecture documents must be stored under:

docs/architecture/<domain>/

All completion reports must be stored under:

docs/completion-reports/

All audit reports must be stored under:

docs/audits/

Repository root must remain clean.
