# Purchasing Enterprise Architecture

## 1. Enterprise Gap Analysis

Sebagai _Enterprise Hospitality Procurement Platform_, IVORQ harus melampaui standar fungsional VHP Cloud dan Oracle OPERA Cloud. Analisis kesenjangan (_Gap Analysis_) berikut memetakan kapabilitas yang harus dibangun:

| Feature Area | IVORQ (Target) | VHP Cloud | Oracle OPERA Cloud | Priority |
| :--- | :--- | :--- | :--- | :--- |
| **Vendor Management** | Vendor Portal, Performance Scoring, Dynamic AVL | Standard Master Data | Complex Master Data, Global AVL | **Critical** |
| **Purchase Request (PR)** | Multi-level Dynamic Matrix, Budget Commitments | Static Hierarchy | Dynamic Approval, Budget Check | **Critical** |
| **Purchase Order (PO)** | Auto-conversion from PR, Partial Receiving Integration | Standard PO, GRN link | Complex PO, Centralized Purchasing | **Critical** |
| **Budget Control** | Real-time Blocking & Reservation per line item | Monthly blocking | Comprehensive Fund Control | **High** |
| **Approval Matrix** | Rule-engine based (Nominal, Dept, Property, Company) | Departmental / Role | Highly configurable | **High** |
| **Cost Control** | Real-time variance tracking, Menu engineering link | End-of-month focus | Advanced analytics | **Medium** |
| **Inter-Company** | Cross-property purchasing & central warehousing | Supported (via Central) | Fully Supported | **Medium** |
| **Bidding / Quotation** | Automated Tender / 3-Quotes comparison | Manual Input | e-Procurement Portal | **Future** |
| **AP Integration** | Seamless invoice matching (3-Way Matching) | Integrated AP | Integrated AP & GL | **Critical** |

### Feature Classification
- **Critical Features** (Sprint 09B): Vendor Master, PR Workflow, PO Workflow, 3-Way Matching Preparation, Property Isolation.
- **High Features** (Sprint 09C): Dynamic Approval Matrix Engine, Real-time Budget Reservation.
- **Medium Features** (Sprint 10): Vendor Evaluation & Performance Score.
- **Future Features**: e-Procurement Vendor Bidding Portal.

---

## 2. Vendor Architecture

Arsitektur _Vendor Management_ IVORQ dirancang untuk mendukung operasional multi-properti, di mana sebuah vendor bisa di-_share_ dalam satu _Company_ atau diisolasi per _Property_.

### Core Components
1. **Vendor Master**: Profil utama vendor, klasifikasi (PT, CV, Perorangan), dan terms of payment (TOP).
2. **Vendor Category**: Pengelompokan vendor (F&B, Engineering, Housekeeping Supplies) untuk filter analisa _Cost Control_.
3. **Vendor Contact & Address**: Multi-alamat (Billing, Shipping) dan multi-kontak (Sales, Finance).
4. **Vendor Bank Account**: Rekening tujuan untuk integrasi _Accounts Payable_ (AP).
5. **Vendor Tax Information**: NPWP, PKP status, NIK untuk pelaporan pajak otomatis.
6. **Vendor Contract**: Manajemen masa berlaku harga kontrak tetap (_Fixed Price Contract_).

### Vendor Lifecycle & Compliance
- **Approved Vendor List (AVL)**: Vendor hanya bisa digunakan jika statusnya `Approved`.
- **Preferred Vendor**: Vendor yang direkomendasikan sistem berdasarkan kombinasi harga terendah dan _Performance Score_ tertinggi.
- **Blocked Vendor**: Vendor yang di- _blacklist_ karena performa buruk atau masalah legal (mencegah pembuatan PO baru).
- **Vendor Evaluation & Performance Score**: Modul _rating_ dari tim Receiving (Kualitas Barang, Ketepatan Waktu) dan Purchasing (Harga, Responsiveness) yang akan mengkalkulasi bobot nilai (0-100).

---

## 6. Budget Commitment (Fund Control)

Untuk mencegah _over-budget_, IVORQ Purchasing tidak sekadar membaca sisa _budget_ bulanan, melainkan mengunci anggaran pada titik mula permintaan.

### 4 Pillars of Budget Control
1. **Budget Reservation** (At PR Creation)
   - Ketika PR statusnya `Submitted`, sistem langsung mereservasi (_lock_) nominal estimasi dari sisa _budget_ departemen terkait.
   - Anggaran ini tersembunyi/tidak bisa digunakan PR lain, tapi belum terpotong permanen.
2. **Budget Blocking** (Over-budget Prevention)
   - Jika PR nominal melebihi sisa anggaran (Sisa Budget - Reserved), sistem akan menahan status PR ke `Budget Blocked`.
   - Diperlukan _override approval_ dari Financial Controller / GM.
3. **Budget Release** (At PR/PO Cancellation)
   - Jika PR di- _Reject_ atau PO di- _Cancel_, nominal yang di-_reserve_ akan otomatis dikembalikan ke sisa _budget_ departemen.
4. **Budget Consumption** (At Receiving / AP)
   - Ketika barang diterima (GRN) atau Invois AP divalidasi, nilai _Reserved_ dihapus, diganti dengan _Actual Consumption_ berdasarkan harga faktur riil.

