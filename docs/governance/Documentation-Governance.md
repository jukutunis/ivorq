# Documentation Governance

## Root Directory Protection

Root may only contain:

- app/
- bootstrap/
- config/
- database/
- Modules/
- resources/
- routes/
- storage/
- tests/
- vendor/

and core files:

- README.md
- GEMINI.md
- composer.json
- package.json
- phpunit.xml

No Review files allowed in root.

## Documentation Placement Rules

Architecture:
`docs/architecture/`

Reviews:
`docs/reviews/`

Audits:
`docs/audits/`

Roadmaps:
`docs/roadmap/`

ADR:
`docs/decisions/`

Knowledge:
`docs/knowledge-base/`

Module Specs:
`docs/modules/`

## Sprint Completion Rules

At sprint completion:

1. Update MASTER_INDEX.md
2. Move reviews into docs/reviews
3. Verify root cleanliness
4. Run git status
5. Report documentation changes

## Documentation Creation Rules

Never create duplicate documents.

Prefer updating existing documents.

Do not create temporary markdown files.

Do not leave walkthrough.md in root.

Do not leave task.md in root.
