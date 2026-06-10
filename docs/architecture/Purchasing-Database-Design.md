# Purchasing Database Design

## 7. Entity Relationship Diagram (ERD)

Desain skema database berikut dirancang untuk IVORQ Enterprise dengan prinsip: 
1. **Property Isolation** (`property_id` pada setiap tabel).
2. **Auditability** (`created_by`, `updated_by`).
3. **Data Integrity** (Strict Foreign Keys, Soft Deletes).

### Architecture Refinement v2
Telah diperbarui untuk menyertakan `vendor_price_history`, `vendor_quotations`, `exchange_rates`, dan `approval_snapshots` guna memenuhi standar *Oracle OPERA Cloud* dan *Materials Control*.

```mermaid
erDiagram
    PROPERTIES ||--o{ VENDORS : "owns (or shared)"
    VENDORS ||--o{ VENDOR_CONTACTS : has
    VENDORS }|--|| VENDOR_CATEGORIES : belongs_to
    
    VENDORS ||--o{ VENDOR_PRICE_HISTORY : maintains
    INVENTORY_ITEMS ||--o{ VENDOR_PRICE_HISTORY : priced_by
    
    VENDORS ||--o{ VENDOR_QUOTATIONS : submits
    VENDOR_QUOTATIONS ||--o{ VENDOR_QUOTATION_LINES : contains
    
    PROPERTIES ||--o{ EXCHANGE_RATES : manages
    EXCHANGE_RATES ||--o{ EXCHANGE_RATE_HISTORY : tracks

    PROPERTIES ||--o{ PURCHASE_REQUESTS : owns
    DEPARTMENTS ||--o{ PURCHASE_REQUESTS : creates
    PURCHASE_REQUESTS ||--o{ PURCHASE_REQUEST_LINES : contains
    
    PROPERTIES ||--o{ PURCHASE_ORDERS : owns
    VENDORS ||--o{ PURCHASE_ORDERS : receives
    PURCHASE_ORDERS ||--o{ PURCHASE_ORDER_LINES : contains
    PURCHASE_REQUEST_LINES |o--o{ PURCHASE_ORDER_LINES : converts_to
    
    PROPERTIES ||--o{ APPROVAL_WORKFLOWS : owns
    APPROVAL_WORKFLOWS ||--o{ APPROVAL_STEPS : defines
    PURCHASE_REQUESTS ||--o{ APPROVAL_SNAPSHOTS : captures
    PURCHASE_ORDERS ||--o{ APPROVAL_SNAPSHOTS : captures
    
    DEPARTMENTS ||--o{ BUDGET_COMMITMENTS : owns
    PURCHASE_REQUESTS ||--o{ BUDGET_COMMITMENTS : reserves

    EXCHANGE_RATES {
        ulid id PK
        ulid property_id FK
        string currency_code UK "IDR, USD, AUD"
        decimal current_rate
        date effective_date
        ulid updated_by
    }

    VENDORS {
        ulid id PK
        ulid property_id FK "nullable if Company level"
        ulid company_id FK
        ulid vendor_category_id FK
        string vendor_code UK
        string name
        string tax_id
        string default_currency_code FK "e.g., USD"
        boolean is_active
        boolean is_approved
        decimal performance_score
        ulid created_by
    }
    
    VENDOR_PRICE_HISTORY {
        ulid id PK
        ulid property_id FK
        ulid vendor_id FK
        ulid item_id FK
        decimal contracted_price
        string currency_code
        date valid_from
        date valid_to
        boolean is_active
    }
    
    VENDOR_QUOTATIONS {
        ulid id PK
        ulid property_id FK
        ulid vendor_id FK
        string rfq_number
        string quotation_number
        date valid_until
        string status "Draft, Submitted, Won, Lost"
    }

    PURCHASE_REQUESTS {
        ulid id PK
        ulid property_id FK
        ulid department_id FK
        string pr_number UK
        date pr_date
        date expected_date
        string status
        string currency_code
        decimal exchange_rate
        decimal total_estimated_amount_local
        decimal total_estimated_amount_foreign
        ulid created_by
    }

    PURCHASE_ORDERS {
        ulid id PK
        ulid property_id FK
        ulid vendor_id FK
        string po_number UK
        date po_date
        date expected_delivery_date
        string status
        string currency_code
        decimal exchange_rate "Locked at PO issuance"
        decimal subtotal
        decimal tax_amount
        decimal discount_amount
        decimal total_amount
    }

    APPROVAL_SNAPSHOTS {
        ulid id PK
        ulid property_id FK
        string document_type "PR, PO"
        ulid document_id FK
        ulid approver_id FK
        string approver_name "Immutable snapshot"
        string role_name "Immutable snapshot"
        decimal approval_limit "Immutable snapshot"
        integer approval_order
        timestamp approval_timestamp
        string action "Approved, Rejected"
    }

    BUDGET_COMMITMENTS {
        ulid id PK
        ulid property_id FK
        ulid department_id FK
        string document_type "PR"
        ulid document_id FK
        decimal reserved_amount
        string status "Active, Released, Consumed"
    }
```

### Table Specifications

#### 1. Primary Keys (PK) & Data Types
Seluruh PK menggunakan format `ULID` 26-karakter string (`01ARZ3NDEKTSV4RRFFQ69G5FAV`). Mencegah ID enumerasi dan krusial untuk sinkronisasi _offline-to-online_.

#### 2. Foreign Keys (FK) & Constraints
Seluruh FK dilindungi constraint DB ketat. Penghapusan referensi menggunakan mode `RESTRICT` pada data transaksional, dan mode `CASCADE` pada relasi komposit (misal: Hapus `PurchaseOrder` otomatis menghapus `PurchaseOrderLines`).

#### 3. Indexes & Unique Keys
- **Unique**: `(property_id, vendor_code)`, `(property_id, pr_number)`, `(property_id, po_number)`.
- **B-Tree Indexes**: Pada kolom _filtering_ intensif seperti `status`, `pr_date`, `department_id`, dan `vendor_id`.

#### 4. Audit Fields (HasAuditColumns)
Seluruh tabel transaksi dan master menyertakan `created_by`, `updated_by`, `created_at`, `updated_at`, dan `deleted_at`.

#### 5. Property Isolation
Global Scope `addGlobalScope('property')` menjamin isolasi data mutlak antar properti.