---

## 8. Future Integration

Purchasing adalah hulu dari aliran material dan keuangan. Arsitektur harus di-desain sedari awal untuk menyokong modul hilir:

### Integration Pipeline
**Purchasing** → **Receiving** → **Inventory** → **Cost Control** → **Accounts Payable** → **General Ledger**

### Critical Events to Prepare
1. `PurchaseOrderIssued`
   - *Listener*: Modul **Receiving** (menyiapkan _Expected Receipts_ di _Loading Dock_).
2. `GoodsReceivedNoteCreated`
   - *Listener*: Modul **Inventory** (menambah `StockMovement` & _Moving Average Cost_).
   - *Listener*: Modul **Purchasing** (mengubah status PO menjadi `Partially Received` atau `Fully Received`).
3. `InvoiceMatched` (3-Way Matching: PO + GRN + Invoice)
   - *Listener*: Modul **Accounts Payable** (mencatat Hutang Dagang).
4. `VoucherPosted`
   - *Listener*: Modul **General Ledger** (menjurnal persediaan, hutang, dan PPN).

---

## 9. Enterprise Readiness Checklist

Desain arsitektur di atas memastikan IVORQ memenuhi standar Enterprise Hospitality:

- [x] **Multi Property**: Struktur *Property Isolation* via `property_id` pada seluruh transaksi.
- [x] **Multi Company**: Vendor bisa di- _share_ di level Company melalui relasi global.
- [x] **Inter Company**: Kemampuan _Central Purchasing_ membelikan barang untuk _Sister Company_ (akan dicatat sebagai _Due To / Due From_).
- [x] **Audit Trail**: Seluruh PR dan PO mutlak diobservasi oleh `AuditObserver` (telah disiapkan di Sprint 08B).
- [x] **Budget Control**: Konsep _Reservation_ dan _Consumption_ melindungi arus kas hotel.
- [x] **Approval Matrix**: Hierarki finansial terstruktur mematuhi SOP Hotel (Siklus Approval Otorisasi).
- [x] **AP Ready**: Struktur _Bank Account_ & _Tax_ vendor memastikan tim Finance siap bayar.
- [x] **GL Ready**: Penggunaan `department_id` di level baris memastikan jurnal beban presisi.
- [x] **Cost Control Ready**: Data riwayat harga (_Price History_) terkunci rapi di `purchase_order_lines`.

---

## Architecture Refinement v2

Menjawab kebutuhan *Enterprise Scalability* standar Oracle OPERA dan VHP Cloud, penyempurnaan desain berikut ditambahkan untuk persiapan pengembangan lanjutan:

### Vendor Price History
Tabel `vendor_price_history` mendokumentasikan fluktuasi penawaran harga dari vendor atas _Inventory Items_ dari waktu ke waktu.
- **Tujuan**: Memungkinkan *Historical Purchasing Analysis*, *Price Variance Analysis*, dan *Budget Planning* yang akurat.
- **Business Rules**: Setiap ada perubahan harga kontrak baru, record harga lama tidak ditimpa melainkan ditandai `is_active = false` dengan penutup `valid_to`.
- **BI & Reporting**: Harga historis ini krusial untuk fitur *Forecasting* anggaran makanan dan minuman (F&B) untuk bulan berjalan.

### Multi Currency Support
Pembelian internasional sangat jamak di *Hospitality* (seperti impor *Wine* atau mesin *Engineering*).
- **Strategi Konversi**: Seluruh nominal dalam transaksi (PR, PO) dicatat dalam 2 dimensi: `foreign_currency` dan `base_currency` (contoh: IDR).
- **Tabel**: `exchange_rates` menyimpan *rate* aktif per-hari, sementara `exchange_rate_history` melacak riwayat fluktuasi nilai tukar untuk audit laporan akhir bulan.
- **Rate Locking Strategy**: Nilai tukar mata uang dikunci pada saat PO di-_Issue_ untuk mengontrol eksposur devisa, lalu dibandingkan ulang saat Invois AP divalidasi (Pencatatan laba/rugi kurs di GL).

### Cost Control Readiness
Modul Purchasing harus bertindak sebagai sumber kebenaran data (*Single Source of Truth*) harga beli.
- Arsitektur dipastikan siap mem- *feed* kalkulasi **Food Cost** & **Beverage Cost** secara *real-time*.
- Integrasi ke **Menu Engineering**: Resep (*Recipe Costing*) akan selalu menggunakan perhitungan `average_cost` atau `last_purchase_price` terbaru hasil kalkulasi silang dari data Purchasing & Inventory.
- **Yield Analysis**: Varian harga akan tercatat di PO Line vs GRN Line, memberi gambaran *Theoretical vs Actual Usage* yang nyata.

### AP & GL Readiness Validation (3-Way Matching)
Desain PO Lines kini mengakomodasi pencatatan sinkronisasi dengan *Vendor Invoice*.
- Fitur *3-Way Matching* dijamin integritasnya karena kuantitas terhubung ke `Inventory Receipts` (GRN) dan harga divalidasi langsung ke `purchase_order_lines`.
- **Missing Event Gap Closed**: Momen persetujuan AP Invoice ditambahkan sebagai pemicu pembebasan sisa anggaran (dari *Reserved* menjadi *Consumed*).
