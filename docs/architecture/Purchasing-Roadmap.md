# Purchasing Roadmap

## Sprint 09: Enterprise Purchasing Core

Berdasarkan *Enterprise Gap Analysis* dan *Architecture Blueprints* yang telah disusun pada Sprint 09A, berikut adalah peta jalan teknis (Roadmap) eksekusi untuk Sprint 09B hingga tuntasnya modul Purchasing IVORQ.

### Sprint 09B: Vendor & Master Data Foundation
**Fokus**: Membangun fondasi Vendor Management yang mendukung Property Isolation dan Shared Company Vendors.
- **Milestones**:
  - Pembuatan *Migrations*, *Models*, dan *Factories* untuk `vendors`, `vendor_categories`, `vendor_contacts`.
  - Eksekusi *Architecture Refinement*: Menyiapkan struktur dasar untuk `vendor_quotations` dan `vendor_price_history` meskipun eksekusi logika bisnisnya di-_defer_ ke fase selanjutnya.
  - Menanamkan kerangka Multi-Currency pada level Master Vendor.
  - Feature Tests untuk Vendor Module (mencapai 100% *Coverage* di level Repository dan Service).

### Sprint 09C: Dynamic Approval Engine & PR Workflow
**Fokus**: Menghidupkan *Rule-Engine*, *Approval Snapshots*, dan eksekusi dokumen *Purchase Request* (PR).
- **Milestones**:
  - Pembuatan sistem pendaftar rute persetujuan (Berdasarkan *Amount Threshold*, *Department*, *Role*).
  - Implementasi fungsi **Approval Snapshots** yang merekam jejak tak terhapuskan dari nama *approver* dan otoritasnya pada tabel transaksional khusus.
  - Pembangunan fitur PR (`purchase_requests`, `purchase_request_lines`) dengan kemampuan Multi-Currency (pencatatan nilai tukar).
  - Integrasi PR dengan `BudgetService` untuk melakukan *Budget Reservation* saat `Submitted`.

### Sprint 09D: Purchase Order & Receiving Handshake
**Fokus**: Konversi PR menjadi *Purchase Order* (PO) secara otomatis/semi-otomatis, dan jabat tangan data ke modul Receiving.
- **Milestones**:
  - Pembuatan fitur PO (`purchase_orders`, `purchase_order_lines`).
  - Pembuatan antarmuka konsolidasi PR Line Items menjadi satu PO.
  - Fitur *Rate Locking Strategy* di mana kurs devisa dikunci (*locked*) ke `exchange_rate` saat PO di-_issue_.
  - Sinkronisasi status otomatis (`Issued` → `Partially Received` → `Fully Received`) yang dipicu dari pembuatan *Goods Received Note* (GRN) oleh modul Receiving.

---

## Sprint 10: Accounts Payable (AP) & Cost Control Integration
*Sprint lanjutan paska Purchasing Core selesai.*

### Sprint 10A: The 3-Way Matching
- Membangun integrasi Accounts Payable.
- Membandingkan kuantitas dan harga dari tiga sumber secara sistemik:
  1. **Purchase Order** (Harga komitmen awal)
  2. **Goods Received Note** (Kuantitas fisik diterima)
  3. **Vendor Invoice** (Tagihan legal vendor)
- *Cost Control Readiness*: Melakukan *auto-update* *Moving Average Cost* ke tabel inventori jika tidak ada *variance*.

### Sprint 10B: Vendor Evaluation & Performance
- Mengumpulkan metrik kecepatan kirim (selisih tanggal GRN dan Expected PO date).
- Mengumpulkan metrik kualitas barang (dari catatan penolakan Receiving).
- Pengisian *Vendor Price History* secara presisi hasil *awarding*.

### Sprint 10C: Bidding & Quotation Portal
- Menghidupkan skema *RFQ Workflow* yang sudah dirancang sebelumnya.
- Pemanfaatan entitas `vendor_quotations` untuk melakukan skema *3 Quotes Rule* otomatis bagi *Purchaser* sebelum memenangkan tender (*Awarding*).
- Pembuatan antarmuka *online* bagi *Supplier* eksternal.
