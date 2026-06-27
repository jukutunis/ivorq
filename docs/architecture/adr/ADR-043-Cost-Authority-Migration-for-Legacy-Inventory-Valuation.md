# ADR-043: Cost Authority Migration for Legacy Inventory Valuation

## Status
Accepted

## Context
Sistem saat ini mengelola data fisik persediaan (`InventoryStock`) dan nilai legacy weighted average cost (WAC) pada properti aktif menggunakan tabel `inventory_items` dengan kolom `weighted_average_cost`. Modul `CostControl` yang baru menggunakan `CostAvcoState` untuk melacak saldo fisik dan carrying value secara persisten. Namun, `CostAvcoStateRepository::bootstrapAndLock()` memulai scope baru secara kosong (quantity = 0, carrying value = 0, cost = null) tanpa membaca record saldo operasional. Hal ini menghalangi adopsi langsung saldo fisik live stock secara aman. Selain itu, enum `OpeningBalance` belum didukung oleh `InventoryPostingControlCoordinator` dan engine CostControl. Di sisi lain, terdapat pembaca operasional sinkron (`IssueService`, `AdjustmentService`, `TransferService`, `StockMovementService` fallback) yang membutuhkan cost secara real-time, serta modul `Finance` (`VariancePostingEngine`) yang mengambil data `total_cost` dari `InventoryTransaction` untuk posting General Ledger (GL). Tanpa enrollment transisi, aktivasi CostControl akan merusak konsistensi saldo awal, memicu error urutan sequence, dan menimbulkan risiko dual-authority.

## Decision
1. **Single Source of Authority per Scope**: Setiap scope penilaian (`property_id` + `location_id` + `item_id` + `valuation_scope`) hanya boleh memiliki tepat satu sumber otoritas AVCO yang aktif pada satu waktu.
2. **Explicit Enrollment Gate**: Sebuah scope hanya dapat beralih ke CostControl melalui record migrasi transisi yang eksplisit, disetujui, dan bersifat immutable (tidak dapat diubah).
3. **Reconciled Opening Evidence**: Record migrasi wajib mencakup quantity awal hasil rekonsiliasi, approved carrying value, mata uang, business date, context period keuangan, reference document, pengesah (approver ID), dan timestamp bukti.
4. **No Fake Historical Transaction**: Pencatatan saldo awal dilarang memalsukan riwayat transaksi ledger utama (InventoryTransaction) atau menggunakan enum `OpeningBalance` secara ad-hoc tanpa koordinasi posting yang matang.
5. **Sequence Baseline**: Kontrol sequence valuasi pertama (`last_valuation_sequence = 1`) ditentukan secara konseptual oleh transition record sebagai baseline kelanjutan sequence berikutnya.
6. **Finance and Period Boundary**: Scope yang sama dilarang memiliki otoritas valuasi ganda (mixed authority) dalam satu financial period aktif yang sama.
7. **Operational Read Consistency (Recommendation: Rule 1)**: Mengingat pembaca operasional memerlukan data cost secara realtime sewaktu posting, delayed asynchronous WAC projection saja tidak cukup. Diputuskan untuk menetapkan **Rule 1**: Item-level WAC projection disinkronkan secara konsisten (synchronous consistency) sebelum transaksi dependen berikutnya dijalankan.

## Authority and Enrollment Group
1. **A Property + Item enrollment group has one AVCO authority at a time.**
2. **Before enrollment, legacy valuation remains authoritative for every active location and valuation scope in that group.**
3. **After enrollment, CostControl is the sole AVCO authority for every included location and valuation scope in that group.**
4. **Mixed legacy and CostControl authority across locations for the same Property + Item enrollment group is prohibited.**
5. **CostControl state remains calculated per: property_id + location_id + item_id + valuation_scope.**

Cutover otoritas harus dilakukan secara terkoordinasi sebagai satu kesatuan kelompok Property + Item karena representasi legacy WAC tidak dapat membedakan lokasi penyimpanan.

## Immutable Opening Evidence
Sebelum enrollment dijalankan untuk suatu kelompok Property + Item, dokumen bukti transisi awal yang bersifat immutable wajib dibuat secara konseptual mengandung informasi berikut:
* reconciled opening quantity
* approved opening carrying value
* currency
* business date
* financial-period context
* source reference
* approver identity
* evidence timestamp
* authority state

Pencatatan ini harus mengikuti aturan berikut:
* **The transition record is not a fabricated historical InventoryTransaction.**
* **The existing OpeningBalance enum must not be used as an ad-hoc migration workaround.**

Proses enrollment wajib gagal tertutup (fail-closed) jika pembuktian opening evidence, quantity, carrying value, currency, period, atau authority state tidak dapat divalidasi dengan benar. Tidak ada pencatatan backfill otomatis, silent repair, atau pemalsuan asal-usul data (fabricated provenance).

## Sequence Baseline
* **The immutable transition record establishes a sequence baseline N atomically aligned with the Inventory valuation-sequence allocator.**
* **The first eligible controlled transaction after enrollment must use N + 1.**
* **The stored representation of N is intentionally deferred to a later, narrow implementation slice and must not fabricate historical transactions.**

## Operational Read Consistency
* **inventory_items.weighted_average_cost is not a safe per-location CostControl projection because it is property-scoped and does not represent the full CostControl valuation identity.**
* **An async-only delayed projection is not acceptable while Issue, Adjustment, Transfer, and StockMovement use cost synchronously.**
* **The approved initial direction is CostControl valuation read-boundary redirection for enrolled groups.**
* **A separate future architecture slice may evaluate a dedicated, scope-aware projection or read model.**
* **The existing inventory_items.weighted_average_cost field must not be treated as a safe per-location CostControl projection.**

