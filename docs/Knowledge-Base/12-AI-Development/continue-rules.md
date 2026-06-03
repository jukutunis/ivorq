
# Continue.dev Rules

Project: IVORQ Hotel Operations Platform

Version: 1.0

Status: Approved

Owner: CTO

---

# Context Loading Order

Always load:

1. MASTER_INDEX.md
2. architecture.md
3. database-finalization.md
4. laravel-folder-structure-v1.md
5. module PRD
6. feature-template.md

---

# Development Workflow

Before coding:

Read PRD

Read Architecture

Read Database Rules

Read Folder Structure

Analyze Dependencies

Create Plan

Only then write code.

---

# File Creation Order

When creating a new feature:

1. Migration
2. Model
3. Repository
4. Service
5. Request
6. Policy
7. Controller
8. API Resource
9. Tests
10. Documentation

Never skip steps.

---

# Laravel Standards

Use:

* Laravel 13+
* PHP 8.3+
* Sanctum
* Spatie Permission
* PostgreSQL
* Redis
* Queue
* React
* Inertia
* PWA

---

# Forbidden

Do not:

* Put business logic in controllers
* Access database directly from controllers
* Create duplicate models
* Create duplicate migrations
* Ignore property scope
* Ignore tests

---

# Review Checklist

Before completing work:

✓ Architecture compliant

✓ Database compliant

✓ Security compliant

✓ Tests created

✓ Documentation updated

---

# Output Format

Always provide:

1. Summary
2. Files Created
3. Files Modified
4. Risks
5. Next Steps

---

# Final Rule

All generated code must be maintainable, testable, scalable, and aligned with IVORQ architecture.
