# ADR-041: Durable Derived Ledger Delivery via Transactional Outbox

## Status
Approved

## Current Canonical Precedence / CC-G1 Synchronization

ADR-041 remains **Approved** and governs **future deferred** Inventory-to-CostControl derived-ledger delivery. It does not retroactively invalidate the accepted synchronous enrolled valuation paths now present in canonical production source.

Current accepted synchronous composition is classified as:

`TRANSITIONAL_EXISTING_CANONICAL_PATH`

The direct Operations/Inventory or Receiving-to-Finance/CostControl class dependency within that existing path is frozen as:

`EXISTING_CANONICAL_BOUNDARY_EXCEPTION__NO_EXPANSION`

This is a source-proven current-state exception, not permission for new coupling. No new direct dependency may be added and the existing coupling must not be expanded. A later runtime package that touches it must either introduce an approved application/port composition boundary or provide explicit source-backed justification within that package.

For future deferred delivery, Inventory owns the immutable `InventoryTransaction` source evidence and durable outbox record; CostControl consumes the identifier and resolves the source read-only. Inventory must not construct, import, instantiate, or call CostControl implementation classes for that mode. The one-way consumer dependency and source-of-truth rules in this ADR remain governing.

Approval of this ADR does not activate an outbox consumer. No CostControl consumer, queue, worker, retry loop, replay command, scheduler, or deferred delivery runtime is activated by CC-G1. Current synchronous production remains accepted while future expansion remains constrained by this ADR.

## Context
Receipt, Issue, Transfer, dan Adjustment sudah menggunakan controlled Inventory posting boundary.
Inventory Ledger adalah source transaction layer.
Cost Ledger append boundary sudah memiliki provenance validation serta idempotency protection.
Belum ada durable operational delivery path dari Inventory Ledger ke Cost Ledger.
`afterCommit` saja tidak cukup jika Cost Ledger consumer gagal setelah Inventory commit.
Repository belum memiliki reusable transactional outbox atau Cost Ledger reconciliation convention.

This ADR complements ADR-017 for the specific derived Cost Ledger flow. It does not authorize automatic retry for Inventory posting, and it defers any automatic outbox delivery retry policy beyond Slice 0.

## Decision
1. **Source of Truth**: Inventory Ledger tetap menjadi source of truth untuk Cost Ledger.
2. **Durable Outbox Record**: Controlled Inventory posting yang berhasil membuat immutable `InventoryTransaction` wajib menulis durable outbox record dalam transaction database yang sama.
3. **Minimum Payload**: Payload outbox minimum hanya:
   ```text
   transactionId: string
   ```
4. **No Duplicate Fields**: `property_id`, quantity, cost, actor, source document, reference, dan data lain harus dimuat kembali dari immutable `InventoryTransaction`; tidak boleh diduplikasi di payload outbox tanpa alasan yang dibuktikan.
5. **afterCommit Delivery Attempt**: `afterCommit` hanya memicu delivery attempt setelah outer transaction sukses commit.
6. **afterCommit is not a Durability Guarantee**: `afterCommit` bukan mekanisme durability dan bukan jaminan final delivery.
7. **Consumer Flow**: CostControl consumer memuat source `InventoryTransaction` menggunakan `transactionId`, kemudian menjalankan:
   ```text
   CostLedgerPostingPlanner
       → CostLedgerAppendService
   ```
8. **Idempotency Protection**: Cost Ledger append idempotency tetap menjadi perlindungan terhadap at-least-once delivery.
9. **Consumer Failure Policy**: Kegagalan Cost Ledger consumer setelah Inventory transaction commit:
   * tidak membatalkan Inventory posting;
   * tidak boleh hanya "log lalu dilupakan";
   * harus durable, observable, dan recoverable;
   * tidak memakai automatic retry pada Slice 0;
   * akan menggunakan controlled/manual replay pada implementation slice terpisah.
10. **Loose Coupling**: Inventory tidak boleh mengimpor, menginstansiasi, atau memanggil class dari CostControl.
11. **One-Way Dependency**: Dependency lintas modul tetap satu arah:
    ```text
    Finance/CostControl → Operations/Inventory
    ```
12. **Out of Scope**: ADR ini tidak mengatur General Ledger posting, UI, reports, AVCO redesign, Financial Period close, queue platform selection, atau implementation detail replay command.

## Consequences
### Positive
* Menjamin konsistensi akhir (eventual consistency) antara Inventory Ledger dan Cost Ledger tanpa membebani performa atau merusak integritas modul Inventory.
* Mencegah duplikasi data cost, quantity, dan metadata di tingkat outbox payload.
* Melindungi database dari penulisan data ganda berkat sifat idempotent pada append service.
* Mengisolasi kegagalan operasional Cost Control sehingga tidak merusak atau membatalkan mutasi stok utama.

### Negative / Trade-offs
* Membutuhkan mekanisme outbox publisher dan consumer, yang meningkatkan kompleksitas infrastruktur.
* Kegagalan pengiriman memerlukan manajemen observabilitas dan recovery manual terpisah.

## Alternatives Considered
1. **Direct synchronous call**: Ditolak karena mengikat erat Finance ke Operations dan melanggar batas kemandirian modul.
2. **afterCommit listener tanpa durable outbox**: Ditolak karena kehilangan data jika listener/worker mati pasca commit.
3. **Listener terpisah untuk setiap event dokumen**: Ditolak karena mempersulit pemeliharaan dan validasi basis cost terpusat.
4. **Automatic retry di Inventory posting transaction**: Ditolak karena dapat mengunci database stok fisik terlalu lama dan memicu deadlock.
5. **Membiarkan Cost Ledger tanpa operational caller**: Ditolak karena membuat ledger biaya tidak terisi secara real-time.
6. **Memasukkan propertyId/detail transaksi ke payload outbox**: Ditolak karena melanggar prinsip keaslian data (single source of truth) dan memicu duplikasi data mutable.

## Deferred Implementation
Implementasi berikut ditangguhkan dan belum diotorisasi oleh ADR ini:
* outbox migration
* outbox model
* outbox repository
* outbox publisher
* generic InventoryTransactionPosted event
* CostControl listener
* queue worker
* manual replay command
* Cost Ledger integration test
* General Ledger integration

## References
* ADR-001 — Multi-Tenant Hierarchy (Active)
* ADR-004 — Finance Module Boundary (Active)
* ADR-017 — Event-Driven Accounting and Queue Resiliency Strategy (Active) (Note: This ADR complements ADR-017 by deferring automatic delivery retries for Cost Ledger Slice 0).