## Finance and Cutover Gates
* **Initial activation is limited to one pilot Property.**
* **Enrollment occurs only for one or more complete Property + Item groups.**
* **Activation occurs at a financial-period boundary.**
* **No mid-period cutover.**
* **No partial-location enrollment for an item.**
* **No enrollment while relevant Inventory documents are in flight.**
* **No enrollment while controlled messages for that group are pending or failed.**
* **Before group activation, the applicable General Ledger path must be proven not to treat independently legacy-stamped InventoryTransaction cost as authoritative for the enrolled group.**

## Rejected Alternatives
* **direct bootstrap dari InventoryStock and legacy item WAC**: Ditolak karena `CostAvcoStateRepository` menginisialisasi saldo kosong secara default dan tidak membaca saldo operasional stock secara langsung, sehingga memicu risiko carry value tidak cocok.
* **treating OpeningBalance as an already-complete migration path**: Ditolak karena tipe transaksi ini belum diimplementasikan pada coordinator posting persediaan utama, sehingga akan memicu kegagalan sistem.
* **permanent dual legacy and CostControl AVCO authorities**: Ditolak karena menyebabkan inkonsistensi pencatatan cost ganda pada item yang sama secara tidak sinkron.
* **removing legacy receipt WAC writes before read consistency exists**: Ditolak karena pembaca operasional dependen memerlukan cost secara langsung saat posting transaksi dijalankan.
* **delayed asynchronous WAC projection as the sole solution**: Ditolak karena jeda waktu pemrosesan antrean menyebabkan operational readers mengambil cost stale yang kemudian merusak posting akuntansi di `VariancePostingEngine`.
* **partial-location enrollment for one Property + Item group**: Ditolak karena field legacy `weighted_average_cost` dibagikan di tingkat properti; pendaftaran terpisah per lokasi akan memicu konflik update.
* **CostControl processing only Issue/Adjustment while receipt remains legacy-authoritative for the same group**: Ditolak karena pemisahan penerimaan (yang mengubah cost) dari sequence pengeluaran melanggar hukum perhitungan carrying value berkelanjutan.

## Consequences
### Positive
* **protects strict sequence and AVCO integrity**: Menjamin kesinambungan sequence ledger akuntansi biaya per scope.
* **prevents mixed financial authority**: Mencegah konflik pencatatan valuasi ganda yang berbeda antara legacy dan CostControl.
* **enables auditable per-group cutover**: Memungkinkan audit transisi saldo awal per item-properti secara tuntas.
* **protects future Cost Ledger and GL integrity**: Melindungi keabsahan nilai jurnal akuntansi yang dikirim ke GL.

### Constraints / Costs
* **existing live stock cannot be activated immediately**: Stok yang sudah berjalan harus direkonsiliasi terlebih dahulu sebelum terdaftar.
* **transition record and enrollment architecture are required**: Membutuhkan model tabel data transisi dan program verifikasi gate enrollment.
* **read-boundary work is required before runtime activation**: Pengubahan pembaca operasional (redirection) wajib diselesaikan terlebih dahulu.
* **ReceiptService and Receiving integration must cut over together for an enrolled group**: Transisi penerimaan stok utama dan penerimaan barang harus dialihkan ke controlled posting secara bersamaan.
* **PostgreSQL proof is mandatory before production activation**: Menuntut bukti pengujian transaksi concurrent lock database PostgreSQL.

## Controlled Future Implementation Sequence
* **Stage 1 — transition record / enrollment architecture, finance eligibility gate, and PostgreSQL lock proof.**
  * *Kenapa tidak bisa dilewati*: Untuk menjamin skema transisi saldo awal aman secara database transaksi sebelum merancang logika aplikasi di modul operasional.
* **Stage 2 — CostControl valuation read boundary for scope-aware operational cost reads.**
  * *Kenapa tidak bisa dilewati*: Agar pembaca operasional memiliki data cost yang akurat per lokasi dan tidak membaca cost stale setelah enrollment diaktifkan.
* **Stage 3 — explicit non-transfer CostControl processing application-service boundary with focused proof.**
  * *Kenapa tidak bisa dilewati*: Untuk membuktikan planner dan engine CostControl dapat memproses sequence ledger biaya tanpa anomali sebelum menerima data transaksi nyata.
* **Stage 4 — ReceiptService and Receiving controlled-posting integration with enrollment guard.**
  * *Kenapa tidak bisa dilewati*: Penerimaan stok merupakan kejadian utama pengubah harga; integrasi ini mutlak diperlukan sebelum beralih ke CostControl.
* **Stage 5 — pilot activation for complete Property + Item groups at a financial-period boundary, with legacy AVCO writers disabled for the enrolled group.**
  * *Kenapa tidak bisa dilewati*: Merupakan pintu cutover utama tempat dimulainya otoritas CostControl secara eksklusif.
* **Stage 6 — post-cutover reconciliation and finance evidence review.**
  * *Kenapa tidak bisa dilewati*: Tahapan penjaminan kualitas akuntansi persediaan akhir untuk memastikan tidak ada selisih angka posting General Ledger.

## Non-Goals
* global historical transaction backfill
* all-property rollout
* queue workers
* schedulers
* automatic retries
* General Ledger implementation
* P&L implementation
* broad Inventory refactor
* changes to paired Transfer Option B
* permanent parallel item-WAC authority
