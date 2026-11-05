# PROC-P03 Procurement Current-Scope Completion Record

## Result

`PROCUREMENT_CURRENT_IMPLEMENTABLE_SCOPE_COMPLETE`

PROC-P03 completes the currently implementable controlled pre-post Goods Receipt exception, inspection, correction, rejection, and void lifecycle under ADR-091. Inventory remains the controlled receipt owner; Procurement remains the Purchase Order/commercial-intent owner; Finance consumes only accepted POSTED immutable receipt evidence.

## Capability Classification

| Capability | Classification | Evidence |
|---|---|---|
| Controlled receipt discrepancy and inspection evidence | COMPLETE | Server-resolved Property/receipt/line/PO provenance; canonical discrepancy and inspection enums |
| Shortage, overage observation, damaged, wrong-item, expired, quality issue | COMPLETE | Controlled exception evidence with immutable source fingerprint |
| Idempotent report/replay and conflicting replay rejection | COMPLETE | Property-scoped idempotency key and immutable source fingerprint |
| Maker/checker exception resolution | COMPLETE | Independent resolution permission; reporter self-resolution rejected |
| Accepted-quantity reduction | COMPLETE | DRAFT-only reduction within observed quantity and remaining PO capacity |
| Rejected-line disposition | COMPLETE | Line preserved as REJECTED and excluded from posting evidence/movement |
| Supplier rejection before posting | COMPLETE | REJECT_LINE or terminal pre-post VOID_RECEIPT resolution |
| DRAFT and ConfirmationPending void | COMPLETE | Terminal VOIDED receipt with actor, reason, and timestamp; zero posting facts |
| Accepted-only posting and PO quantity | COMPLETE | Only ACCEPTED lines create commercial evidence, stock movement, and received quantity |
| Finance controlled capacity compatibility | COMPLETE | Finance remains sourced from accepted POSTED immutable commercial evidence only |
| Operational Goods Receipt exception UX | COMPLETE | Report/inspection drawer, resolution confirmation, void confirmation, provenance display |
| Posted Goods Receipt reversal | DEFERRED_BY_ADR | ADR-091 requires a separate posted correction architecture |
| Supplier return after posting | DEFERRED_BY_ADR | Return, settlement, and credit/debit-note consequences are outside pre-post scope |
| Controlled InventoryStockMovement reversal | ARCHITECTURE_DECISION_REQUIRED | Existing reversal runtime is InventoryTransaction/CostControl based and not applicable |
| CostControl reversal, Finance/GRNI/AP reversal, credit/debit notes | OUT_OF_SCOPE | Explicit Owner boundary |

## Governance

- No ReceivingDocument dual-write.
- No InventoryTransaction shadow-write.
- No CostControl write.
- No Finance accounting behavior change.
- No Business Date or Financial Period activation.
- One unassigned permission was registered: `inventory.purchasing.goods-receipt.exception.resolve`.
- RoleSeeder was not modified.
- PROC-P03 does not authorize another domain package.
