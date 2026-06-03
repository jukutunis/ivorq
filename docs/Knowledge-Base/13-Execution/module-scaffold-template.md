# Module Scaffold Template

Project: IVORQ Hotel Operations Platform

Version: 1.0

---

# Purpose

Template standar untuk seluruh modul IVORQ.

---

# Module Structure

Modules/{ModuleName}

├── Actions
├── Contracts
├── Events
├── Exceptions
├── Http
│   ├── Controllers
│   ├── Requests
│   └── Resources
├── Jobs
├── Listeners
├── Models
├── Policies
├── Repositories
├── Services
├── Tests
├── database
│   ├── migrations
│   └── seeders
├── routes
├── config
└── README.md

---

# Development Order

1. Migration
2. Model
3. Repository
4. Service
5. Request
6. Policy
7. Controller
8. Resource
9. Tests
10. Documentation

---

# Required Files

Example:

Room.php

RoomRepository.php

RoomService.php

RoomController.php

StoreRoomRequest.php

UpdateRoomRequest.php

RoomPolicy.php

RoomResource.php

RoomTest.php

---

# Definition Of Done

✓ Migration

✓ Model

✓ Repository

✓ Service

✓ Controller

✓ Tests

✓ Documentation

✓ Security Review

✓ Architecture Review

---

# AI Rule

Never generate controller-first code.

Always generate:

Migration
↓
Model
↓
Repository
↓
Service
↓
Controller
↓
Tests
