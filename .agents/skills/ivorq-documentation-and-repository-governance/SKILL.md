---
name: ivorq-documentation-and-repository-governance
description: |
  IVORQ documentation placement, repository navigation, ADR governance, and
  documentation-change discipline. Use when a task touches docs, architecture
  records, sprint notes, repository layout, or proposes new documentation.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Documentation & Repository Governance

## Purpose

Documentation must be deliberate, discoverable, and proportionate. Do not create document churn or move files simply to satisfy a generic convention.

## Knowledge-base structure

The intended IVORQ knowledge base is organized under `docs/` with these top-level groups:

- `00-core/` — product vision, roadmap, architecture, database, business rules, features;
- `01-adr/` — Architecture Decision Records;
- `02-modules/` — module-specific knowledge;
- `03-sprints/` — scoped sprint or delivery records;
- `99-archive/` — superseded or historical material.

Use the repository’s actual existing layout as the source of truth during a task. Do not move legacy documents without authorization.

## Documentation rules

1. Create or edit documentation only when the task explicitly authorizes it or the change cannot be safely understood without a proportionate record.
2. ADRs govern significant and enduring decisions. Do not create an ADR for routine implementation details.
3. Do not edit approved ADRs to make a new implementation appear compliant.
4. Keep module documentation in its module area; keep global decisions in core/ADR locations.
5. State whether a document is a proposal, approved decision, implementation record, or historical archive.

## Repository discipline

- Inspect before assuming paths, module names, framework versions, or current architecture.
- Do not reorganize files, rename folders, or add documentation templates as incidental cleanup.
- Respect explicit allowed-file scope in delivery tasks.

## Review output

For documentation work, report: purpose, file path, decision status, links/relationships to existing material, and whether any owner approval remains needed.
