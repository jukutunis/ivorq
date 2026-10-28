# FIN-R1 Finance Canonical Revalidation and Implementation Gap Audit

**Status:** Frozen for independent review  
**Canonical predecessor:** `1dba42313e9713f5c81a478934ac59aab194ad2e`  
**Audit type:** Source-backed implementation and reachability review; no runtime activation  
**Production/dependency delta:** `0/0`

## Scope and method

This audit classifies the Finance implementation at the canonical predecessor. It reads registered providers, routes, controllers, services, models, migrations, permissions, workspaces, committed tests, and the accepted Finance ADRs. A passing test name is supporting design evidence, not a substitute for reachable production code. No test was executed because the material classifications were resolvable from source.

Classifications are `COMPLETE`, `PARTIAL`, `MISSING`, `LEGACY_ACTIVE`, `DUPLICATE`, `BLOCKED`, or `DEFERRED`. `COMPLETE` means complete for the stated capability, not that the whole module is complete.

## Canonical ownership

- Finance owns the General Ledger, AP liability and settlement, AR/City Ledger after accepted transfer, controlled banking evidence, FX accounting, budgeting, forecasting, treasury projections, and financial reporting.
- Operations owns supplier/receiving facts, General Cashier sessions and cash evidence, and the PMS Guest Ledger before an AR transfer is accepted. Finance consumes those facts through controlled boundaries; it must not rewrite their source records.
- Inventory owns quantity movement and operational valuation inputs. Finance Cost Control owns accounting consequences and derived cost evidence, not inventory movement authority.
- Business Date and Financial Period remain mandatory posting gates. This audit neither starts nor bypasses either control.
- `Modules/Finance/GeneralCashier` is a Finance-facing controller over `Modules/Operations/GeneralCashier`; that layering is intentional ownership integration, not a duplicate General Cashier domain.

## Implementation matrix

