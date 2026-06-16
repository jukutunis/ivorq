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

## DOCUMENT OUTPUT RULE

Any Architecture Audit, Architecture Revision,
Domain Audit, Final Decision, ADR, Completion Report,
Implementation Report, or Governance Report MUST:

1. Be saved as a physical .md file inside repository.
2. Never exist only as Gemini artifact.
3. Be visible in git status.
4. Be stored in approved docs folders.
5. Include file verification before task completion.

Task is NOT complete until file is commit-ready.

## DOCUMENT VERIFICATION RULE

Every document creation must verify:

1. File exists physically
2. File size reported
3. Line count reported
4. Appears in git status
5. Commit recommendation generated

A document is NOT considered completed until all five checks pass.