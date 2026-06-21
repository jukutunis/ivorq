# Sprint 15: Cost Control Readiness Audit

## Executive Summary
This audit evaluates the repository's readiness for upcoming Cost Control capability boundaries, focusing on the Inventory Ledger, Cost Ledger, GRNI (Goods Received Not Invoiced), and AVCO (Average Cost) components. The current architecture shows that while the conceptual foundations and business logic for GRNI and AVCO exist within the application services (e.g., `ReceiptService`, `ApPostingEngine`), the dedicated physical schemas for the Inventory Ledger and Cost Ledger have not yet been provisioned. This audit identifies observed implementation gaps and records architecture-constrained readiness findings; it is not an implementation authorization. It does not approve database schemas, service names, migration names, queue jobs, or release sequencing. It does not replace an approved implementation plan. It is a candidate prerequisite for future implementation planning and does not establish production readiness. An architecture-aligned readiness gap is identified between Operations (Receiving/Purchasing) and Finance (AP/GL).

## Dependency Analysis
The following observations are based on the repository state reviewed for this audit and require active-branch revalidation before implementation planning.
- **Purchasing & Receiving:** Observed in the reviewed repository evidence. `ReceivingDocument` and `ReceivingLine` (formerly `InventoryReceiptLine`) exist and appear to form the operational basis for incoming stock.
- **Accounts Payable (AP):** Observed in the reviewed repository evidence. `ApInvoice` and the `ThreeWayMatchingEngine` are referenced by the reviewed service/test evidence for mapping Invoices to POs and Receipts.
- **General Ledger (GL):** Observed in the reviewed repository evidence. The `GrniPostingEngine` appears to provide the documented processing path for generating liability accrual lines for receipts, and the `ApPostingEngine` is referenced for relieving them upon invoice matching, subject to active-branch revalidation.

## Schema Readiness
- **Inventory Ledger:** **NOT READY.** A search of `database/migrations` confirms that an inventory ledger (or equivalent item-movement tracking schema) does not exist.
- **Cost Ledger:** **NOT READY.** No cost ledger tables exist to track the financial valuation history of inventory items over time.
- **GRNI:** **PARTIALLY READY.** GRNI relies on GL operational identities (`GRNI_ACCRUAL`, `GRNI_RECEIPT`). GL operational identities or posting flows do not automatically equal a complete GRNI subledger architecture. A future detailed GRNI aging/reconciliation design must remain aligned with ADR-011 and ADR-016.
- **AVCO:** **PARTIALLY READY.** AVCO is calculated dynamically in memory during receiving (e.g., in `ReceiptService`), but lacks persistent historical ledger capability to track AVCO drift over time.

## Observed Service and Integration Evidence
- **Inventory Ledger Services:** No dedicated Inventory Ledger service implementation was observed in the reviewed repository evidence. Any future implementation must be separately authorized and conform to ADR-008.
- **Cost Ledger Services:** No dedicated Cost Ledger service implementation was observed in the reviewed repository evidence. Any future implementation must be separately authorized and conform to ADR-012.
- **GRNI Services:** Observed in the reviewed repository evidence. `GrniPostingEngine` and `GrniPostingListener` are referenced by the reviewed service/test evidence. AP services appear to provide the documented processing path for `ApInvoiceTypeEnum::GRNI_MATCHED`, subject to active-branch revalidation.
- **AVCO Services:** Observed as an in-memory or service-layer calculation capability in isolation. `ReceiptService::test_avco_calculation` references AVCO math, but this observed calculation test result does not establish that a persistent Cost Ledger exists or that a durable immutable ledger record is created. Future AVCO persistence and correction behavior must remain constrained by ADR-010, ADR-012, ADR-013, and ADR-017. Retroactive correction must not silently rewrite historical valuation outcomes or closed period evidence.

## Integration Readiness
The conceptual integration dependencies between Operations and Finance are inferred from reviewed service and domain references:
1. **Receiving -> GL:** Repository evidence indicates an intended posting flow where receiving events trigger liability accrual via `GrniPostingListener`.
2. **AP -> Purchasing/Receiving:** `ThreeWayMatchingEngine` appears to provide the documented processing path for enforcing matching rules (Invoice, PO, GRN) and identifying exceptions.
3. **AP -> GL:** Repository evidence indicates an intended posting or matching flow where `ApPostingEngine` debits the GRNI control account to clear the liability.

**Conclusion:** Integration behavior, event delivery, idempotency, error handling, and reconciliation outcomes require source-code and test revalidation before implementation planning.

## Risk Register
1. **AVCO Drift & Retroactive Adjustments:** Because AVCO calculation currently relies on an in-memory or service-layer calculation capability, retroactive corrections to historical receipts must be carefully designed so they do not permanently corrupt future AVCO states or silently rewrite closed period evidence, constrained by ADR-013.
2. **GRNI Reconciliation:** Without a dedicated GRNI subledger, matching open GRNI receipts to AP invoices relies purely on GL operational identities. Future detailed design for GRNI reconciliation and aging must adhere to ADR-011 and ADR-016.
3. **Period-Close Integrity:** Inventory and Cost Ledger period-control behavior must be governed by Finance period-close policy under ADR-013 and operational business-date policy under ADR-034. Period close, business-date close, late event handling, controlled reopen, correction, and post-period adjustment are distinct concerns. No future implementation may silently backdate, overwrite, or mutate closed-period or closed-business-date evidence. A future implementation design may define controlled processing mechanisms only after validating ADR-013 and ADR-034 constraints.

## Explicit Cost Control Boundary
Cost Control:
- reads, analyzes, references, and investigates governed data;
- is not an Inventory Ledger;
- is not a Cost Ledger or General Ledger;
- is not a GRNI, AP, tax, or payment posting engine;
- cannot independently alter Inventory movements, Cost Ledger records, Purchase Order commitments, or Finance-period status;
- may surface readiness gaps and controlled recommendation paths only.

Please refer to `docs/architecture/CostControl/Cost-Control-PRD.md` as the user-facing requirements source.

## Readiness Gates and Future Planning Considerations
1. Inventory movement persistence must preserve ADR-008 source-of-truth and audit principles.
2. Cost valuation history must preserve ADR-010 and ADR-012 boundaries.
3. GRNI reconciliation detail must align with ADR-011 and ADR-016.
4. PPV handling must remain governed by ADR-014.
5. Event delivery, retries, idempotency, and failure recovery must align with ADR-017.
6. Any implementation must preserve Finance period-close controls under ADR-013.
7. Any operational day and late-event behavior must preserve Night Audit / Business Date controls under ADR-034.
8. Cost Control remains a read/analyze/investigate workspace and must not become a posting or ledger owner.
9. Future implementation requires a separately approved implementation plan, source-code audit, test plan, migration strategy, rollback plan, and controlled delivery authorization.

## Evidence and Revalidation Disclaimer
Findings are point-in-time repository observations. Service, test, schema, and integration claims must be revalidated against the active branch before any implementation work. No statement in this audit supersedes ADR-008 through ADR-017, ADR-032, ADR-034, or future approved implementation governance. The audit does not grant permission to implement, migrate, stage, commit, or deploy anything.