| # | Area / capability | Classification | Source-backed finding and remaining gap |
|---:|---|---|---|
| 1 | GL candidate -> review -> draft -> finalization -> posting | COMPLETE | Registered controlled services enforce permission, property scope, provenance, balanced lines, idempotency, Business Date, and Financial Period. |
| 2 | GL journal reversal | COMPLETE | One linked reversal, posted-original validation, period/date guards, idempotency, and database integrity constraints are present. |
| 3 | GL account, ledger, and posting evidence integrity | COMPLETE | Posted-entry immutability, operational identity mapping, candidate provenance, and posting evidence are enforced. |
| 4 | Trial balance, P&L, balance sheet, and cash-flow APIs | COMPLETE | Permission-gated, property-scoped, read-only report paths and committed tests exist. |
| 5 | GL operational and reporting workspace | PARTIAL | GRNI control UI exists; general journal queues and the four financial reports have no complete Finance workspace. |
| 6 | Financial Period administration surface | PARTIAL | Posting guards exist, but a canonical operator workspace for controlled initialization/open/close is not source-proven. |
| 7 | Older direct GL/subledger/period services | LEGACY_ACTIVE | Older callable paths coexist with the controlled lifecycle; `PeriodControlService` behavior is weaker than the newer initialization authority. |
| 8 | Supplier invoice review, approval/rejection, and exception resolution | COMPLETE | Registered Payables controllers/services/workspace provide controlled review and exception decisions. |
| 9 | Three-way match and GRNI/AP clearing | COMPLETE | Controlled matching, candidate review/materialization/finalization/posting, and settlement control workspaces exist. |
| 10 | AP settlement allocation, partial, sequential, and split payment | COMPLETE | Server-owned allocation and settlement evidence paths are implemented and tested for isolation, locking, and over-allocation. |
| 11 | Payment proposal lifecycle | COMPLETE | Create, submit, approve/reject, cancel, segregation, confirmation, scope, and idempotency controls are reachable. |
| 12 | Controlled cash and bank payment execution | COMPLETE | Both use server-derived amount/currency and sensitive confirmation; accounting remains on the controlled posting path. |
| 13 | Supplier invoice initiation/registration | PARTIAL | Domain services exist, but no canonical operational intake workspace is source-proven; the legacy API entry point is broken. |
| 14 | Payment void, return, and supplier-payment reversal operations | PARTIAL | Cash void/return/reversal services and accounting evidence exist; operational UI coverage is incomplete and bank-return evidence is absent. |
| 15 | Tax, withholding, discount, and payment adjustment application | DEFERRED | Reference/configuration foundations exist, but an end-to-end runtime application lifecycle is not activated. |
| 16 | Legacy Payables API | LEGACY_ACTIVE | Registered API routes import a missing `VendorInvoiceController`; this path must not be treated as canonical invoice intake. |
| 17 | `AccountsPayable` versus registered `Payables` | DUPLICATE | Payables consumes older AccountsPayable types while overlapping concepts remain. The compatibility/retirement boundary is not frozen in code. |
| 18 | Controlled banking payment confirmation/execution | COMPLETE | Controlled accounts and statement-line evidence feed a confirmation-gated workspace and payment lifecycle. |
| 19 | Controlled manual bank reconciliation | COMPLETE | Exact, immutable, property-scoped manual reconciliation and evidence workspaces are reachable. |
| 20 | Controlled reconciliation-session lifecycle | PARTIAL | Read-only/convergence evidence is present; canonical session creation and full session integration remain deferred by ADR-071/072. |
| 21 | Legacy bank account/statement/reconciliation API | LEGACY_ACTIVE | Registered legacy models and API routes coexist with the operative controlled banking domain. |
| 22 | External bank integration and banking migration/cutover | DEFERRED | Intake/control-plane evidence is deliberately non-executable; no external integration, pilot, or cutover is authorized. |
| 23 | Bank payment return evidence and reversal | MISSING | No bank-equivalent of cash-return evidence or end-to-end bank supplier-payment reversal was found. |
| 24 | General Cashier execution, cashbook projection, count/baseline, and reconciliation | COMPLETE | Operations owns immutable source evidence; Finance exposes controlled execution/reconciliation and read-only cashbook evidence. |
| 25 | Cashier session, instrument, cash-count, and baseline administration | PARTIAL | Strong services and tests exist, but complete operator setup and evidence-capture workspaces are not source-proven. |
| 26 | Cash void/return/reversal operator workflow | PARTIAL | Backend evidence and reversal services exist without a complete correction workspace. |
| 27 | Realized FX adjustment lifecycle | COMPLETE | Eligibility, candidate, independent review, materialization, finalization, posting, workspace, permissions, and break-glass controls exist. |
| 28 | Exchange-rate evidence operations | PARTIAL | Record/approve/reject services enforce scope, precision, segregation, and immutability; no operational evidence workspace exists. |
| 29 | Unrealized FX revaluation | MISSING | No controlled period-end revaluation lifecycle, posting evidence, or operator workspace was found. |
| 30 | Guest Ledger -> AR transfer decision slice | PARTIAL | Request/accept/reject/reverse is controlled and append-only across PMS and Finance ownership, but it is only one AR slice. |
| 31 | Broader AR/City Ledger operations | MISSING | Customer accounts, credit limits, aging/collections, receipts/allocation, statements, bad debt, chargebacks, and a live AR workspace are not source-proven. |
| 32 | Treasury | PARTIAL | Cash-position, liquidity, alerts, payment/allocation models/services/schema/tests exist, but no registered Treasury provider or operational workspace exists. |
| 33 | Budgeting | PARTIAL | Budget/version/approval/variance foundations exist, including purchasing enforcement, but no registered runtime module or live workspace exists. |
| 34 | Forecasting | PARTIAL | Forecast/version/variance foundations exist, but no registered runtime module or live workspace exists. |
| 35 | Shared Finance workspace | PARTIAL | Navigation exists, but primary cash/AP/AR/budget figures, dates, cards, and actions come from hard-coded `financeData` fixtures. |
| 36 | Operational posting readiness | BLOCKED | Live posting remains gated by Operational First Trust, Business Date, and Financial Period; all remain intentionally inactive. |

