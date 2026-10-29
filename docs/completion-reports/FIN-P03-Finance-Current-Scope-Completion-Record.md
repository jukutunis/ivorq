# FIN-P03 Finance Current-Scope Completion Record

**Package:** `FIN-P03_AR_TREASURY_PLANNING_AND_LIVE_FINANCE_WORKSPACE`

**Canonical predecessor:** `37c3f872c4506177261188448ce4318a79e359ea`

**Scope:** Implementable canonical Finance runtime; no production activation

| Capability | Final status | Current-scope result |
|---|---|---|
| Procure to Pay | COMPLETE | Canonical supplier intake, approval, settlement, correction, and controlled posting paths remain authoritative. |
| General Ledger | COMPLETE | Controlled candidate, review, draft, finalization, posting, reversal, queue, and evidence paths remain authoritative. |
| Financial Reporting | COMPLETE | Property-scoped trial balance, profit and loss, balance sheet, and cash-flow runtime is registered. |
| Financial Period development control-plane | COMPLETE | Property-scoped initialization/open/close controls and posting guards are available for isolated development use. |
| Banking controlled operations | COMPLETE | Controlled payment, statement, reconciliation, session, return, and reversal evidence paths remain authoritative. |
| Banking deferred convergence | DEFERRED_BY_ADR | External integration, automated reconciliation, migration execution, pilot, and cutover remain governed by ADR-071/072 and separate activation. |
| General Cashier | COMPLETE | Operations-owned cashier evidence is exposed through canonical Finance-facing setup, count, execution, correction, and reconciliation workflows. |
| Realized FX | COMPLETE | Controlled realized-adjustment eligibility, review, posting, and evidence workflow remains authoritative. |
| FX evidence | COMPLETE | Property-scoped exchange-rate record, review, and evidence workspace is registered. |
| Unrealized FX | ARCHITECTURE_DECISION_REQUIRED | Period-end revaluation policy, account mapping, reversal timing, and posting semantics are not established by accepted source contracts. |
| AR / City Ledger accepted-transfer exposure | COMPLETE | The live workspace projects only accepted, unreversed Guest Ledger transfer evidence, with property scope, aging, currency totals, and decision provenance. |
| AR customer accounts, receipts, allocation, limits, collections, chargebacks, write-off, and bad debt | ARCHITECTURE_DECISION_REQUIRED | Canonical customer-account identity, settlement lifecycle, and accounting/correction semantics are not represented by accepted schema or ADRs; no policy was invented. |
| Treasury | COMPLETE | A read-only, property-scoped workspace projects GL cash equivalents, immutable bank snapshots, approved AP invoice exposure, accepted AR exposure, and existing alerts without read-side mutation or duplicate balance authority. |
| Budgeting | COMPLETE | Existing property/year versions, P&L lines, submission, permission-separated approval/rejection, locking, history, and actual variance are operational. |
| Forecasting | COMPLETE | Existing property/year versions, source seeding, P&L lines, submission, permission-separated approval/rejection, locking, history, and budget/actual variance are operational. |
| Shared Finance workspace | COMPLETE | Hard-coded `financeData` authority is removed; server-authoritative, permission-scoped, property/period projections and operational empty states are live. |
| Payment adjustment, tax, discount, and withholding application | DEFERRED_BY_ADR | Reference/configuration foundations exist, but end-to-end application, posting, correction, and evidence semantics are not accepted. |
| External bank APIs, money movement, AI forecasting, and specialized prediction services | OUT_OF_SCOPE | No external service, bank synchronization, payment initiation, or prediction dependency was added. |
| Operational live blockers | OUT_OF_SCOPE | Operational First Trust remains disabled; Business Date and Financial Period remain not started; production posting, AWS enablement, pilot, and cutover remain prohibited. |

## Control boundaries

- PMS owns Guest Ledger facts until an accepted transfer; Finance reads accepted, unreversed decision evidence and does not rewrite PMS source facts.
- Treasury aggregates canonical Banking, General Ledger, AP, and AR evidence and does not post journals or create balances.
- Budget and Forecast remain planning/control evidence and cannot change GL, AP, AR, cashbook, or bank evidence.
- Active Property and all financial source identities are resolved and revalidated server-side. Cross-property planning versions, accounts, and departments fail closed.
- `RoleSeeder.php` remains byte-identical at blob `8519c4618ec614aafb94f2611753bfbad5a147ff`; newly registered permissions are not assigned to production roles.

## Activation state

- AWS/live actions: `0/0`
- Operational First Trust: `DISABLED`
- Business Date: `NOT STARTED`
- Financial Period: `NOT STARTED`
- Pilot/cutover: `NO/NO`
- FIN-P03 merge: `NOT PERFORMED`
