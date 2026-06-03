# Laravel Folder Structure v1

Project: IVORQ Hotel Operations Platform

Version: 1.0

Status: Approved

Owner: CTO

---

# Philosophy

IVORQ menggunakan:

* Modular Monolith
* Domain Driven Design (DDD)
* Service Layer Pattern
* Repository Pattern
* Event Driven Architecture

Tujuan:

* Scalability
* Maintainability
* Team Collaboration
* AI Friendly Development

---

# Root Structure

```text
app/
Modules/
Shared/
Infrastructure/
routes/
database/
resources/
tests/
docs/
```

---

# Final Project Structure

```text
IVORQ/

├── app/
├── Modules/
├── Shared/
├── Infrastructure/
├── routes/
├── database/
├── resources/
├── tests/
├── docs/
└── storage/
```

---

# App Layer

Digunakan hanya untuk Laravel Core.

```text
app/

├── Console/
├── Exceptions/
├── Http/
├── Providers/
└── Support/
```

Business logic tidak boleh berada di sini.

---

# Modules Layer

Seluruh business modules berada di sini.

```text
Modules/

├── Foundation/
├── Housekeeping/
├── Engineering/
├── Inventory/
├── Purchasing/
├── GuestRequest/
├── PMS/
├── POS/
├── Finance/
└── HRIS/
```

---

# Foundation Module

```text
Modules/Foundation/

├── Authentication/
├── Authorization/
├── Property/
├── Department/
├── User/
├── Audit/
└── Activity/
```

---

# Standard Module Structure

Contoh:

```text
Modules/Housekeeping/

├── Actions/
├── Contracts/
├── Events/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
├── Jobs/
├── Listeners/
├── Models/
├── Policies/
├── Repositories/
├── Services/
├── Tests/
├── database/
│   ├── migrations/
│   └── seeders/
├── routes/
├── config/
└── README.md
```

---

# Shared Layer

Komponen yang digunakan lintas modul.

```text
Shared/

├── DTOs/
├── Enums/
├── Traits/
├── Helpers/
├── Notifications/
├── Exceptions/
├── Services/
└── Support/
```

---

# Infrastructure Layer

Komponen teknis.

```text
Infrastructure/

├── Queue/
├── Cache/
├── Storage/
├── Mail/
├── Integrations/
├── ExternalApis/
└── Monitoring/
```

---

# Database Structure

```text
database/

├── migrations/
├── seeders/
├── factories/
└── scripts/
```

---

# Routes Structure

```text
routes/

├── api.php
├── web.php
└── console.php
```

Module Routes:

```text
Modules/Housekeeping/routes/api.php
Modules/Inventory/routes/api.php
Modules/PMS/routes/api.php
```

---

# Frontend Structure

```text
resources/js/

├── Pages/
├── Components/
├── Layouts/
├── Hooks/
├── Services/
├── Stores/
├── Types/
└── Utils/
```

---

# Pages Structure

```text
Pages/

├── Foundation/
├── Housekeeping/
├── Engineering/
├── Inventory/
├── Purchasing/
├── GuestRequest/
├── PMS/
├── POS/
├── Finance/
└── HRIS/
```

---

# Tests Structure

```text
tests/

├── Feature/
├── Unit/
├── Integration/
└── Performance/
```

---

# Documentation Structure

```text
docs/

├── Architecture/
├── Database/
├── PRD/
├── API/
├── Governance/
├── AI/
└── Sprints/
```

---

# Naming Rules

Modules:

PascalCase

Examples:

* Housekeeping
* GuestRequest
* Inventory

Classes:

PascalCase

Examples:

* RoomService
* WorkOrderRepository
* InventoryItem

Tables:

snake_case plural

Examples:

* rooms
* work_orders
* inventory_items

Columns:

snake_case

Examples:

* room_number
* property_id
* assigned_to

---

# Dependency Rules

Allowed:

Foundation
↓
Operations
↓
PMS
↓
POS
↓
Finance
↓
HRIS

Forbidden:

POS → Foundation

Finance → Inventory (direct shortcut)

Gunakan Events.

---

# Module Communication

Gunakan:

* Events
* Listeners
* Jobs

Hindari direct coupling.

---

# AI Rules

Saat membuat fitur baru:

1. Baca PRD
2. Baca Module Scaffold Template
3. Buat Migration
4. Buat Model
5. Buat Repository
6. Buat Service
7. Buat Controller
8. Buat Tests
9. Update Documentation

---

# Definition of Done

✓ Folder Structure Locked

✓ Naming Rules Locked

✓ Dependency Rules Locked

✓ Architecture Approved

---

# Final Instruction

No code may be written before complying with this structure.

This document is the official source of truth for IVORQ project organization.
