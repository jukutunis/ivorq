# Database Finalization Strategy

Project: IVORQ Hotel Operations Platform

Version: 1.0

Status: Approved

Owner: CTO

---

# Objective

Mengunci struktur database inti sebelum Sprint 01 Development dimulai.

Tujuan:

* Mencegah refactor besar
* Mencegah duplicate entities
* Mencegah circular dependency
* Menjaga konsistensi antar domain
* Menjadi source of truth database

---

# Global Database Rules

All tables must contain:

* id (ULID)
* created_at
* updated_at

Business Tables must contain:

* property_id
* created_by
* updated_by

Optional:

* deleted_at

---

# Naming Convention

## Tables

Format:

snake_case plural

Examples:

* users
* properties
* departments
* cleaning_tasks
* work_orders
* purchase_orders

## Columns

Format:

snake_case

Examples:

* room_number
* property_id
* assigned_to
* purchase_order_number

---

# Primary Key Standard

Use:

ULID

Example:

id CHAR(26)

Never use:

AUTO_INCREMENT

---

# Foreign Key Standard

Examples:

* property_id
* department_id
* vendor_id
* room_id
* warehouse_id

---

# Audit Standard

All business tables must support:

* created_by
* updated_by

Critical workflow tables also support:

* approved_by
* approved_at
* rejected_by
* rejected_at

---

# Foundation Domain Tables

* companies
* properties
* property_settings
* departments
* positions
* users
* roles
* permissions
* user_sessions
* user_devices
* audit_logs
* activity_logs

---

# Operations Domain Tables

## Housekeeping

* housekeeping_zones
* rooms
* room_status_histories
* cleaning_tasks
* task_assignments
* cleaning_checklists
* checklist_items
* room_inspections
* inspection_photos

## Engineering

* work_orders
* work_order_status_histories
* technician_assignments
* preventive_maintenances
* preventive_maintenance_tasks
* asset_requests
* engineering_checklists

## Guest Request

* guest_requests
* guest_request_categories
* guest_request_assignments
* guest_request_status_histories
* guest_request_escalations
* guest_request_sla_logs
* guest_request_comments

## Inventory

* inventory_items
* inventory_categories
* inventory_units
* warehouses
* warehouse_assignments
* stock_movements
* stock_counts
* stock_count_items
* stock_adjustments

## Purchasing

* vendors
* vendor_categories
* purchase_requests
* purchase_request_items
* purchase_request_approvals
* rfqs
* rfq_vendors
* rfq_quotations
* purchase_orders
* purchase_order_items
* goods_receipts
* goods_receipt_items

---

# PMS Domain Tables

* guests
* guest_profiles
* reservations
* reservation_rooms
* room_types
* room_rates
* folios
* room_blocks

---

# POS Domain Tables

* outlets
* menus
* menu_categories
* recipes
* recipe_items
* orders
* order_items
* bills
* payments
* cash_shifts

---

# Finance Domain Tables

* chart_of_accounts
* journal_entries
* journal_items
* budgets
* budget_items
* fixed_assets
* asset_depreciations
* vendor_payments

---

# HRIS Domain Tables

* employees
* employee_positions
* attendances
* shifts
* rosters
* leaves
* overtime_requests
* payrolls
* payroll_items
* performance_reviews
* trainings

---

# Index Strategy

Mandatory Indexes:

* property_id
* status
* created_at
* updated_at

Workflow Tables:

* assigned_to
* priority
* due_date

Inventory Tables:

* item_id
* warehouse_id
* movement_date

Reservation Tables:

* guest_id
* reservation_number
* arrival_date
* departure_date

---

# Soft Delete Strategy

Use Soft Delete:

* users
* vendors
* items
* rooms
* departments
* properties
* employees

Do Not Use Soft Delete:

* audit_logs
* activity_logs
* status_histories
* stock_movements
* journal_entries

---

# Multi Property Enforcement

Every business query must include:

WHERE property_id = current_property

Mandatory.

No exceptions.

---

# Reporting Optimization

Prepare indexes for:

* Dashboard Queries
* KPI Queries
* Monthly Reports
* Audit Reports
* Inventory Reports
* Financial Reports

---

# Future Ready Domains

Reserved:

* CRM
* Booking Engine
* Channel Manager
* AI Assistant
* Business Intelligence

---

# Migration Approval Rule

Before creating migration:

Check:

1. database-finalization.md
2. database.md
3. table catalog
4. ERD

Only then create migration.

---

# Final Instruction

This document is the official source of truth for IVORQ database design.

No table may be created outside this specification without CTO approval.