**Frozen counts:** `COMPLETE 13`, `PARTIAL 13`, `MISSING 3`, `LEGACY_ACTIVE/DUPLICATE 4`, `BLOCKED/DEFERRED 3`.

## Complete capabilities to preserve

Do not rebuild the controlled GL lifecycle, GRNI/AP clearing, payment proposals, AP settlement allocation, cash/bank payment execution, controlled manual reconciliation, General Cashier cashbook evidence, or realized FX adjustment lifecycle. Follow-on packages must extend their public contracts and reuse their permission, provenance, confirmation, locking, idempotency, and posting guards.

## Actionable gaps

### UI and operational gaps

- Canonical supplier-invoice intake and correction workspaces are missing or incomplete.
- General Cashier setup/count/baseline and cash correction services are not fully exposed to operators.
- Bank return/reversal is missing, and controlled reconciliation-session lifecycle is incomplete.
- GL report queues, Financial Period operations, and exchange-rate evidence lack complete workspaces.
- AR, Treasury, Budgeting, Forecasting, and the shared Finance landing page are not live operational surfaces.

### Accounting and control gaps

- A bank-return source fact and its reversal candidate/posting chain do not exist.
- Legacy GL, AP, and Banking paths can bypass the clarity of the controlled domains even where they do not bypass database controls.
- Period administration has two generations of service behavior and no single operator authority surface.
- Exchange-rate evidence is service-only and unrealized revaluation is absent.
- Broader AR lacks receivable-account, collections, receipt, allocation, statement, and write-off controls.

### Cross-domain blockers

- Supplier invoice intake must consume Purchasing/Receiving evidence without moving source ownership into Finance.
- General Cashier configuration and cash evidence remain Operations-owned; Finance may expose task-oriented controls but not duplicate the aggregate.
- AR activation depends on the accepted PMS Guest Ledger transfer boundary and must never treat a requested/rejected/reversed transfer as settlement.
- Treasury must read controlled Banking, Cashbook, AP, AR, Budget, and Forecast projections rather than introduce a second posting authority.
- Any posting-capable package remains blocked from live use until Operational First Trust, Business Date, and Financial Period are separately authorized.

## Implementation order and runtime packages

1. **FIN-P01 — Procure-to-Pay Operational Completion and Canonical Path Consolidation.** Close the start/correction gaps in the already-strong supplier-payment vertical slice and remove ambiguous legacy reachability.
2. **FIN-P02 — Ledger, Period, Banking Session, and FX Control Plane Completion.** Provide canonical operator surfaces, retire weaker paths, complete bank session convergence, and add controlled rate/revaluation operations without live activation.
3. **FIN-P03 — AR, Treasury, Planning, and Live Finance Workspace.** Complete City Ledger operations, activate registered/read-only Treasury and planning projections, and replace fixture data with permission-scoped live summaries.

Packages are ordered by operational value and dependency. They are proposals only; none is activated by FIN-R1.

## FIN-P01 frozen scope

**Objective:** make the existing supplier-invoice-to-payment lifecycle operationally startable, correctable, and unambiguous without creating a second accounting authority.

### Exact submodules

- `Modules/Finance/Payables`
- `Modules/Finance/AccountsPayable` only for compatibility migration/retirement
- `Modules/Operations/GeneralCashier`
- `Modules/Finance/GeneralCashier`
- `Modules/Finance/Banking`
- `Modules/Finance/GeneralLedger` only for existing candidate/posting integrations
- Foundation Authorization/Audit only for narrowly required permissions and evidence

### Missing capabilities included

1. Canonical supplier-invoice registration/intake from server-resolved supplier, PO, receipt, currency, and property evidence.
2. Operator setup/capture surfaces required by the existing General Cashier flow: session, instrument, cash count, and reconciliation baseline.
3. Controlled correction workspace for pre-post void, cash return, and cash supplier-payment reversal.
4. New bank-return evidence, bank supplier-payment reversal execution, and the existing controlled GL candidate/review/materialize/finalize/post chain.
5. Removal or fail-closed retirement of the broken legacy Payables API and an explicit `AccountsPayable` compatibility boundary.

