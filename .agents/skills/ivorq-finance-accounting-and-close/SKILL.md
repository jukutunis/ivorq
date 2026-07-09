---
name: ivorq-finance-accounting-and-close
description: |
  IVORQ finance-domain control guidance for accounting, General Ledger, General
  Cashier, financial periods, close, reconciliation, posting review, currency,
  and auditability. Use when a task affects accounting outcomes, financial
  reporting, cash handling, period state, or controlled financial decisions.
metadata:
  version: v1
  publisher: IVORQ
---

# IVORQ Finance, Accounting & Close

## Purpose

Finance behavior is a controlled hospitality domain—not generic CRUD. Apply this skill when work can affect accounting records, financial periods, cash accountability, reconciliation, posting approval, reporting basis, or audit evidence.

For inventory quantity/value movement, AVCO, cost posting, business-date lock behavior, and idempotent stock transactions, use `ivorq-financial-and-inventory-controls` as the companion skill.

## Finance ownership rules

- Preserve the approved boundary between operational source facts and accounting outcomes.
- Do not create, amend, reverse, or close financial records through UI-only state or direct database manipulation.
- A financial action must retain the required tenant/property scope, actor, business date or accounting period, source reference, currency context, approval state, and audit evidence.
- Financial reports must state their scope and basis; do not present derived or incomplete data as an official balance, posting, close, or statement.

## Posting and correction discipline

1. Post only through the approved service/process boundary.
2. Treat source transaction, posting reference, approval authority, and period eligibility as controlled inputs.
3. Do not silently overwrite posted history.
4. Correct financial outcomes through an approved adjustment, reversal, or compensating workflow—not by editing historical facts in place.
5. Do not invent chart-of-accounts treatment, tax treatment, revenue recognition, currency conversion, or approval limits when they are not documented or approved.
6. Preserve the distinction between draft, pending approval, posted, reversed, closed, and reported states.

## Period and close discipline

- Financial period and business-date close are controlled state transitions, not a UI toggle.
- Close must be server-side, time-stamped, actor-resolved, auditable, and fail closed when required authority/context is unavailable.
- Do not reopen, backdate, or post into a closed period without an explicitly approved policy and traceable authority.
- Reconciliation, variance, and exception outcomes must remain reviewable; do not auto-clear discrepancies to make a dashboard appear balanced.

## General Cashier and Night Audit guardrails

- Cash, settlement, folio, and handover facts must be attributed to the correct property, shift/business date, actor, and reference.
- Do not treat a user-entered total as authoritative where a controlled calculation, source transaction, or reconciliation is required.
- Make exceptions visible to the responsible operational/finance role rather than hiding them in a generic “success” state.

## Prohibited shortcuts

- No direct SQL fixes for balances, postings, reconciliation, period state, cash accountability, or historical financial data.
- No automatic posting/reversal/retry rule without approved domain policy.
- No client-side authorization as the only protection for financial actions.
- No generic accounting assumption imported from a third-party skill without IVORQ owner approval.
