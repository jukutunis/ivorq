# Implementation Order

Project: IVORQ Hotel Operations Platform

Version: 1.0

---

# Build Order

## Stage 1

Foundation

1. Property
2. Department
3. User
4. Authentication
5. Authorization
6. Audit Log
7. Activity Log

---

## Stage 2

Operations

1. Housekeeping
2. Engineering
3. Inventory
4. Purchasing
5. Guest Request

---

## Stage 3

PMS

1. Room Management
2. Guest Profile
3. Reservation
4. Front Office
5. Rate Management

---

## Stage 4

POS

1. Outlet
2. Menu
3. Recipe
4. Order
5. Billing
6. Payment
7. Cash Management

---

## Stage 5

Finance

1. Chart Of Account
2. Journal
3. Budget
4. Fixed Asset

---

## Stage 6

HRIS

1. Employee
2. Attendance
3. Shift
4. Leave
5. Payroll

---

# Rule

Never build downstream modules before upstream dependencies are completed.