Payment adjustment application, external bank integration, automated reconciliation, pilot/cutover, and live posting activation are excluded.

### Backend and database impact

- Reuse current Payables, General Cashier, Banking, and GL services; add orchestration/controllers rather than duplicate calculations or posting.
- Add append-only bank-return and bank-reversal evidence tables with property/source identity, immutable amount/currency, actor, reason, timestamps, idempotency hash, and uniqueness constraints.
- Add only constraints/columns required to bind invoice intake and corrections to immutable source evidence.
- Preserve existing posted journals, executions, cashbook transactions, settlement allocations, and source documents.

### Frontend/UI work

- Supplier Invoice Intake workspace with source-evidence preview and controlled submission.
- General Cashier Setup and Count/Baseline workspace.
- Payment Correction workspace covering eligible void, cash return/reversal, and bank return/reversal states.
- Read-only provenance panels linking source invoice, proposal item, execution, return, reversal, candidate, and journal.
- No browser-supplied amount, currency, GL account, property, posting date, or source authority.

### Permission and audit work

- Separate permissions for invoice registration, cashier setup, cash evidence capture, pre-post void, cash return/reversal, bank return recording, and bank reversal execution.
- Maker/checker separation where an action changes financial interpretation; sensitive confirmation for every correction execution.
- Append-only audit evidence for denied and successful sensitive actions, with actor/company/property/source identities and reason codes; no role mutation from an operational flow.

### Accounting invariants

- One canonical AP liability and one GL posting authority.
- Debits equal credits; posted journals and source evidence remain immutable.
- A correction creates linked evidence and, when needed, a linked reversal journal; it never edits or deletes the original.
- Amount, currency, vendor, account mapping, property, Business Date, and Financial Period are server-derived and revalidated under lock.
- Exactly-once source identity and idempotent replay; conflicting replay fails closed.
- Partial/split settlement totals cannot exceed the approved invoice obligation.
- Closed/unavailable Business Date or Financial Period blocks posting without partial mutation.

### Cross-domain dependencies

- Purchasing/Receiving: supplier, PO, receipt, and three-way-match source evidence.
- Operations General Cashier: cashier sessions, instruments, counts, baselines, and cash return evidence.
- Controlled Banking: account, statement-line, and bank-return source evidence.
- Foundation: active actor, company/property membership, permissions, sensitive confirmation, and audit.
- GL: mappings, candidate lifecycle, posting evidence, Business Date, and Financial Period gates.

### Focused validation

- Source-integrity tests proving one canonical controller/service path and retirement of the missing-controller route.
- PostgreSQL tests for invoice intake, property isolation, immutable source binding, duplicate prevention, and conflicting replay.
- Cash and bank correction matrices: unauthorized, unconfirmed, cross-property, wrong instrument/account, partial, duplicate, concurrent, closed-date, closed-period, successful, and idempotent replay.
- GL balance/provenance assertions and zero mutation of unrelated source, permission, and role tables.
- Focused route/UI tests for empty, ineligible, ready, success, failure, and already-corrected states.
- No broad registry, historical B5A suite, live authority call, external bank call, or production write.

### Definition of done

- An authorized operator can register a source-backed supplier invoice, complete its existing review/payment lifecycle, and execute every supported cash or bank correction from canonical workspaces.
- Every financial value and identity is server-owned, property-scoped, audited, immutable, and idempotent.
- Bank-return/reversal produces controlled evidence and balanced GL reversal through the existing posting authority.
- The broken legacy Payables API is unreachable or explicitly compatibility-routed, and the AccountsPayable boundary is documented and tested.
- Production/dependency changes are independently reviewed; Operational First Trust, Business Date, Financial Period, pilot, cutover, and live writes remain inactive unless separately authorized.

## FIN-R1 safety state

- AWS/live actions: `0/0`
- Operational First Trust: `DISABLED`
- Business Date: `NOT STARTED`
- Financial Period: `NOT STARTED`
- Pilot/cutover: `NO/NO`
- Canonical merge: `NOT PERFORMED`
